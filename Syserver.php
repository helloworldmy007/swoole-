<?php

class Syserver{
    
    private $server;
	private $bindHost;
	private $bindPort;
	private $workersNum;
	private $debug;
	private $getIpMethod;
    private $adminPass;
    
    /**
     * 
     * 定义服务器基础信息
     * 
     * */
    public function __construct($bindHost, $bindPort, $workersNum, $debug, $getIpMethod, $adminPass){
        $this->bindHost    = $bindHost;
		$this->bindPort    = $bindPort;
		$this->workersNum  = $workersNum;
		$this->debug       = $debug;
		$this->getIpMethod = $getIpMethod;
		$this->adminPass   = $adminPass;
    }
    /**
	 *
	 *  Init 初始化并配置服务器
	 *
	 * */
	public function init(){
	    $this->server = new Swoole\Websocket\Server($bindHost, $this->bindPort);
	    $this->server->set([
	        'task_worker_num'=> $this->workersNum,                     //设置异步任务的工作进程数量
            'worker_num'=> 16               //设置启动的 Worker 进程数。【默认值：CPU 核数】
	    ]);
	    
	    // Table 表，用于储存服务器的信息
	    $table = new Swoole\Table(1024);
	    $table->column('banned_ips', swoole_table::TYPE_STRING, 32768);  //内存表增加一列
		$table->create();
	    
	    // Chats 表，用于储存用户的信息
	    $chats = new Swoole\Table(1024);
	    $chats->column("ip", swoole_table::TYPE_STRING, 256);   //内存表增加一列
		$chats->column("last", swoole_table::TYPE_FLOAT, 8);
		$chats->create();       //创建内存表。定义好表的结构后，执行 create 向操作系统申请内存，创建表。
	    
	    // 初始化信息
	    $this->server->table = $table;
	    $this->server->chats = $chats;
	    $this->server->started = false;
	    
	    /**
		 *
		 *  Open Event 当客户端与服务器建立连接时触发此事件
		 *
		 */
	    $this->server->on('open', function (Swoole\WebSocket\Server $server, $request) {
           // 当第一个客户端连接到服务器的时候就触发 Task 去处理事件
           if(!$server->started) {
				$server->task(["action" => "Start"]);
				$server->started = true;
			}
			// 获取客户端的 IP 地址
			if($this->getIpMethod) {
				$clientIp = $request->header['x-real-ip'] ?? "127.0.0.1";
			} else {
				$clientIp = $server->getClientInfo($request->fd)['remote_ip'] ?? "127.0.0.1";
			}
			// 将客户端 IP 储存到表中
			$server->chats->set($request->fd, ["ip" => $clientIp]);
			
			$this->consoleLog("客户端 {$request->fd} [{$clientIp}] 已连接到服务器", 1, true);
			$server->push($request->fd, json_encode([
				"type" => "msg",
				"data" => "你已经成功连接到服务器！"
			]));
        });
        
        /**
		 *
		 *  Task Event 当服务器运行任务时触发此事件
		 *
		 */
        $this->server->on('Task', function (Swoole\Server $server, $task_id, $from_id, $data) {
            // 如果是服务器初始化任务
			if($data['action'] == "Start") {
			    
			}
        });
    
        /**
		 *
		 *  Message Event 当客户端发送数据到服务器时触发此事件
		 *
		 */
        $this->server->on('message', function (Swoole\WebSocket\Server $server, $frame) {
            
            $clients = $server->connections;
            $clientIp = $this->getClientIp($frame->fd);
            
			$adminIp = $this->getAdminIp();
			
		    // 把客户端 IP 地址的 C 段和 D 段打码作为用户名显示
			$username = $this->getMarkName($clientIp);
			
            // 解析客户端发过来的消息
            $message = $frame->data;
			$json = json_decode($message, true);
			if($json && isset($json['type'])) {
			    switch($json['type']) {
			        case "heartbeat":
						// 处理客户端发过来心跳包的操作，返回在线人数给客户端
						$server->push($frame->fd, json_encode([
							"type" => "online",
							"data" => count($server->connections)
						]));
						break;
					case 'username':
        	           // 如果是设置昵称
							$userNick = $json['data'];
							// 正则判断用户名是否合法
							if(preg_match("/^[\x{4e00}-\x{9fa5}A-Za-z0-9_]+[^_]{2,20}$/u", $userNick)) {
								if($this->isBlackList($userNick)) {
									$server->push($frame->fd, json_encode([
										"type" => "msg",
										"data" => "不允许的昵称"
									]));
								} elseif(mb_Strlen($userNick) <= 20) {
									$this->setUserNickname($clientIp, $userNick,$frame->fd);
									$server->push($frame->fd, json_encode([
										"type" => "msg",
										"data" => "昵称设置成功"
									]));
									$server->push($frame->fd, json_encode([
										"type" => "setname",
										"data" => $userNick
									]));
								} else {
									$server->push($frame->fd, json_encode([
										"type" => "msg",
										"data" => "昵称最多 20 个字符"
									]));
								}
							} else {
								$server->push($frame->fd, json_encode([
									"type" => "msg",
									"data" => "只允许中英文数字下划线，最少 3 个字"
								]));
							}
								
        	            break;
    	            case "loginadmin":
    	                $userPass = $json['data'];
    	                
    					// 判断密码是否正确
						if($userPass == $this->adminPass) {
							$this->setAdminIp($clientIp,$frame->fd);
							$server->push($frame->fd, json_encode([
								"type" => "msg",
								"data" => "房管登录成功"
							]));
						} else {
							$server->push($frame->fd, json_encode([
								"type" => "msg",
								"data" => "房管密码错误"
							]));
						}
    					break;
        	        case "msg":
        	            // 获取客户端最后发言的时间戳
						$lastChat = $this->getLastChat($frame->fd);
						//防止客户端刷屏
						if($lastChat && time() - $lastChat <= MIN_CHATWAIT) {
							$server->push($frame->fd, json_encode([
								"type" => "msg",
								"data" => "发言太快，请稍后再发送"
							]));
						} else {
						    $userNickName = $this->getUserNickname($clientIp,$frame->fd);
						    var_dump($this->isBanned($userNickName));
						    var_dump($userNickName);
						    // 判断客户端是否已被封禁
                			if($this->isBanned($userNickName)) {
                				$server->push($frame->fd, json_encode([
                					"type" => "msg",
                					"data" => "你没有权限发言"
                				]));
                			} else {
                			    if($json['data'] == "禁言列表007") {
									
    								// 查看已禁言的用户列表，先判断是否是管理员
    								if($this->isAdmin($clientIp,$frame->fd)) {
    									$server->push($frame->fd, json_encode([
    										"type" => "chat",
    										"user" => "System",
    										"time" => date("Y-m-d H:i:s"),
    										"data" => htmlspecialchars("禁言 IP 列表：" . $this->getBannedIp())
    									]));
    								} else {
    									$server->push($frame->fd, json_encode([
    										"type" => "msg",
    										"data" => "你没有权限这么做"
    									]));
    								}
    							} elseif(mb_substr($json['data'], 0, 3) == "禁言-" && mb_strlen($json['data']) > 3) {
    								
    								// 如果是禁言客户端的命令，先判断是否是管理员
    								if($this->isAdmin($clientIp,$frame->fd)) {
    									$banName = trim(mb_substr($json['data'], 3, 99999));
    									if(!empty($banName)) {
    										
    										// 判断是否已经被禁言
    										if($this->isBanned($banName)) {
    											$server->push($frame->fd, json_encode([
    												"type" => "msg",
    												"data" => "这个 IP 已经被禁言了"
    											]));
    										} else {
    											$this->banIp($banName);
    											$server->push($frame->fd, json_encode([
    												"type" => "msg",
    												"data" => "成功禁止此 IP 发言"
    											]));
    										}
    									} else {
    										$server->push($frame->fd, json_encode([
    											"type" => "msg",
    											"data" => "禁言的 IP 不能为空！"
    										]));
    									}
    								} else {
    									$server->push($frame->fd, json_encode([
    										"type" => "msg",
    										"data" => "你没有权限这么做"
    									]));
    								}
    							} elseif(mb_substr($json['data'], 0, 3) == "解禁-" && mb_strlen($json['data']) > 3) {
    								
    								// 如果是解禁客户端的命令，先判断是否是管理员
    								if($this->isAdmin($clientIp,$frame->fd)) {
    									$banName = trim(mb_substr($json['data'], 3, 99999));
    									if(!empty($banName)) {
    										
    										// 如果用户没有被封禁
    										if(!$this->isBanned($banName)) {
    											$server->push($frame->fd, json_encode([
    												"type" => "msg",
    												"data" => "这个 IP 没有被禁言"
    											]));
    										} else {
    											$this->unbanIp($banName);
    											$server->push($frame->fd, json_encode([
    												"type" => "msg",
    												"data" => "成功解禁此 IP 的禁言"
    											]));
    										}
    									} else {
    										$server->push($frame->fd, json_encode([
    											"type" => "msg",
    											"data" => "解禁的 IP 不能为空！"
    										]));
    									}
    								} else {
    									$server->push($frame->fd, json_encode([
    										"type" => "msg",
    										"data" => "你没有权限这么做"
    									]));
    								}
    						    } elseif(mb_substr($json['data'], 0, 5) == "加黑名单-" && mb_strlen($json['data']) > 5) {
    						        // 如果是房管登录操作
    								$blackList = trim(mb_substr($json['data'], 5, 99999));
    								
    								// 判断密码是否正确
    								if($this->isAdmin($clientIp,$frame->fd)) {
    									$this->addBlackList($blackList);
    									$server->push($frame->fd, json_encode([
    										"type" => "msg",
    										"data" => "已增加新的黑名单"
    									]));
    								} else {
    									$server->push($frame->fd, json_encode([
    										"type" => "msg",
    										"data" => "你没有权限这么做"
    									]));
    								}
    						    } else{
    						        // 储存用户的最后发言时间
        							$this->setLastChat($frame->fd, time());
        							$this->consoleLog("客户端 {$frame->fd} 发送消息：{$json['data']}", 1, true);
        					
        						    // 默认消息内容，即普通聊天，广播给所有客户端
        							if(mb_strlen($json['data']) > MAX_CHATLENGTH) {
        								$server->push($frame->fd, json_encode([
        									"type" => "msg",
        									"data" => "消息过长，最多 " . MAX_CHATLENGTH . " 字符"
        								]));
        							} else {
        								if($this->isAdmin($clientIp,$frame->fd)) {         //本地测试暂使用id
        									$username = "管理员";
        								}
        								// 广播给所有客户端
        								$userNickName = $this->getUserNickname($clientIp,$frame->fd);
        								
        								foreach($clients as $id) {
        									$showUserName = $this->isAdmin($this->getClientIp($id),$frame->fd) ? $username : $clientIp;
        									if($userNickName) {
        										$showUserName = "{$userNickName} ({$showUserName})";
        									}
        									$server->push($id, json_encode([
        										"type" => "chat",
        										"user" => htmlspecialchars($showUserName),
        										"time" => date("Y-m-d H:i:s"),
        										"data" => htmlspecialchars($json['data'])
        									]));
        								}
        							}
    						    }
                			}
						    
						}
        	            break;
					default:
						// 如果客户端发过来未知的消息类型
						$this->consoleLog("客户端 {$frame->fd} 发送了未知消息：{$message}", 2, true);
			    }
			}
        });
        
        /**
		 *
		 *  Close Event 当客户端断开与服务器的连接时触发此事件
		 *
		 */
		$this->server->on('close', function ($server, $fd) {
			$this->consoleLog("客户端 {$fd} 已断开连接", 1, true);
		});
    
	}
	/**
	 *
	 *  Run 启动服务器
	 *
	 * */
	public function run()
	{
		$this->server->start();
	}
	
