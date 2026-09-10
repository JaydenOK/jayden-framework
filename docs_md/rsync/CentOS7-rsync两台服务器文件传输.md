# CentOS 7：两台服务器 rsync 文件传输（A → B）

本文说明在两台 CentOS 7 上，从 A（源）向 B（目标）同步文件的两种方式。

### 两种方式特点与怎么选


| 方式                     | 通道     | 特点                            | 适用场景                                  |
| ---------------------- | ------ | ----------------------------- | ------------------------------------- |
| **方式一：SSH**            | 22 端口  | 安全、配置简单，需免密或交互密码              | 临时拷贝、已有 SSH、不想开 873；公网优先用此方式          |
| **方式二：rsync 模块（守护进程）** | 873 端口 | B 预先配置模块与目录，A 按模块名推送；适合内网批量同步 | B 要固定「模块名 → 目录」、多台机器推送；公网使用须严格限制来源 IP |


同步目录：


| 端     | 路径                       |
| ----- | ------------------------ |
| A（源）  | `/www/wwwroot/basic.vm/` |
| B（目标） | `/www/wwwroot/basic.vm/` |


---

## 方式一：通过 SSH 传输

走 SSH（22 端口），不配 rsync 模块。

### 1. 安装（A、B）

```bash
sudo yum install -y rsync openssh-clients openssh-server
sudo systemctl enable --now sshd
```

B 放行 SSH：

```bash
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --reload
```

> CentOS 7 已 EOL，若 `yum` 源失效，需先更换 vault 镜像后再安装。

### 2. A 生成密钥

```bash
ssh-keygen -t rsa -b 4096 -N "" -f ~/.ssh/id_rsa
```


| 参数                 | 含义                    |
| ------------------ | --------------------- |
| `-t rsa`           | RSA 类型                |
| `-b 4096`          | 密钥长度                  |
| `-N ""`            | 私钥无口令，便于定时任务          |
| `-f ~/.ssh/id_rsa` | 私钥路径；公钥为 `id_rsa.pub` |


生成结果：

- **私钥** `~/.ssh/id_rsa`：仅留在 A，禁止外传
- **公钥** `~/.ssh/id_rsa.pub`：需要放到 B 上

### 3. 公钥放到 B（二选一）

**ssh-copy-id（A 能密码登录 B）：**

```bash
ssh-copy-id root@B的IP
```

**手工配置：**

在 A 执行 `cat ~/.ssh/id_rsa.pub`，把整行公钥发给 B。  
在 B：

```bash
mkdir -p /root/.ssh
chmod 700 /root/.ssh
echo '这里粘贴整行公钥' >> /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
```

只传公钥，不要传私钥 `id_rsa`。

### 4. 验证

```bash
ssh root@B的IP "hostname"
```

- 直接打印 B 的主机名、且不再要密码 → 配置成功
- 仍要密码或报错 → 检查公钥是否写入、权限是否为 `700/600`、网络与防火墙

### 5. 传输

```bash
rsync -avzP -e ssh /www/wwwroot/basic.vm/ root@B的IP:/www/wwwroot/basic.vm/
```

### 6. 定时同步（可选，在 A 上）

```bash
crontab -e
```

```cron
0 2 * * * /usr/bin/rsync -az -e ssh /www/wwwroot/basic.vm/ root@B的IP:/www/wwwroot/basic.vm/ >> /var/log/rsync-ssh.log 2>&1
```

### 7. 方式一操作清单

1. A、B 安装 `rsync`、`openssh`，B 启动 `sshd` 并放行 22
2. A 执行 `ssh-keygen` 生成密钥
3. 将 A 的 `id_rsa.pub` 写入 B 的 `authorized_keys`（`ssh-copy-id` 或手工）
4. A 上 `ssh root@B的IP "hostname"` 验证免密
5. A 上执行 `rsync` 命令同步

### 8. 方式一常见问题


| 现象                 | 排查方向                                     |
| ------------------ | ---------------------------------------- |
| Permission denied  | B 目标目录权限不足，或登录用户不对                       |
| Connection refused | B 未开 sshd，或防火墙/安全组未放行                    |
| 仍提示输入密码            | 公钥未写入、写错用户、`.ssh`/`authorized_keys` 权限不对 |
| 传输慢                | 优先走内网 IP；大文件可依赖 `-P` 断点续传                |


### 9. 安全注意（SSH 方式）

- 私钥 `id_rsa` 只留在 A，不要拷贝到 B，也不要通过聊天工具发送  
- 公钥可以公开分发到需要信任 A 的服务器  
- 生产环境建议使用专用业务账号，并限制该账号可写目录

---

## 方式二：rsync 模块（守护进程）

流程：在 **B** 配置 `rsyncd` 模块与目录并启动服务 → 在 **A** 用模块名推送文件。

需要「B 先配好模块、目录，A 按模块同步」时用本方式。

### 1. 安装（A、B 都执行）

