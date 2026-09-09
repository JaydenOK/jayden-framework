# CentOS 7 切换阿里云 Yum 源操作文档

适用于 CentOS 7 执行 `yum install` / `yum makecache` 时出现 `Could not resolve host: mirrorlist.centos.org`，需要改成阿里云归档镜像源的场景。

本文以「命令做备份和安装、源文件用编辑器打开后复制粘贴」的方式操作。不熟悉 `vi` 时可用 `nano`。

---

## 1. 原因说明

CentOS 7 已于 **2024-06-30** 结束生命周期（EOL）。官方已下线 `mirrorlist.centos.org`，yum 拿不到仓库地址就会失败。

典型报错：

```text
Could not retrieve mirrorlist http://mirrorlist.centos.org/?release=7&arch=x86_64&repo=os&infra=stock error was
14: curl#6 - "Could not resolve host: mirrorlist.centos.org; 未知的错误"

Cannot find a valid baseurl for repo: base/7/x86_64
```

`yum install rsync` 时同样会失败，例如：

```text
[root@localhost ~]# yum install rsync
已加载插件：fastestmirror
Loading mirror speeds from cached hostfile
Could not retrieve mirrorlist http://mirrorlist.centos.org/?release=7&arch=x86_64&repo=os&infra=stock error was
14: curl#6 - "Could not resolve host: mirrorlist.centos.org; 未知的错误"
```

处理方式：把 yum 源改到阿里云 **centos-vault / 7.9.2009**（最后一版归档）。

注意：

- 不要下载阿里云官网上的 `Centos-7.repo`，那份仍是旧路径，会 404。
- Vault 只有停更前的旧包，**没有新的安全补丁**。长期应迁移到 Rocky Linux / AlmaLinux / RHEL 8 或 9。

---

## 2. 操作前准备

以 `root` 执行。

### 2.1 备份原有源

```bash
mkdir -p /etc/yum.repos.d/backup
cp -a /etc/yum.repos.d/*.repo /etc/yum.repos.d/backup/
```

### 2.2 查看现有源文件

```bash
ls /etc/yum.repos.d/
```

后面先改 `CentOS-Base.repo`，再执行第 4 节的 yum。只有出现 SCLo 报错时，才按第 5 节处理 `CentOS-SCLo-scl-rh.repo`、`CentOS-SCLo-scl.repo`。

---

## 3. 改主源 CentOS-Base.repo

打开文件：

```bash
vi /etc/yum.repos.d/CentOS-Base.repo
```

操作：按 `i` 进入编辑 → 删掉原有全部内容 → 粘贴下面内容 → `Esc` → 输入 `:wq` 回车保存。

不熟 `vi` 可用：

```bash
nano /etc/yum.repos.d/CentOS-Base.repo
```

粘贴后 `Ctrl+O` 回车保存，`Ctrl+X` 退出。

**粘贴内容：**

```ini
[base]
name=CentOS-7.9.2009 - Base - mirrors.aliyun.com
baseurl=https://mirrors.aliyun.com/centos-vault/7.9.2009/os/$basearch/
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7

[updates]
name=CentOS-7.9.2009 - Updates - mirrors.aliyun.com
baseurl=https://mirrors.aliyun.com/centos-vault/7.9.2009/updates/$basearch/
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7

[extras]
name=CentOS-7.9.2009 - Extras - mirrors.aliyun.com
baseurl=https://mirrors.aliyun.com/centos-vault/7.9.2009/extras/$basearch/
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7

[centosplus]
name=CentOS-7.9.2009 - Plus - mirrors.aliyun.com
baseurl=https://mirrors.aliyun.com/centos-vault/7.9.2009/centosplus/$basearch/
gpgcheck=1
enabled=0
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7
```

其它官方源（有则改，没有可跳过），例如：

- `CentOS-CR.repo`
- `CentOS-fasttrack.repo`
- `CentOS-Media.repo`
- `CentOS-Sources.repo`