	/**
	 *
	 *  ConsoleLog 控制台输出日志
	 *
	 */
	private function consoleLog($data, $level = 1, $directOutput = false)
	{
		$msgData = "[" . date("Y-m-d H:i:s") . " " . $this->getLoggerLevel($level) . "] {$data}\n";
		if($directOutput) {
			echo $msgData;
		} else {
			return $msgData;
		}
	}
	
	/**
	 *
	 *  GetLoggerLevel 获取输出日志的等级
	 *
	 */
	private function getLoggerLevel($level)
	{
		$levelGroup = ["DEBUG", "INFO", "WARNING", "ERROR"];
		return $levelGroup[$level] ?? "INFO";
	}
	
	/**
	 *
	 *  SetLastChat 设置客户端的最后发言时间
	 *
	 */
	private function setLastChat($id, $time = 0)
	{
		$this->server->chats->set($id, ["last" => $time]);
	}
	
	/**
	 *
	 *  GetLastChat 获取客户端最后一次发言时间
	 *
	 */
	private function getLastChat($id)
	{
		return $this->server->chats->get($id, "last") ?? 0;
	}
	
	/**
	 *
	 *  IsAdmin 判断是否是管理员
	 *
	 */
	private function isAdmin($ip,$id)
	{
		$adminIp = $this->getAdminIp();
		return ($adminIp !== "" && $adminIp !== "127.0.0.1" && $adminIp == $id);    //本地测试暂使用id
	}
	
