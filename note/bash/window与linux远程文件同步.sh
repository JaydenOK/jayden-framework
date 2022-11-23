1,查看rsycn版本 rysnc --version, centos安装rsync服务端: yum -y install rsync
2, 配置 rsync: vim  /etc/rsyncd.conf

# 进行同步的用户
uid = root
gid = root
port = 873
# 传输文件以前首先chroot到path参数所指定的目录下
use chroot = yes
read only = on
list = no
max connections = 4
#pidfile = /var/run/rsyncd.pid
lock file=/var/run/rsyncd.lock
log file = /var/log/rsyncd.log
# motd file = /etc/rsyncd/rsyncd.motd
exclude = lost+found/
transfer logging = yes
timeout = 900
ignore nonreadable = yes
dont compress = *.gz *.tgz *.zip *.z *.Z *.rpm *.deb *.bz2
#hosts allow = 192.168.0.110
#hosts deny = 172.25.0.0/24


#认证模块： 认证用户及密码：/etc/rsyncd.pass: root:9d8c6fc9a53e9672d9c798e237f5386f, 创建目录:mkdir /root/coroutine-mysql-pool-task
[coroutine_task]
path = /root/coroutine-mysql-pool-task
ignore errors
read only = no
auth users = root
secrets file = /etc/rsyncd.pass

3,启动rsync服务:  (重启: systemctl restart rsyncd)
/usr/bin/rsync  --daemon

4, 查看监听端口(防火墙开放873端口): netstat -lntup |grep rsync, centos7 防火墙添加端口: firewall-cmd --zone=public --add-port=873/tcp --permanent

5,设置开机自启动:
echo "/usr/bin/rsync --deamon" >> /etc/rc.local

######### 安装rsync客户端（windows）
1,下载: cwRsync_5.4.1  , https://www.xiazaiba.com/html/4326.html
2,安装目录下, 新增pass.txt, 内容为上面的/etc/rsyncd.pass密码:9d8c6fc9a53e9672d9c798e237f5386f
3,执行同步window文件到远程服务器(认证密码,模块,源目录):
D:/www/rsync/cwRsync_5.4/rsync.exe -avzP  --port=873 --password-file=/cygdrive/D/www/rsync/cwRsync_5.4/pass.txt --exclude=logs/ --exclude=.git/ --exclude=.idea/ /cygdrive/D/www/sw-www/coroutine-mysql-pool-task/ root@192.168.92.208::coroutine_task



####### windows 脚本写成bat文件，方便执行
@echo off

::::::::::::::  stop
echo Start Sync ...

D:/www/rsync/cwRsync_5.4/rsync.exe -avzP  --port=873 --password-file=/cygdrive/D/www/rsync/cwRsync_5.4/pass.txt --exclude=logs/ --exclude=.git/ --exclude=.idea/ /cygdrive/D/www/sw-www/coroutine-mysql-pool-task/ root@192.168.92.208::coroutine_task

echo Success...
:: 延时
choice /t 1 /d y /n >nul
::pause
exit