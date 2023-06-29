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

