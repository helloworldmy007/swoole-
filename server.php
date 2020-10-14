<?php

require('Syserver.php');

// 工作目录，默认是当前路径，一般不需要修改
define("ROOT", __DIR__);

// WebSocket 服务器监听地址，默认 0.0.0.0 无需修改
define("BIND_HOST", "0.0.0.0");

// WebSocket 服务器监听端口，默认 811
define("BIND_PORT", 811);

// 是否使用 Redis 来储存歌单数据
define("USE_REDIS", true);

// Redis 地址
define("REDIS_HOST", "127.0.0.1");

// Redis 端口
define("REDIS_PORT", "6379");

// Redis 密码（留空禁用）
define("REDIS_PASS", "");

// 执行任务的 Workers 数量，不要太小也不要太大，一般 32 左右
define("WORKERNUM", 32);

// 是否启用调试模式，可以输出详细信息
define("DEBUG", false);

// 是否使用 X-Real-IP 来获取客户端 IP，适用于 Nginx 反代后的 WebSocket
define("USE_X_REAL_IP", true);

// 客户端聊天冷却时间，单位秒
define("MIN_CHATWAIT", 3);

// 聊天内容的最大长度
define("MAX_CHATLENGTH", 100);

// 房管密码
define("ADMIN_PASS", "123456789");


/**
 *
 *  开始运行服务器，请勿修改
 *
 */
$syncMusic = new Syserver(BIND_HOST, BIND_PORT, WORKERNUM, DEBUG, USE_X_REAL_IP, ADMIN_PASS);
$syncMusic->init();
$syncMusic->run();