分别打开，把每个仓库段里的 `enabled=1` 改成 `enabled=0`。有 `mirrorlist=` 的行前面加 `#` 注释掉。

`CentOS-Base.repo` 不要改 `enabled`，保持上面粘贴的内容即可。

保存后先执行第 4 节，不要先改 SCLo 源。

---

## 4. 清缓存并安装 wget

```bash
yum clean all
yum makecache
yum install -y wget
```

`yum makecache` 成功时应能拉到 `base`、`updates`、`extras` 的元数据，随后即可安装。

若出现下面这类 **SCLo** 报错，再去第 5 节关掉对应源，改完后回到本节重新执行上面三条命令：

```text
Could not retrieve mirrorlist http://mirrorlist.centos.org?arch=x86_64&release=7&repo=sclo-rh
Cannot find a valid baseurl for repo: centos-sclo-rh/x86_64
```

若仍报某个其它 repo 找不到 `baseurl`，执行：

```bash
grep -n mirrorlist /etc/yum.repos.d/*.repo
```

对仍含 `mirrorlist.centos.org` 的文件按第 5 节同样方式关掉：把对应仓库段设为 `enabled=0`，再回到本节重试 yum。

---

## 5. 处理 SCLo 源（第 4 步出现 SCLo 报错时再处理）

第 4 步执行 `yum makecache` 若报 `centos-sclo-rh` / `mirrorlist.centos.org` 且 `repo=sclo-rh`，说明 **SCLo**（Software Collections）源仍在访问已下线地址，与 Base 源无关。没有该报错则跳过本节。

先确认文件：

```bash
ls /etc/yum.repos.d/
grep -n mirrorlist /etc/yum.repos.d/*.repo
```

通常会看到：

- `/etc/yum.repos.d/CentOS-SCLo-scl-rh.repo`
- `/etc/yum.repos.d/CentOS-SCLo-scl.repo`

有哪个改哪个。关掉即可，不必指向归档地址。

打开：

```bash
vi /etc/yum.repos.d/CentOS-SCLo-scl-rh.repo
```

删掉原内容，粘贴：

```ini
[centos-sclo-rh]
name=CentOS-7 - SCLo rh
enabled=0

[centos-sclo-rh-test]
name=CentOS-7 - SCLo rh Testing
enabled=0

[centos-sclo-rh-source]
name=CentOS-7 - SCLo rh Sources
enabled=0

[centos-sclo-rh-debuginfo]
name=CentOS-7 - SCLo rh Debuginfo
enabled=0
```

再打开：

```bash
vi /etc/yum.repos.d/CentOS-SCLo-scl.repo
```

删掉原内容，粘贴：

```ini
[centos-sclo-sclo]
name=CentOS-7 - SCLo sclo
enabled=0

[centos-sclo-sclo-testing]
name=CentOS-7 - SCLo sclo Testing
enabled=0

[centos-sclo-sclo-source]
name=CentOS-7 - SCLo sclo Sources
enabled=0

[centos-sclo-sclo-debuginfo]
name=CentOS-7 - SCLo sclo Debuginfo
enabled=0
```

保存后回到第 4 节，重新执行 `yum clean all`、`yum makecache`、`yum install -y wget`。

---

## 6. 操作清单（只装 wget）

1. 备份 `/etc/yum.repos.d/*.repo`
2. 用编辑器打开 `CentOS-Base.repo`，粘贴第 3 节内容并保存
3. 执行第 4 节：`yum clean all` → `yum makecache` → `yum install -y wget`
4. 若出现 SCLo 报错，按第 5 节关掉 SCLo 源，再回到第 4 节重试 yum

---

## 7. 注意事项

1. yum 报错里提示的 `--disablerepo`、`skip_if_unavailable` 不能解决根因，默认 `base` / `sclo` 源已经失效，必须改文件或关闭对应仓库。
2. 改源后只能安装归档里的旧包，不能当作继续获得官方安全更新。
3. 生产环境建议规划迁移到仍在维护的发行版。
