<?php

namespace module\server;

use chan;
use EasySwoole\EasySwoole\Config;
use EasySwoole\ORM\Db\Connection;
use EasySwoole\ORM\DbManager;
use Exception;
use InvalidArgumentException;
use module\lib\PdoPoolClient;
use module\task\TaskFactory;
use PDO;
use Swoole\Coroutine;
use Swoole\Database\PDOPool;
use Swoole\Database\PDOProxy;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Process;
use Swoole\Server;
use Swoole\Table;
use Swoole\Timer;

class TaskServerManager
{
    const EVENT_START = 'start';
    const EVENT_MANAGER_START = 'managerStart';
    const EVENT_WORKER_START = 'workerStart';
    const EVENT_WORKER_STOP = 'workerStop';
    const EVENT_REQUEST = 'request';

    /**
     * @var \Swoole\Http\Server
     */
    protected $httpServer;
    /**
     * @var string
     */
    private $taskType;
    /**
     * @var int|string
     */
    private $port;
    private $processPrefix = 'co-server-';
    private $setting = ['worker_num' => 2, 'enable_coroutine' => true];
    /**
     * @var bool
     */
    private $daemon;
    /**
     * @var string
     */
    private $pidFile;
    /**
     * @var int
     */
    private $poolSize = 16;
    /**
     * 是否使用连接池，可参数指定，默认不使用
     * @var bool
     */
    private $isUsePool = false;
    /**
     * @var PDOPool
     */
    private $pool;
    private $checkAvailableTime = 1;
    private $checkLiveTime = 10;
    private $availableTimerId;
    private $liveTimerId;
    /**
     * @var Table
     */
    private $poolTable;

    public function run($argv)
    {
        try {
            $cmd = isset($argv[1]) ? (string)$argv[1] : 'status';
            $this->taskType = isset($argv[2]) ? (string)$argv[2] : '';
            $this->port = isset($argv[3]) ? (string)$argv[3] : 9901;
            $this->daemon = isset($argv[4]) && (in_array($argv[4], ['daemon', 'd', '-d'])) ? true : false;
            $this->isUsePool = true;
            if (empty($this->taskType) || empty($this->port) || empty($cmd)) {
                throw new InvalidArgumentException('params error');
            }
            $this->pidFile = $this->taskType . '.pid';
            if (!in_array($this->taskType, TaskFactory::taskList())) {
                throw new InvalidArgumentException('task_type not exist');
            }
            switch ($cmd) {
                case 'start':
                    $this->start();
                    break;
                case 'stop':
                    $this->stop();
                    break;
                case 'status':
                    $this->status();
                    break;
                default:
                    break;
            }
        } catch (Exception $e) {
            $this->logMessage('Exception:' . $e->getMessage());
        }
    }

    private function start()
    {
        //一键协程化，使回调事件函数的mysql连接、查询协程化
        Coroutine::set(['hook_flags' => SWOOLE_HOOK_TCP]);
        //\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        $this->renameProcessName($this->processPrefix . $this->taskType);
        $this->httpServer = new \Swoole\Http\Server("0.0.0.0", $this->port);
        $setting = [
            'daemonize' => (bool)$this->daemon,
            'log_file' => MODULE_DIR . '/logs/server-' . date('Y-m') . '.log',
            'pid_file' => MODULE_DIR . '/logs/' . $this->pidFile,
        ];
        $this->setServerSetting($setting);
        $this->createTable();
        $this->bindEvent(self::EVENT_START, [$this, 'onStart']);
        $this->bindEvent(self::EVENT_MANAGER_START, [$this, 'onManagerStart']);
        $this->bindEvent(self::EVENT_WORKER_START, [$this, 'onWorkerStart']);
        $this->bindEvent(self::EVENT_WORKER_STOP, [$this, 'onWorkerStop']);
        $this->bindEvent(self::EVENT_REQUEST, [$this, 'onRequest']);
        $this->startServer();
    }

