安装php-7.1 ，swoole

#### 编译安装
```text

1,下载：
[root@localhost swoole-src-4.5.11]# wget https://github.com/swoole/swoole-src/archive/refs/tags/v4.5.11.tar.gz

> 解压，进入解压目录  

[root@localhost swoole-src-4.5.11]# phpize && ./configure --enable-openssl --enable-http2 --with-php-config=/www/server/php/71/bin/php-config && make && sudo make install


3. 启用扩展
编译安装到系统成功后，需要在 php.ini 中加入一行 extension=swoole.so 来启用 Swoole 扩展


[root@localhost swoole-src-4.5.11]# php --ri swoole

```
###  修改保存最大连接数: vim /etc/security/limits.conf
```
* soft nofile 10000
* hard nofile 10000

然后重启服务器或重新登录即可生效
最大连接数，适当设置，提高并发数，max_connection 最大不得超过操作系统 ulimit -n 的值(增加服务器文件描述符的最大值)，否则会报一条警告信息，并重置为 ulimit -n 的值

ulimit -n
```

