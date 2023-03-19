## swoole-mysql-pool-coroutine-server-task
swoole协程并发任务服务项目（常驻Server进程），使用easyswoole连接池
## 

- 另一种协程容器实现方案（非常驻进程），请前往 [协程容器并发任务项目](https://github.com/JaydenOK/swoole-container-mysql-pool-coroutine-task).

#### 功能逻辑
```text
协程并发任务服务项目，使用easyswoole连接池的（用途有并发刷新账号token，拉单，拉Amazon报告，爬虫等，不用担心一个任务被重复执行问题）。  
可以一个端口启动一个类型任务，或者一个端口启动多个类型任务，当启动多个类型任务时，应适当调大worker_num数。 
```

#### 版本
- PHP 7.1
- Swoole 4.5.11


#### 测试结果

```shell script
总请求数1000, 并发数10,20,40,60,80分别测试, 结果如下:

[root@ac_web ]# php service.php start Amazon 9901  -d  (守护进程启动)
 
[root@ac_web easy_mysql_pool]# curl "127.0.0.1:9901/?task_type=Amazon&concurrency=10&total=1000"
{"taskCount":1000,"concurrency":10,"useTime":"103s"}

[root@ac_web easy_mysql_pool]# curl "127.0.0.1:9901/?task_type=Amazon&concurrency=20&total=1000"
{"taskCount":1000,"concurrency":20,"useTime":"51s"}

[root@ac_web easy_mysql_pool]# curl "127.0.0.1:9901/?task_type=Amazon&concurrency=40&total=1000"
{"taskCount":1000,"concurrency":40,"useTime":"25s"}
 
[root@ac_web easy_mysql_pool]# curl "127.0.0.1:9901/?task_type=Amazon&concurrency=60&total=1000"
{"taskCount":1000,"concurrency":60,"useTime":"19s"}
 
[root@ac_web easy_mysql_pool]# curl "127.0.0.1:9901/?task_type=Amazon&concurrency=80&total=1000"
{"taskCount":1000,"concurrency":80,"useTime":"13s"}


```