    /**
     * 当前进程重命名
     * @param $processName
     * @return bool|mixed
     */
    private function renameProcessName($processName)
    {
        if (function_exists('cli_set_process_title')) {
            return cli_set_process_title($processName);
        } else if (function_exists('swoole_set_process_name')) {
            return swoole_set_process_name($processName);
        }
        return false;
    }

    private function setServerSetting($setting = [])
    {
        //开启内置协程，默认开启
        //当 enable_coroutine 设置为 true 时，底层自动在 onRequest 回调中创建协程，开发者无需自行使用 go 函数创建协程
        //当 enable_coroutine 设置为 false 时，底层不会自动创建协程，开发者如果要使用协程，必须使用 go 自行创建协程
        $this->httpServer->set(array_merge($this->setting, $setting));
    }

    private function bindEvent($event, callable $callback)
    {
        $this->httpServer->on($event, $callback);
    }

    private function startServer()
    {
        $this->httpServer->start();
    }

    public function onStart(Server $server)
    {
        //onStart 调用时修改主进程名称
        //onManagerStart 调用时修改管理进程 (manager) 的名称
        //onWorkerStart 调用时修改 worker 进程名称
        $this->logMessage('start, master_pid:' . $server->master_pid);
        $this->renameProcessName($this->processPrefix . $this->taskType . '-master');
    }

    public function onManagerStart(Server $server)
    {
        $this->logMessage('manager start, manager_pid:' . $server->manager_pid);
        $this->renameProcessName($this->processPrefix . $this->taskType . '-manager');
    }

    public function onWorkerStart(Server $server, int $workerId)
    {
        $this->logMessage('worker start, worker_pid:' . $server->worker_pid);
        $this->renameProcessName($this->processPrefix . $this->taskType . '-worker-' . $workerId);
        //初始化连接池
        if ($this->isUsePool) {
            try {
                //================= 注册 mysql orm 连接池 =================
                $config = new \EasySwoole\ORM\Db\Config(\EasySwoole\EasySwoole\Config::getInstance()->getConf('MYSQL'));

                $config->setMinObjectNum(5)->setMaxObjectNum(30); // 【可选操作】我们已经在 dev.php 中进行了配置 配置连接池数量; 总连接数 = minObjectNum * SETTING.worker_num
                //DbManager::getInstance()->addConnection(new Connection($config));
                // 设置指定连接名称 后期可通过连接名称操作不同的数据库
                $ormConnection = new Connection($config);

                DbManager::getInstance()->addConnection(new Connection($config), 'main');    //连接池1
                DbManager::getInstance()->addConnection(new Connection($config), 'write');     //连接池2

                //=================  注册redis连接池 (http://192.168.92.208:9511/Account/mysqlPoolList)  =================
                $config = new \EasySwoole\Pool\Config();
                $redisConfig1 = new \EasySwoole\Redis\Config\RedisConfig(Config::getInstance()->getConf('REDIS'));
                // 注册连接池管理对象
                \EasySwoole\Pool\Manager::getInstance()->register(new \App\Pool\RedisPool($config, $redisConfig1), 'redis');
                /**
                 * @var $connection \EasySwoole\ORM\Db\Connection
                 */
                $connection = DbManager::getInstance()->getConnection('main');
                $connection->__getClientPool()->keepMin();   //预热连接池1


                $this->logMessage('use pool:' . $this->poolSize);
            } catch (Exception $e) {
                $this->logMessage('initPool error:' . $e->getMessage());
            }
        }
    }

    public function onWorkerStop(Server $server, int $workerId)
    {
        $this->logMessage('worker stop, worker_pid:' . $server->worker_pid);
        if ($this->isUsePool) {
            try {
                $this->logMessage('pool close');
                $this->pool && $this->pool->close();
                $this->clearTimer();
            } catch (Exception $e) {
                $this->logMessage('pool close error:' . $e->getMessage());
            }
        }
    }