	/**
	 *
	 *  GetAdminIp 获取管理员的 IP
	 *
	 */
	private function getAdminIp()
	{
		$adminIp = @file_get_contents(ROOT . "/admin.ip");
		return $adminIp ?? "127.0.0.1";
	}
	/**
	 *
	 *  GetUserNickname 获取用户的昵称
	 *
	 */
	private function getUserNickname($ip,$id)       //本地测试暂使用id
	{
		$data = $this->getUserNickData();
		return $data[$id] ?? false;
	}
	
	/**
	 *
	 *  GetUserNickData 获取所有用户的昵称数据
	 *
	 */
	private function getUserNickData()
	{
		$data = @file_get_contents(ROOT . "/username.json");
		$json = json_decode($data, true);
		return $json ?? [];
	}
	/**
	 *
	 *  GetClientIp 获取客户端 IP 地址
	 *
	 */
	private function getClientIp($id)
	{
		return $this->server->chats->get($id, "ip") ?? "127.0.0.1";
	}
	/**
	 *
	 *  GetMaskName 获取和谐过的客户端 IP 地址
	 *
	 */
	private function getMarkName($ip)
	{
		$username = $ip ?? "127.0.0.1";
		$uexp = explode(".", $username);
		if(count($uexp) >= 4) {
			$username = "{$uexp[0]}.{$uexp[1]}." . str_repeat("*", strlen($uexp[2])) . "." . str_repeat("*", strlen($uexp[3]));
		} else {
			$username = "Unknown";
		}
		return $username;
	}
	/**
	 *
	 *  IsBlackList 判断是否在黑名单
	 *
	 */
	private function isBlackList($key)
	{
		$blackList = $this->getBlackList();
		for($i = 0;$i < count($blackList);$i++) {
			if(stristr($key, $blackList[$i])) {
				return true;
			}
		}
		return false;
	}
	/**
	 *
	 *  GetBlackList 获取黑名单列表
	 *
	 */
	private function getBlackList()
	{
		$data = @file_get_contents(ROOT . "/blacklist.txt");
		$exp = explode("\n", $data);
		$result = [];
		for($i = 0;$i < count($exp);$i++) {
			$tmpData = trim($exp[$i]);
			if(!empty($tmpData)) {
				$result[] = $tmpData;
			}
		}
		return $result;
	}
	/**
	 *
	 *  SetUserNickname 设置用户的昵称
	 *
	 */
	private function setUserNickname($ip, $name,$id)
	{
		$data = $this->getUserNickData();
		$data[$id] = $name;                         //本地测试暂使用id
		$this->setUserNickData($data);
	}
	/**
	 *
	 *  SetUserNickData 将昵称数据写入到硬盘
	 *
	 */
	private function setUserNickData($data)
	{
		@file_put_contents(ROOT . "/username.json", json_encode($data));
	}
	
