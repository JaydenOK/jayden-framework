服务端部署流程：
第一步：安装rsync

[root@backup ~]# yum install rsync -y 
第二步：启动、开机自启rsync

[root@backup ~]# systemctl start rsyncd
[root@backup ~]# systemctl enable rsyncd
第三步：修改rsync配置文件

[root@backup ~]# vim /etc/rsyncd.conf
#虚拟用户
uid = rsync
#虚拟用户组
gid = rsync
#端口号
port = 873
#伪装root权限
fake super = yes
#安全相关
usechroot = no
#最大链接数
maxconnections = 200
#超时时间
timeout = 300
#进程对应的进程号文件
pidfile = /var/run/rsyncd.pid
#锁文件
lockfile = /var/run/rsync.lock
#日志文件，显示出错信息
logfile = /var/log/rsyncd.log
#忽略错误程序
ignore errors
#是否只读
readonly = false
#是否可以列表
list = false
#准许访问rsync服务器的客户ip范围
hostsallow = 0.0.0.0/24
#禁止访问rsync服务器的客户ip范围
hostsdeny = 0.0.0.0/24
#不存在的用户；只用于认证
authusers = rsync_backup
#设置进行连接认证的密匙文件
secretsfile = /etc/rsyncd.passwd
 
#模块名称
[backup]
#模块对应的位置（路径）
path = /backup
#连接信息
comment = “backup dir by xu”
第四步：创建用户密码文件，修改文件权限

[root@backup ~]# echo ’rsync_backup:123456‘ >/etc/rsyncd.passwd
[root@backup ~]# chmod 600 /etc/rsyncd.passwd
第五步：创建虚拟用户、模块目录，修改目录权限为rsync

[root@backup ~]# useradd rsync -M -s /sbin/nologin 
[root@backup ~]# mkdir /backup
[root@backup ~]# chown rsync.rsync /backup
第六步：重新加载配置，关闭、开机不启防火墙 + selinux

[root@backup ~]# systemctl reload rsyncd
[root@backup ~]# systemctl stop firewalld
[root@backup ~]# systemctl disable firewalld
[root@backup ~]# setenforce 0
[root@backup ~]# sed -i 's#enforcing#disable#g' /etc/selinux/config
客户端部署流程：
第一步：安装rsync服务

[root@web01~]# yum install rsyncd -y 
第二步：创建用户密码文件，修改文件权限

[root@web01~]# echo ’123456‘ >/etc/rsyncd.passwd
[root@web01~]# chmod 600 /etc/rsyncd.passwd
第三步：发送测试文件到服务端

[root@web01~]# rsync -avz /etc/hosts rsync_backup@172.16.1.41::backup --password-file=/etc/rsyncd.passwd
sending incremental file list
hosts
 
sent 245 bytes  received 43 bytes  576.00 bytes/sec
total size is 405  speedup is 1.41
 
# 格式：rsync 参数 数据源  服务端配置的用户名@主机名::模块 --password-file=密码文件路径


############### 不包括中文其它信息
port = 873								# 指定rsync端口。默认873
uid = root								# rsync服务的运行用户，默认是nobody，文件传输成功后属主将是这个uid
gid = root								# rsync服务的运行组，默认是nobody，文件传输成功后属组将是这个gid
use chroot = no							# rsync daemon在传输前是否切换到指定的path目录下，并将其监禁在内
max connections = 200					# 指定最大连接数量，0表示没有限制
timeout = 300							# 确保rsync服务器不会永远等待一个崩溃的客户端，0表示永远等待
pid file = /var/run/rsyncd.pid			# 指定rsync daemon的pid文件
lock file = /var/run/rsync.lock			# 指定锁文件
log file = /var/log/rsyncd.log			# 指定rsync的日志文件，而不把日志发送给syslog
dont compress = *.gz *.tgz *.zip *.z *.Z *.rpm *.deb *.bz2  # 指定哪些文件不用进行压缩传输


[mysql_pool_concurrency_server_web]
path = /root/mysql_pool_concurrency_server_web
ignore errors
read only = false
write only = false
list = false
auth users = root
secrets file = /etc/rsyncd.passwd



###################
开放防火墙 873端口


#### 查看系统日志
[root@localhost ~]# systemctl status rsyncd

[root@localhost ~]# less /var/log/rsyncd.log


二、检查用户名和密码配置格式是否正确。

接收端密码文件格式是账号:密码，比如 rsync:123456

发送端密码文件格式是密码，比如 123456

三、检查密码文件的权限，必须是600

四、检查 rsyncd.conf 文件配置，是否有重名的模块以及模块配置是否正确，比如模块中是否正确设置了密码文件等，不同的 rsync 版本的具体配置可能不太一样，这点要注意。