    public function onRequest(Request $request, Response $response)
    {
        try {
            $concurrency = isset($request->get['concurrency']) ? (int)$request->get['concurrency'] : 5;  //并发数
            $total = isset($request->get['total']) ? (int)$request->get['total'] : 100;  //需总处理记录数
            $taskType = isset($request->get['task_type']) ? (string)$request->get['task_type'] : '';  //任务类型
            if ($concurrency <= 0 || empty($taskType)) {
                throw new InvalidArgumentException('parameters error');
            }
            //数据库配置信息
            $pdo = $this->isUsePool ? $this->getPoolObject() : null;
            $mainTaskModel = TaskFactory::factory($taskType, $pdo);
            $taskList = $mainTaskModel->getTaskList(['limit' => $total]);       //已一键协程化，多个请求时，此处不阻塞
            if (empty($taskList)) {
                throw new InvalidArgumentException('no tasks waiting to be executed');
            }
            $taskCount = count($taskList);
            $startTime = time();
            $this->logMessage("task count:{$taskCount}");
            $taskChan = new chan($taskCount);
            //初始化并发数量
            $producerChan = new chan($concurrency);
            $dataChan = new chan($total);
            for ($size = 1; $size <= $concurrency; $size++) {
                $producerChan->push(1);
            }
            foreach ($taskList as $task) {
                //增加当前任务类型标识
                $task = array_merge($task, ['task_type' => $taskType]);
                $taskChan->push($task);
            }
            //创建生产者主协程，用于投递任务
            go(function () use ($taskChan, $producerChan, $dataChan) {
                while (true) {
                    $chanStatsArr = $taskChan->stats(); //queue_num 通道中的元素数量
                    if (!isset($chanStatsArr['queue_num']) || $chanStatsArr['queue_num'] == 0) {
                        //queue_num 通道中的元素数量
                        $this->logMessage('finish deliver');
                        break;
                    }
                    //阻塞获取
                    $producerChan->pop();
                    $task = $taskChan->pop();
                    //创建子协程，执行任务，使用channel传递数据
                    go(function () use ($producerChan, $dataChan, $task) {
                        try {
                            //每个协程，创建独立连接（可从连接池获取）
                            $pdo = $this->isUsePool ? $this->getPoolObject() : null;
                            $taskModel = TaskFactory::factory($task['task_type'], $pdo);
                            Coroutine::defer(function () use ($taskModel) {
                                //释放内存及mysql连接
                                unset($taskModel);
                            });
                            $this->logMessage('taskRun:' . $task['id']);
                            $responseBody = $taskModel->taskRun($task['id'], $task);
                            $this->logMessage("taskFinish:{$task['id']}");
                        } catch (Exception $e) {
                            $this->logMessage("taskRunException: id:{$task['id']}: msg:" . $e->getMessage());
                            $responseBody = null;
                        }
                        $pushStatus = $dataChan->push(['id' => $task['id'], 'data' => $responseBody]);
                        if ($pushStatus !== true) {
                            $this->logMessage('push errCode:' . $dataChan->errCode);
                        }
                        //处理完，恢复producerChan协程
                        $producerChan->push(1);
                    });
                }
            });
            //消费数据
            for ($i = 1; $i <= $taskCount; $i++) {
                //阻塞，等待投递结果, 通道被关闭时，执行失败返回 false,
                $receiveData = $dataChan->pop();
                if ($receiveData === false) {
                    $this->logMessage('channel close, pop errCode:' . $dataChan->errCode);
                    //退出
                    break;
                }
                $this->logMessage('taskDone:' . $receiveData['id']);
                $mainTaskModel->taskDone($receiveData['id'], $receiveData['data']);
            }
            //返回响应
            $endTime = time();
            $return = ['taskCount' => $taskCount, 'concurrency' => $concurrency, 'useTime' => ($endTime - $startTime) . 's'];
        } catch (InvalidArgumentException $e) {
            $return = json_encode(['Exception' => $e->getMessage()]);
        } catch (Exception $e) {
            $this->logMessage('Exception:' . $e->getMessage());
            $return = json_encode(['Exception' => $e->getMessage()]);
        }
        $mainTaskModel = null;
        return $response->end(json_encode($return));
    }

