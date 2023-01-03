<?php

namespace module\task;

use EasySwoole\ORM\Db\MysqliClient;

abstract class TaskModel implements Task
{

    /**
     * @var MysqliClient|null
     */
    protected $mysqlClient;

    /**
     * TaskModel constructor.
     * @param MysqliClient $mysqlClient
     */
    public function __construct($mysqlClient = null)
    {
        $this->mysqlClient = $mysqlClient;
    }

    //关闭mysql短连接
    public function __destruct()
    {
        $this->mysqlClient = null;
    }

}