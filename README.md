## swoole-mysql-pool


#### 功能逻辑
```text
使用了第三方 easyswoole 连接池。
```

#### 版本
- PHP 7.1
- Swoole 4.5.11


#### 测试结果

```shell script

[root@ac_web ]# php service.php start Amazon 9901  -d  (守护进程启动)
 
[root@ac_web ]# curl "127.0.0.1:9901/?task_type=Amazon&concurrency=5&total=200"
{"taskCount":200,"concurrency":5,"useTime":"56s"}
 
[root@ac_web ]# curl "127.0.0.1:9901/?task_type=Amazon&concurrency=10&total=200"
{"taskCount":200,"concurrency":10,"useTime":"28s"}
 
[root@ac_web ]# curl "127.0.0.1:9901/?task_type=Amazon&concurrency=20&total=200"
{"taskCount":200,"concurrency":20,"useTime":"10s"}
 
[root@ac_web ]# curl "127.0.0.1:9901/?task_type=Amazon&concurrency=50&total=200"
{"taskCount":200,"concurrency":50,"useTime":"6s"}
 
[root@ac_web ]# curl "127.0.0.1:9901/?task_type=Amazon&concurrency=200&total=500"
{"taskCount":500,"concurrency":200,"useTime":"3s"}

[root@ac_web ]# php service.php stop Amazon 

```