```bash
sudo yum install -y rsync
```

### 2. 在 B 上准备同步目录

写文件的用户是 `rsyncd.conf` 里的 `uid`/`gid`（与 `auth users` 登录名无关）。本例用 `www`：

```bash
# 若无 www 用户/组
groupadd www 2>/dev/null || true
id www >/dev/null 2>&1 || useradd -g www -s /sbin/nologin -d /www/wwwroot www

mkdir -p /www/wwwroot/basic.vm
# 模块 path 这一层目录本身也必须属于 www（只改里面的文件不够）
chown -R www:www /www/wwwroot/basic.vm
chmod 755 /www /www/wwwroot
chmod -R u+rwX /www/wwwroot/basic.vm
```

改完 `uid`/`gid` 后必须 `systemctl restart rsyncd`，否则进程仍按旧用户写盘。

### 3. 在 B 上配置 rsync 模块 `/etc/rsyncd.conf`

```bash
sudo vi /etc/rsyncd.conf
```

示例配置：

```ini
# 全局：写文件的系统用户（必须存在，且对 path 可写）
uid = www
gid = www
use chroot = no
reverse lookup = no
max connections = 10
pid file = /var/run/rsyncd.pid
lock file = /var/run/rsync.lock
log file = /var/log/rsyncd.log

# 只允许内网 A 访问（按实际改）
hosts allow = 192.168.168.0/24
hosts deny = *

# ---------- 模块：名称自定，A 端用此名 ----------
[basic.vm]
path = /www/wwwroot/basic.vm
comment = basic.vm sync directory
read only = no
write only = no
list = yes
auth users = root
secrets file = /etc/rsyncd.secrets
```

说明：


| 项                | 含义                                    |
| ---------------- | ------------------------------------- |
| `[basic.vm]`     | **模块名**，A 同步时写 `::basic.vm`           |
| `path`           | 该模块对应的真实目录                            |
| `read only = no` | 允许 A 向 B 推送（写入）                       |
| `uid` / `gid`    | 在 B 上写文件的系统用户，与登录名无关                  |
| `auth users`     | 认证登录名（可与 uid 不同，例如登录 `root`、落盘 `www`） |
| `secrets file`   | 密码文件路径                                |
| `hosts allow`    | 允许访问的 IP/网段                           |


### 4. 在 B 上配置密码文件

```bash
sudo vi /etc/rsyncd.secrets
```

格式：`用户名:密码`（每行一个），例如：

```text
root:666666
```

权限必须足够严格，否则 rsyncd 可能拒绝启动：

```bash
sudo chmod 600 /etc/rsyncd.secrets
sudo chown root:root /etc/rsyncd.secrets
```

### 5. 在 B 上启动 rsync 守护进程

```bash
sudo systemctl enable --now rsyncd
sudo systemctl status rsyncd
```

若没有 unit，可用守护方式启动：

```bash
sudo rsync --daemon --config=/etc/rsyncd.conf
```

确认 873 在监听：

```bash
ss -lntp | grep 873
# 或
netstat -lntp | grep 873
```

### 6. 在 B 上放行防火墙 / 安全组

```bash
sudo firewall-cmd --permanent --add-port=873/tcp
sudo firewall-cmd --reload
```

云服务器安全组也要放行 **873/tcp**（建议仅对 A 的 IP 开放）。

### 7. 处理 SELinux（B 上必查）

CentOS 7 默认 **Enforcing** 时，rsyncd 写 `/www/wwwroot/basic.vm` 会被拦，表现为认证已过但仍报：

```text
rsync: failed to set times on "." (in basic.vm): Permission denied (13)
rsync: recv_generator: mkdir "assets" (in basic.vm) failed: Permission denied (13)
rsync: mkstemp ".xxx" (in basic.vm) failed: Permission denied (13)
```

`sudo -u www touch` 成功也不能说明 rsyncd 能写。本环境实测：关闭 SELinux 后同步成功。采用 **开机永久关闭**：

```bash
getenforce
# Enforcing = 正在拦截；Disabled = 已关闭

sed -i 's/^SELINUX=enforcing/SELINUX=disabled/' /etc/selinux/config
grep ^SELINUX= /etc/selinux/config    # 应为 SELINUX=disabled
reboot
```

重启后再确认：

```bash
getenforce    # 应为 Disabled
```

然后回 A 执行 `./rsync-basic.vm.sh`。


### 8. 在 A 上准备密码文件（免交互）

```bash
printf '666666\n' > ~/.rsync.pass
chmod 600 ~/.rsync.pass
```

文件里 **只写密码** `666666`，不要写成 `root:666666`。root 执行时实际路径是 `/root/.rsync.pass`。

### 9. 在 A 上按模块同步到 B

单次命令：

```bash
rsync -avzP --password-file=~/.rsync.pass \
  --exclude='logs/*' --exclude='.git/' --exclude='.idea/' --exclude='.user.ini' \
  /www/wwwroot/basic.vm/ root@192.168.168.201::basic.vm/
```