	/**
	 *
	 *  SetAdminIp 设置管理员的 IP 地址
	 *
	 */
	private function setAdminIp($ip,$id) //本地测试暂使用id
	{
		@file_put_contents(ROOT . "/admin.ip", $id);
	}
	/**
	 *
	 *  AddBlackList 增加新的黑名单关键字
	 *
	 */
	private function addBlackList($data)
	{
		$blackList = $this->getBlackList();
		$blackList[] = trim($data);
		$this->setBlackList($blackList);
	}
	/**
	 *
	 *  SetBlackList 将黑名单数据写入到硬盘
	 *
	 */
	private function setBlackList($data)
	{
		$result = "";
		for($i = 0;$i < count($data);$i++) {
			$result .= $data[$i] . "\n";
		}
		@file_put_contents(ROOT . "/blacklist.txt", $result);
	}
	/**
	 *
	 *  GetBannedIp 获取已经被封禁的 IP
	 *
	 */
	private function getBannedIp()
	{
		return $this->server->table->get(0, "banned_ips") ?? "";
	}
	/**
	 *
	 *  IsBanned 判断是否已被封禁
	 *
	 */
	private function isBanned($ip)
	{
	    if($ip === false){
	        return false;
	    }
		$bannedIp = $this->getBannedIp();
		return ($bannedIp && stristr($bannedIp, "{$ip};"));
	}
	/**
	 * 
	 * setBannedIp() 设置被封禁的ip列表
	 * 
	 * */
	 private function setBannedIp($ip){
	     $this->server->table->set(0, ["banned_ips"=>$ip]);
	 }
	 /**
	 *
	 *  BanIp 封禁指定 IP 地址
	 *
	 */
	 private function banIp($ip)
	{
		$bannedIp = $this->getBannedIp() . "{$ip};";
		$this->server->table->set(0, ["banned_ips" => $bannedIp]);
	}
	/**
	 *
	 *  UnbanIp 解封指定 IP 地址
	 *
	 */
	private function unbanIp($ip)
	{
		$bannedIp = str_replace("{$ip};", "", $this->getBannedIp());
		$this->setBannedIp($bannedIp);
	}
}