# swoole-
swoole多人聊天室，功能有，设置名称，管理员登录，禁言，发送表情等。


安装教程
1.环境准备：
OS：Linux x86/x64（Windows 暂未测试）。
PHP：7.0 及以上。
Extension：Swoole,Redis。


2.进入目录，编辑 server.php：
vim server.php
2.1:根据里面的提示修改，改完之后保存，然后运行 server.php
php server.php
推荐使用 screen 或者 nohup 让服务器端在后台运行，断开 SSH 之后也不会关闭。


3.配置网页前端：
编辑 index.html，找到大约 35 行左右的 ws_hostname 这里，修改为你的网站域名。
如果你网站是 https 的，那么地址里就要用 wss:// 否则会被浏览器拦截请求，如果是普通 http 就用 ws:// 。
浏览器打开你的网站查看效果。
