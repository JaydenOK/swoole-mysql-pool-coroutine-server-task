<?php

namespace module\task;

class ShopeeModel extends TaskModel
{

    public function tableName()
    {
        return 'yibai_shopee_account';
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

    public function taskRun($id, $task)
    {
        // TODO: Implement taskRun() method.
        try {
            $set = ['refresh_num' => mt_rand(1, 10), 'update_time' => date('Y-m-d H:i:s')];
            $this->mysqlClient->queryBuilder()->where('id', $id)->update($this->tableName(), $set);
            $this->mysqlClient->execBuilder();
        } catch (\Throwable $e) {
            echo $e->getMessage() . PHP_EOL;
        }
        $host = 'partner.shopeemobile.com';
        $timestamp = time();
        $path = '/api/v2/auth/access_token/get';
        $sign = '111';
        $data = [];
        $data['partner_id'] = 111;
        $data['refresh_token'] = '222';
        $data['merchant_id'] = 333;
        $path .= '?timestamp=' . $timestamp . '&sign=' . $sign . '&partner_id=' . $data['partner_id'];
        $cli = new \Swoole\Coroutine\Http\Client($host, 443, true);
        $cli->set(['timeout' => 10]);
        $cli->setHeaders([
            'Host' => $host,
            'Content-Type' => 'application/json;charset=UTF-8',
        ]);
        $data = [];
        $cli->post($path, json_encode($data));
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