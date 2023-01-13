<?php


namespace EasySwoole\Pool;


use EasySwoole\Pool\Exception\Exception;
use EasySwoole\Spl\SplBean;

class Config extends SplBean
{
    //周期性检查时间
    protected $intervalCheckTime = 10 * 1000;
    //超时时间阈值，超过闲置时间未使用，释放，单位秒
    protected $maxIdleTime = 15;
    //连接池最大数
    protected $maxObjectNum = 20;
    //连接池最小数
    protected $minObjectNum = 5;
    //获取连接超时时间
    protected $getObjectTimeout = 3.0;
    //获取连接时间阈值，判断连接池数量是否过于空闲，用于达到阈值回收部分连接
    protected $loadAverageTime = 0.001;

    protected $extraConf;

    /**
     * @return float|int
     */
    public function getIntervalCheckTime()
    {
        return $this->intervalCheckTime;
    }

    /**
     * @param $intervalCheckTime
     * @return Config
     */
    public function setIntervalCheckTime($intervalCheckTime): Config
    {
        $this->intervalCheckTime = $intervalCheckTime;
        return $this;
    }

    /**
     * @return int
     */
    public function getMaxIdleTime(): int
    {
        return $this->maxIdleTime;
    }

    /**
     * @param int $maxIdleTime
     * @return Config
     */
    public function setMaxIdleTime(int $maxIdleTime): Config
    {
        $this->maxIdleTime = $maxIdleTime;
        return $this;
    }

    /**
     * @return int
     */
    public function getMaxObjectNum(): int
    {
        return $this->maxObjectNum;
    }

    public function setMaxObjectNum(int $maxObjectNum): Config
    {
//        if ($this->minObjectNum >= $maxObjectNum) {
//            throw new Exception('min num is bigger than max');
//        }
        $this->maxObjectNum = $maxObjectNum;
        return $this;
    }

    /**
     * @return float
     */
    public function getGetObjectTimeout(): float
    {
        return $this->getObjectTimeout;
    }

    /**
     * @param float $getObjectTimeout
     * @return Config
     */
    public function setGetObjectTimeout(float $getObjectTimeout): Config
    {
        $this->getObjectTimeout = $getObjectTimeout;
        return $this;
    }

    public function getExtraConf()
    {
        return $this->extraConf;
    }

    /**
     * @param $extraConf
     * @return Config
     */
    public function setExtraConf($extraConf): Config
    {
        $this->extraConf = $extraConf;
        return $this;
    }

    /**
     * @return int
     */
    public function getMinObjectNum(): int
    {
        return $this->minObjectNum;
    }

    /**
     * @return float
     */
    public function getLoadAverageTime(): float
    {
        return $this->loadAverageTime;
    }

    /**
     * @param float $loadAverageTime
     * @return Config
     */
    public function setLoadAverageTime(float $loadAverageTime): Config
    {
        $this->loadAverageTime = $loadAverageTime;
        return $this;
    }

    public function setMinObjectNum(int $minObjectNum): Config
    {
        if ($minObjectNum >= $this->maxObjectNum) {
            throw new Exception('min num is bigger than max');
        }
        $this->minObjectNum = $minObjectNum;
        return $this;
    }
}