<?php

namespace module\task;

use EasySwoole\Pool\Manager;

class AmazonModel extends TaskModel
{

    public function tableName()
    {
        return 'yibai_amazon_account';
    }

    public function getTaskList($params)
    {
        $builder = $this->mysqlClient->queryBuilder()
            ->where('account_status', 10)
            ->limit($params['limit'])
            ->get($this->tableName());
        try {
            $result = $this->mysqlClient->execBuilder();
        } catch (\Throwable $e) {
            echo $e->getMessage() . PHP_EOL;
            $result = [];
        }
        return $result;
    }

    /**
     * 重新解压，编译支持https
     * phpize && ./configure --enable-openssl --enable-http2 && make && sudo make install
     * @param $id
     * @param $task
     * @return mixed
     * @throws \Exception
     */
    public function taskRun($id, $task)
    {
        // TODO: Implement taskRun() method.
        //todo 模拟业务耗时处理逻辑
        try {
            $set = ['refresh_num' => mt_rand(1, 10), 'update_time' => date('Y-m-d H:i:s')];
            $this->mysqlClient->queryBuilder()->where('id', $id)->update($this->tableName(), $set);
            $this->mysqlClient->execBuilder();
        } catch (\Throwable $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        $id = $task['id'];
        $appId = $task['app_id'];
        $sellingPartnerId = $task['selling_partner_id'];
        $host = 'api.amazon.com';
        $path = '/auth/o2/token';
        $data = [];
        $data['grant_type'] = 'refresh_token';
        $data['client_id'] = '111';
        $data['client_secret'] = '222';
        $data['refresh_token'] = '333';
        $cli = new \Swoole\Coroutine\Http\Client($host, 443, true);
        $cli->set(['timeout' => 10]);
        $cli->setHeaders([
            'Host' => $host,
            'grant_type' => 'refresh_token',
            'client_id' => 'refresh_token',
            "User-Agent" => 'Chrome/49.0.2587.3',
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
        ]);
        $cli->post($path, http_build_query($data));
        $responseBody = $cli->body;
        return $responseBody;
    }

    public function taskDone($id, $data)
    {
        // TODO: Implement taskDone() method.
        try {
            $set = ['refresh_msg' => json_encode($data, 256), 'refresh_time' => date('Y-m-d H:i:s')];
            $this->mysqlClient->queryBuilder()->where('id', $id)->update($this->tableName(), $set);
            $this->mysqlClient->execBuilder();
        } catch (\Throwable $e) {
            echo $e->getMessage() . PHP_EOL;
        }
        return true;
    }


}