也可写成脚本，之后在 A 上执行该脚本即可同步。把下面内容保存为 `/root/rsync-basic.vm.sh`：

```bash
#!/bin/bash
# A -> B：同步 /www/wwwroot/basic.vm/ 到 B 的 basic.vm 模块
B_HOST="192.168.168.201"

rsync -avzP --password-file=$HOME/.rsync.pass \
  --exclude='logs/*' \
  --exclude='.git/' \
  --exclude='.idea/' \
  --exclude='.user.ini' \
  /www/wwwroot/basic.vm/ \
  root@${B_HOST}::basic.vm/
```

在 A 上执行：

```bash
chmod +x /root/rsync-basic.vm.sh
/root/rsync-basic.vm.sh
```

干跑预览（不真正写入）：

```bash
rsync -avzn --password-file=~/.rsync.pass \
  --exclude='logs/*' --exclude='.git/' --exclude='.idea/' --exclude='.user.ini' \
  /www/wwwroot/basic.vm/ root@192.168.168.201::basic.vm/
```

查看 B 上有哪些模块（需 `list = yes`）：

```bash
rsync --password-file=~/.rsync.pass root@192.168.168.201::
```

### 10. A 上定时同步（可选）

```cron
0 2 * * * /root/rsync-basic.vm.sh >> /var/log/rsync-basic.vm.log 2>&1
```

### 11. 方式二操作清单

1. B：创建目录并设好属主权限（`www:www`）
2. B：写 `/etc/rsyncd.conf`（`uid/gid = www`、`use chroot = no`、`auth users = root`、模块名、`path`）
3. B：写 `/etc/rsyncd.secrets` 为 `root:666666`，权限 `600`
4. B：启动 `rsyncd`，放行 **873**
5. B：处理 SELinux（开机永久关闭，改 `/etc/selinux/config` 为 `SELINUX=disabled` 后 `reboot`）
6. A：写密码文件，内容仅为 `666666`，`chmod 600`
7. A：执行 `/root/rsync-basic.vm.sh` 或 `rsync ... root@B::basic.vm/`

### 12. 方式二常见问题


| 现象                     | 排查                                               |
| ---------------------- | ------------------------------------------------ |
| Connection refused     | B 未启动 rsyncd，或 873 未放行                           |
| auth failed            | 用户名/密码不一致，或 secrets 权限不是 600                     |
| Permission denied (13) | **优先查 SELinux**（见第 7 节）。本环境：开机永久关闭后同步成功 |
| @ERROR: Unknown module | 模块名写错，或不在 `rsyncd.conf` 中                        |
| 被 hosts deny           | 检查 `hosts allow` 是否包含 A 的 IP                     |


出现 `failed to set times on "."`、`mkdir ... Permission denied (13)` 时，认证已过。**先按第 7 节开机永久关闭 SELinux**（改配置后 `reboot`，`getenforce` 应为 `Disabled`）。

若已 Disabled 仍失败，再查目录属主和 chroot：

```bash
grep -nE 'uid|gid|use chroot|path|auth' /etc/rsyncd.conf
cat -A /etc/rsyncd.conf | head -40    # 行尾不要有 ^M
id www
ls -ld /www /www/wwwroot /www/wwwroot/basic.vm
lsattr -d /www/wwwroot/basic.vm       # 不要有 i（不可变）
systemctl restart rsyncd
ps aux | grep '[r]sync'
```

`/etc/rsyncd.conf` 全局至少：

```ini
uid = www
gid = www
use chroot = no
```

仍失败可临时用 `uid = root` / `gid = root` 验证，或检查 systemd：

```bash
systemctl cat rsyncd
systemctl show rsyncd -p ProtectSystem -p ProtectHome -p ReadWritePaths
```

### 13. 安全建议（模块方式）

- 优先仅内网使用；公网务必限制 `hosts allow` + 安全组  
- 使用强密码，secrets 文件权限保持 `600`  
- 不需要列出模块时设 `list = no`  
- 只收不发的备份场景可设 `write only = yes`（按需求）

---

## 附录：rsync 常用参数与路径


| 参数                | 含义              |
| ----------------- | --------------- |
| `-a`              | 归档（权限、时间等）      |
| `-v`              | 详细输出            |
| `-z`              | 压缩              |
| `-P`              | 进度 + 断点续传       |
| `--delete`        | 删除目标多余文件（镜像，慎用） |
| `--exclude`       | 排除              |
| `-n`              | 干跑              |
| `--password-file` | 模块方式免交互密码文件     |


路径末尾 `/`：


| 写法                       | 效果                  |
| ------------------------ | ------------------- |
| `/www/wwwroot/basic.vm/` | 同步目录**内容**到目标       |
| `/www/wwwroot/basic.vm`  | 在目标下再建一层 `basic.vm` |


