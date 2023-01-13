<?php


namespace EasySwoole\Pool;


use EasySwoole\Pool\Exception\Exception;
use EasySwoole\Pool\Exception\PoolEmpty;
use EasySwoole\Utility\Random;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Table;
use Swoole\Timer;

abstract class AbstractPool
{
    private $createdNum = 0;
    /** @var Channel */
    private $poolChannel;
    //以key保存连接池的连接对象hash值，回收时，比较hash值是否存在，及状态值是否为true(未使用)，值为false（正在使用）
    private $objHash = [];
    /** @var Config */
    private $conf;
    //周期性检测定时器ID
    private $intervalCheckTimerId;
    //回收连接池连接定时器ID，保持最小数量
    private $loadAverageTimerId;
    //销毁pool
    private $destroy = false;
    //使用协程cid保存当前协程上下文连接，协程退出时，回收
    private $context = [];
    // getObj() 记录取出等待时间
    private $loadWaitTimes = 0;
    // 每次getObj 记录该连接池取出的次数
    private $loadUseTimes = 0;
    //当前Worker进程连接池的hash值
    private $poolHash;
    //正在使用的连接对象，hash为键，对象为值
    private $inUseObject = [];
    //连接池全局状态表，以PoolHash值保存
    private $statusTable;


    /*
     * 如果成功创建了,请返回对应的obj
     */
    abstract protected function createObject();

    //Connection()->getPool(); ->  new MysqlPool() -> class MysqlPool extends AbstractPool
    public function __construct(Config $conf)
    {
        if ($conf->getMinObjectNum() >= $conf->getMaxObjectNum()) {
            $class = static::class;
            throw new Exception("pool max num is small than min num for {$class} error");
        }
        $this->conf = $conf;
        //指定行数1024，同 $redis->hset('key', 'field', 'value');
        $this->statusTable = new Table(1024);
        $this->statusTable->column('created', Table::TYPE_INT, 10);
        $this->statusTable->column('pid', Table::TYPE_INT, 10);
        $this->statusTable->column('inuse', Table::TYPE_INT, 10);
        $this->statusTable->column('loadWaitTimes', Table::TYPE_FLOAT, 10);
        $this->statusTable->column('loadUseTimes', Table::TYPE_INT, 10);
        $this->statusTable->column('lastAliveTime', Table::TYPE_INT, 10);
        $this->statusTable->create();
        $this->poolHash = substr(md5(spl_object_hash($this) . getmypid()), 8, 16);
    }

    function getUsedObjects(): array
    {
        return $this->inUseObject;
    }

    /*
     * 回收一个对象
     */
    public function recycleObj($obj): bool
    {
        /*
         * 当标记为销毁后，直接进行对象销毁
         */
        if ($this->destroy) {
            $this->unsetObj($obj);
            return true;
        }
        /*
        * 懒惰模式，可以提前创建 pool对象，因此调用钱执行初始化检测
        */
        $this->init();
        /*
         * 仅仅允许归属于本pool且不在pool内的对象进行回收
         */
        if ($this->isPoolObject($obj) && (!$this->isInPool($obj))) {
            /*
             * 主动回收可能存在的上下文
            */
            $cid = Coroutine::getCid();
            if (isset($this->context[$cid]) && $this->context[$cid]->__objHash === $obj->__objHash) {
                unset($this->context[$cid]);
            }
            $hash = $obj->__objHash;
            //标记为在pool内
            $this->objHash[$hash] = true;
            unset($this->inUseObject[$hash]);
            if ($obj instanceof ObjectInterface) {
                try {
                    $obj->objectRestore();
                } catch (\Throwable $throwable) {
                    //重新标记为非在pool状态,允许进行unset
                    $this->objHash[$hash] = false;
                    $this->unsetObj($obj);
                    throw $throwable;
                }
            }
            $this->poolChannel->push($obj);
            return true;
        } else {
            return false;
        }
    }