    private function logMessage($logData)
    {
        $logData = (is_array($logData) || is_object($logData)) ? json_encode($logData, JSON_UNESCAPED_UNICODE) : $logData;
        echo date('[Y-m-d H:i:s]') . $logData . PHP_EOL;
    }

    private function stop($force = false)
    {
        $pidFile = MODULE_DIR . '/logs/' . $this->pidFile;
        if (!file_exists($pidFile)) {
            throw new Exception('server not running');
        }
        $pid = file_get_contents($pidFile);
        if (!Process::kill($pid, 0)) {
            unlink($pidFile);
            throw new Exception("pid not exist:{$pid}");
        } else {
            if ($force) {
                Process::kill($pid, SIGKILL);
            } else {
                Process::kill($pid);
            }
        }
    }

    private function status()
    {
        $pidFile = MODULE_DIR . '/logs/' . $this->pidFile;
        if (!file_exists($pidFile)) {
            throw new Exception('server not running');
        }
        $pid = file_get_contents($pidFile);
        //$signo=0，可以检测进程是否存在，不会发送信号
        if (!Process::kill($pid, 0)) {
            echo 'not running, pid:' . $pid . PHP_EOL;
        } else {
            echo 'running, pid:' . $pid . PHP_EOL;
        }
    }

    /**
     * swoole官方连接池，PDOProxy 实现了自动重连(代理模式)，构造函数注入 \PDO 对象。即$__object属性
     * 可改用EasySwoole连接池
     * @return PDO|PDOProxy
     * @throws Exception
     */
    private function getPoolObject()
    {
        $pdo = $this->pool->get();
        if (!($pdo instanceof PDOProxy || $pdo instanceof PDO)) {
            throw new Exception('getNullPoolObject');
        }
        $this->logMessage('pdo get:' . spl_object_hash($pdo));
        defer(function () use ($pdo) {
            //协程函数结束归还对象
            if ($pdo !== null) {
                $this->logMessage('pdo put:' . spl_object_hash($pdo));
                $this->pool->put($pdo);
            }
        });
        return $pdo;
    }

    //连接池对象注意点：
    //1，需要定期检查是否可用；
    //2，需要定期更新对象，防止在任务执行过程中连接断开（记录最后获取，使用时间，定时校验对象是否留存超时）
    public function checkPool()
    {
        if (true) {
            return 'not support now';
        }
        $this->availableTimerId = Timer::tick($this->checkAvailableTime * 1000, function () {

        });

        $this->liveTimerId = Timer::tick($this->checkLiveTime * 1000, function () {
        });
    }

    private function clearTimer()
    {
        if ($this->availableTimerId) {
            Timer::clear($this->availableTimerId);
        }
        if ($this->liveTimerId) {
            Timer::clear($this->liveTimerId);
        }
    }

    private function createTable()
    {
        if (true) {
            return 'not support now';
        }
        //存储数据size，即mysql总行数
        $size = 1024;
        $this->poolTable = new Table($size);
        $this->poolTable->column('created', Table::TYPE_INT, 10);
        $this->poolTable->column('pid', Table::TYPE_INT, 10);
        $this->poolTable->column('inuse', Table::TYPE_INT, 10);
        $this->poolTable->column('loadWaitTimes', Table::TYPE_FLOAT, 10);
        $this->poolTable->column('loadUseTimes', Table::TYPE_INT, 10);
        $this->poolTable->column('lastAliveTime', Table::TYPE_INT, 10);
        $this->poolTable->create();
    }

}