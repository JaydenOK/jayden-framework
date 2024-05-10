#!/bin/bash
echo "开始安装rabbitmq-c-0.8.0......"
wget -c https://github.com/alanxz/rabbitmq-c/releases/download/v0.8.0/rabbitmq-c-0.8.0.tar.gz
if ! ls rabbitmq-c-0.8.0.tar.gz;then
    echo "无法访问rabbitmq-c-0.8.0.tar.gz: 没有那个文件或目录"
    exit 2
fi
tar zxf rabbitmq-c-0.8.0.tar.gz
cd rabbitmq-c-0.8.0
./configure --prefix=/usr/local/rabbitmq-c-0.8.0
make && make install && \
echo "rabbitmq-c-0.8.0.tar.gz安装完成."
cd 
sleep 2
echo "开始安装amqp-1.9.3......"
wget -c http://pecl.php.net/get/amqp-1.9.3.tgz
if ! ls amqp-1.9.3.tgz;then
    echo "无法访问amqp-1.9.3.tgz: 没有那个文件或目录"
    exit 2
fi
tar zxf amqp-1.9.3.tgz
cd amqp-1.9.3
/usr/local/php7.3/bin/phpize		
./configure --with-php-config=/usr/local/php7.3/bin/php-config --with-amqp --with-librabbitmq-dir=/usr/local/rabbitmq-c-0.8.0
make && make install && \
echo "amqp-1.9.3安装完成."