    /*
     * tryTimes为出现异常尝试次数
     */
    public function getObj(float $timeout = null, int $tryTimes = 3)
    {
        /*
        * 1, 初始化连接池channel，懒惰模式，可以提前创建 pool对象，因此调用前执行初始化检测
        */
        $this->init();
        /*
         * 当标记为销毁后，禁止取出对象
         */
        if ($this->destroy) {
            return null;
        }
        if ($timeout === null) {
            $timeout = $this->getConfig()->getGetObjectTimeout();
        }
        $object = null;
        //2，连接池的连接为空，创建新的连接对象，连接池数不够时新建(但不超过最大数)
        if ($this->poolChannel->isEmpty()) {
            try {
                $this->initObject();
            } catch (\Throwable $throwable) {
                if ($tryTimes <= 0) {
                    throw $throwable;
                } else {
                    $tryTimes--;
                    return $this->getObj($timeout, $tryTimes);
                }
            }
        }
        $start = microtime(true);
        //3，获取连接
        $object = $this->poolChannel->pop($timeout);
        $take = microtime(true) - $start;
        // getObj 记录取出等待时间 5s周期内
        $this->loadWaitTimes += $take;
        $this->statusTable->set($this->poolHash(), [
            'loadWaitTimes' => $this->loadWaitTimes
        ]);
        if (is_object($object)) {
            $hash = $object->__objHash;
            //标记该对象已经被使用，不在pool中
            $this->objHash[$hash] = false;
            $this->inUseObject[$hash] = $object;
            $object->__lastUseTime = time();
            if ($object instanceof ObjectInterface) {
                try {
                    //使用前检查是否连接为真可用，实例化真连接对象。重试{tryTimes}次
                    if ($object->beforeUse() === false) {
                        $this->unsetObj($object);
                        if ($tryTimes <= 0) {
                            return null;
                        } else {
                            $tryTimes--;
                            return $this->getObj($timeout, $tryTimes);
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->unsetObj($object);
                    if ($tryTimes <= 0) {
                        throw $throwable;
                    } else {
                        $tryTimes--;
                        return $this->getObj($timeout, $tryTimes);
                    }
                }
            }
            // 每次getObj 记录该连接池取出的次数 5s周期内
            $this->loadUseTimes++;
            $this->statusTable->incr($this->poolHash(), 'loadUseTimes');
            return $object;
        } else {
            return null;
        }
    }

    /*
     * 彻底释放一个对象
     */
    public function unsetObj($obj): bool
    {
        if (!$this->isInPool($obj)) {
            /*
             * 主动回收可能存在的上下文
             */
            $cid = Coroutine::getCid();
            //当obj等于当前协程defer的obj时,则清除
            if (isset($this->context[$cid]) && $this->context[$cid]->__objHash === $obj->__objHash) {
                unset($this->context[$cid]);
            }
            $hash = $obj->__objHash;
            unset($this->objHash[$hash]);
            unset($this->inUseObject[$hash]);
            if ($obj instanceof ObjectInterface) {
                try {
                    $obj->gc();
                } catch (\Throwable $throwable) {
                    throw $throwable;
                } finally {
                    $this->createdNum--;
                    $this->statusTable->decr($this->poolHash(), 'created');
                }
            } else {
                $this->createdNum--;
                $this->statusTable->decr($this->poolHash(), 'created');
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * 保持连接可用性，所有连接心跳检测；超时未使用并且当前大于最小连接数，连接移除
     * 超过$idleTime未出队使用的，将会被回收。
     */
    public function idleCheck(int $idleTime)
    {
        /*
        * 懒惰模式，可以提前创建 pool对象，因此调用钱执行初始化检测
        */
        $this->init();
        $size = $this->poolChannel->length();
        while (!$this->poolChannel->isEmpty() && $size >= 0) {
            $size--;
            $item = $this->poolChannel->pop(0.01);
            if (!$item) {
                continue;
            }
            //回收超时没有使用的链接(默认15s)
            if (time() - $item->__lastUseTime > $idleTime) {
                $num = $this->getConfig()->getMinObjectNum();
                if ($this->createdNum > $num) {
                    echo date('[Y-m-d H:i:s]') . 'idleCheck:' . $this->createdNum . PHP_EOL;
                    //标记为不在队列内，允许进行gc回收
                    $hash = $item->__objHash;
                    $this->objHash[$hash] = false;
                    $this->unsetObj($item);
                    continue;
                }
            }
            //执行itemIntervalCheck检查，select 1 心跳检测
            if (!$this->itemIntervalCheck($item)) {
                //标记为不在队列内，允许进行gc回收
                $hash = $item->__objHash;
                $this->objHash[$hash] = false;
                $this->unsetObj($item);
                echo date('[Y-m-d H:i:s]') . 'itemIntervalCheck:' . $this->createdNum . PHP_EOL;
            } else {
                //如果itemIntervalCheck 为真，则重新标记为已经使用过，可以用。
                $item->__lastUseTime = time();
                $this->poolChannel->push($item);
            }
        }
    }

    /*
     * 检查连接池连接是否可用，保持最小连接数，每10秒执行一次
     * 允许外部调用，初始化后，启用定时器Timer周期性检测
     */
    public function intervalCheck()
    {
        //更新当前pool最后存活时间
        $this->statusTable->set($this->poolHash(), [
            'lastAliveTime' => time()
        ]);
        $list = [];
        $time = time();
        //遍历所有pool（目前就只支持一个，永不清理了）
        foreach ($this->statusTable as $key => $item) {
            if ($time - $item['lastAliveTime'] >= 2) {
                $list[] = $key;
            }
        }
        //删除其它超时的pool进程
        foreach ($list as $key) {
            $this->statusTable->del($key);
        }

        $this->idleCheck($this->getConfig()->getMaxIdleTime());
        $this->keepMin($this->getConfig()->getMinObjectNum());
    }

    /**
     * 心跳检测（子类实现），如 mysql-query: select 1
     * @param $item $item->__lastUseTime 属性表示该对象被最后一次使用的时间
     * @return bool
     */
    protected function itemIntervalCheck($item): bool
    {
        return true;
    }

    /*
    * 可以解决冷启动问题，预热
    */
    public function keepMin(?int $num = null): int
    {
        if ($num == null) {
            $num = $this->getConfig()->getMinObjectNum();
        }
        if ($this->createdNum < $num) {
            $left = $num - $this->createdNum;
            while ($left > 0) {
                /*
                 * 避免死循环
                 */
                if ($this->initObject() == false) {
                    break;
                }
                $left--;
            }
        }
        return $this->createdNum;
    }


    public function getConfig(): Config
    {
        return $this->conf;
    }

    public function status(bool $currentWorker = false): array
    {
        if ($currentWorker) {
            return $this->statusTable->get($this->poolHash());
        } else {
            $data = [];
            foreach ($this->statusTable as $key => $value) {
                $data[] = $value;
            }
            return $data;
        }
    }

    /**
     * 启动前预热、或获取连接对象时调用
     * @return bool
     * @throws \Throwable
     */
    private function initObject(): bool
    {
        if ($this->destroy) {
            //已销毁pool不再初始化
            return false;
        }
        /*
        * 懒惰模式，可以提前创建 pool对象，因此调用前执行初始化检测
        */
        $this->init();
        $obj = null;
        $this->createdNum++;
        $this->statusTable->incr($this->poolHash(), 'created');
        if ($this->createdNum > $this->getConfig()->getMaxObjectNum()) {
            $this->createdNum--;
            $this->statusTable->decr($this->poolHash(), 'created');
            return false;
        }
        try {
            $obj = $this->createObject();
            if (is_object($obj)) {
                $hash = Random::character(12);
                $this->objHash[$hash] = true;
                $obj->__objHash = $hash;
                $obj->__lastUseTime = time();
                $this->poolChannel->push($obj);
                return true;
            } else {
                $this->createdNum--;
                $this->statusTable->decr($this->poolHash(), 'created');
            }
        } catch (\Throwable $throwable) {
            $this->createdNum--;
            $this->statusTable->decr($this->poolHash(), 'created');
            throw $throwable;
        }
        return false;
    }

    public function isPoolObject($obj): bool
    {
        if (isset($obj->__objHash)) {
            return isset($this->objHash[$obj->__objHash]);
        } else {
            return false;
        }
    }

    public function isInPool($obj): bool
    {
        if ($this->isPoolObject($obj)) {
            return $this->objHash[$obj->__objHash];
        } else {
            return false;
        }
    }

    /*
     * 销毁该pool，但保留pool原有状态
     */
    function destroy()
    {
        $this->destroy = true;
        /*
        * 懒惰模式，可以提前创建 pool对象，因此调用钱执行初始化检测
        */
        $this->init();
        if ($this->intervalCheckTimerId && Timer::exists($this->intervalCheckTimerId)) {
            Timer::clear($this->intervalCheckTimerId);
            $this->intervalCheckTimerId = null;
        }
        if ($this->loadAverageTimerId && Timer::exists($this->loadAverageTimerId)) {
            Timer::clear($this->loadAverageTimerId);
            $this->loadAverageTimerId = null;
        }

        if ($this->poolChannel) {
            while (!$this->poolChannel->isEmpty()) {
                $item = $this->poolChannel->pop(0.01);
                $this->unsetObj($item);
            }
            foreach ($this->inUseObject as $item) {
                $this->unsetObj($item);
                $this->inUseObject = [];
            }

            $this->poolChannel->close();
            $this->poolChannel = null;
        }

        $list = [];
        foreach ($this->statusTable as $key => $value) {
            $list[] = $key;
        }
        foreach ($list as $key) {
            $this->statusTable->del($key);
        }
    }

    function reset(): AbstractPool
    {
        $this->destroy();
        $this->createdNum = 0;
        $this->destroy = false;
        $this->context = [];
        $this->objHash = [];
        return $this;
    }

    //直接调用，使用完立即归还
    public function invoke(callable $call, float $timeout = null)
    {
        $obj = $this->getObj($timeout);
        if ($obj) {
            try {
                $ret = call_user_func($call, $obj);
                return $ret;
            } catch (\Throwable $throwable) {
                throw $throwable;
            } finally {
                $this->recycleObj($obj);
            }
        } else {
            throw new PoolEmpty(static::class . " pool is empty");
        }
    }

    //获取连接池对象（协程结束，自动归还）
    public function defer(float $timeout = null)
    {
        $cid = Coroutine::getCid();
        if (isset($this->context[$cid])) {
            return $this->context[$cid];
        }
        $obj = $this->getObj($timeout);
        if ($obj) {
            $this->context[$cid] = $obj;
            Coroutine::defer(function () use ($cid) {
                if (isset($this->context[$cid])) {
                    $obj = $this->context[$cid];
                    unset($this->context[$cid]);
                    $this->recycleObj($obj);
                }
            });
            return $this->defer($timeout);
        } else {
            throw new PoolEmpty(static::class . " pool is empty");
        }
    }

    /**
     * 懒惰模式，可以提前创建 pool对象，因此调用前执行初始化检测
     * (此方法只会初始化一次)， poolChannel属性不为null执行，故初始化预热、获取连接对象，启动检测定时器（连接池为空没有影响）
     */
    private function init()
    {
        if ((!$this->poolChannel) && (!$this->destroy)) {
            $this->poolChannel = new Channel($this->conf->getMaxObjectNum() + 8);
            if ($this->conf->getIntervalCheckTime() > 0) {
                $this->intervalCheckTimerId = Timer::tick($this->conf->getIntervalCheckTime(), [$this, 'intervalCheck']);
            }
            $this->loadAverageTimerId = Timer::tick(5 * 1000, function () {
                // 5s 定时检测
                $loadWaitTime = $this->loadWaitTimes;   //从连接池取出等待时间
                $loadUseTimes = $this->loadUseTimes;    //从连接池取出总次数
                $this->loadUseTimes = 0;
                $this->loadWaitTimes = 0;
                $this->statusTable->set($this->poolHash(), [
                    'loadWaitTimes' => 0,
                    'loadUseTimes' => 0
                ]);
                //避免分母为0
                if ($loadUseTimes <= 0) {
                    $loadUseTimes = 1;
                }
                $average = $loadWaitTime / $loadUseTimes; // average 记录的是平均每个链接取出的时间
                //取出时间小于限制的阈值时，说明连接数可能比较充裕，负载小，尝试回收部分连接
                if ($this->getConfig()->getLoadAverageTime() > $average) {
                    //负载小。尝试回收链接百分之5的链接
                    $decNum = intval($this->createdNum * 0.05);
                    if (($this->createdNum - $decNum) > $this->getConfig()->getMinObjectNum()) {
                        while ($decNum > 0) {
                            $temp = $this->getObj(0.001, 0);
                            if ($temp) {
                                $this->unsetObj($temp);
                            } else {
                                break;
                            }
                            $decNum--;
                        }
                    }
                }
            });
            //table记录初始化
            $this->statusTable->set($this->poolHash(), [
                'pid' => getmypid(),
                'created' => 0,
                'inuse' => 0,
                'loadWaitTimes' => 0,
                'loadUseTimes' => 0,
                'lastAliveTime' => 0
            ]);
        }
    }

    function poolHash(): string
    {
        return $this->poolHash;
    }

    final function __clone()
    {
        throw new Exception('AbstractObject cannot be clone');
    }
}
