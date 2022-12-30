CREATE TABLE `t_async_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '任务名称',
  `master` varchar(50) NOT NULL DEFAULT '' COMMENT '主进程名称',
  `prefetch` int(5) NOT NULL DEFAULT '10' COMMENT '预取数量',
  `host` varchar(50) NOT NULL DEFAULT '' COMMENT 'host',
  `port` int(5) NOT NULL DEFAULT '0' COMMENT '端口',
  `login` varchar(50) NOT NULL DEFAULT '' COMMENT '登录名',
  `password` varchar(50) NOT NULL DEFAULT '',
  `vhost` varchar(20) NOT NULL DEFAULT '',
  `exchange` varchar(50) NOT NULL DEFAULT '' COMMENT '交换机名称',
  `queue` varchar(50) NOT NULL DEFAULT '' COMMENT '队列名称',
  `route_key` varchar(50) NOT NULL DEFAULT '' COMMENT 'routeKey',
  `num` int(5) NOT NULL DEFAULT '1' COMMENT '进程数量',
  `url` varchar(200) NOT NULL DEFAULT '' COMMENT '回调地址',
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT 'master pid',
  `child_pid` varchar(300) NOT NULL DEFAULT '' COMMENT 'child pid',
  `status` tinyint(3) NOT NULL DEFAULT '0' COMMENT '1启用，2停用',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `idx_queue` (`queue`) USING BTREE,
  KEY `idx_master` (`master`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='异步消息服务队列';


-- INSERT INTO `t_async_config` (`id`, `name`, `master`, `prefetch`, `host`, `port`, `login`, `password`, `vhost`, `exchange`, `queue`, `route_key`, `num`, `url`, `pid`, `child_pid`, `status`, `create_time`, `update_time`) VALUES('1', 'send_app_msg', 'async-master-send_app_msg', '5', '192.168.71.91', '5672', 'admin', 'admin123.', '/', 'async-message-exchange', 'send_app_msg', 'send_app_msg', '2', 'http://192.168.92.208:81/callback/ThirdCompanyReceiver/accountSyncThirdAccount', '13542', '13543,13544', '1', '2022-11-15 14:45:41', '2022-11-16 16:33:39');
-- INSERT INTO t_async_config` (`id`, `name`, `master`, `prefetch`, `host`, `port`, `login`, `password`, `vhost`, `exchange`, `queue`, `route_key`, `num`, `url`, `pid`, `child_pid`, `status`, `create_time`, `update_time`) VALUES ('2', 'send_code', 'async-master-send_code', '5', '192.168.71.91', '5672', 'admin', 'admin123.', '/', 'async-message-exchange', 'send_code', 'send_code', '3', 'http://192.168.92.208:10038/Order/insertOrder', '14860', '14861,14862,14863', '1', '2022-11-15 14:45:41', '2022-11-16 16:41:10');
