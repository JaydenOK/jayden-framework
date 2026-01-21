
## Jayden-Framework 支持多模块小型PHP框架  
PHP工具类库，Swoole常驻进程/异步任务/多消费队列进程/协程实例，rabbitmq使用，Auth2.0，RSA加密解密，JWT，validation参数过滤校验、DI模式等。  
同时是一个支持多模块小型PHP框架，如api、apiv2为模块目录  
（注意：一般目录下带有bootstrap.php文件的为独立于框架的用例模块） 


### 版本
```shell script
php > 7.1
```

#### 架构及目录
项目jayden-framework即为app应用根目录，命名空间根目录app:    

app架构遵循:应用-模块-控制器-方法结构  
```
jayden-framework/api/UserController.php  
jayden-framework(app) 应用目录  
api   模块      
command   命令行模块      
api - UserController  控制器    
api - UserController - add 添加方法
config 配置目录
core 系统核心目录
language  国际化语言目录
module   模块
utils 常用工具
third_party 第三方模块 (一般存放为直接下载的第三方项目)
vendor  第三方扩展目录 (通过composer下载的扩展)
docs  一些文档说明
```

####  下载安装，支持composer
```shell script
composer require jaydenok/jayden-framework
```

####  配置nginx
```shell script
nginx站点配置如: jayden.cc.conf
```
  
#### 操作 
```
action方法内部获取请求数据，使用$this->body或注入run($body)获取  
访问路由： 

HTTP:
http://jayden.cc/api/test/testOne?param1=1&param2=2
  
命令行：
php index.php "command/Smc/test" "param1=1&param2=2"
```

  
#### 其它目录为单个模块库 （不需要可去除）
```
模块跟目录下一个固定PSR-4 自动加载类，使用时引入此文件，功能：将当前根目录注册为自动加载的命名空间 module
require '../bootstrap.php';

如PHP实现雪花算法模块, 
测试生成分布式ID生成，访问: http://jayden.cc/php-snowflake/test.php
```  


#### 存在的独立功能模块
```text
PSR 注册命名空间   | autoloader/Autoloader.php
雪花算法 （分布式ID生成） | php-snowflake
DI容器  | di
php事件机制 | php-event
树的多级数组转换 （对象，节点方式）| tree
util工具类 XML，alias转盘抽奖算法， | util 
php-di容器 | vendor 
rakit 参数验证器 | rakit 
php工具类库，mysql实用查询等  
支持命名空间、多模块，控制器，方法小型PHP项目_  
utils php常用工具集合 (一般一个工具存放在一个文件夹)
-- autoloader 自动加载命名空间  
-- 数组与树型结构转换  
-- pdo操作mysql数据库，支持ORM  
```

#### 消息队列模块
基于 MySQL 存储的轻量级 PHP 消息队列系统，位于 `system/` 目录。

**功能特性：**
- 支持多消费者进程并行消费
- 失败自动重试（指数退避策略）
- 消息锁机制，确保消息不被重复消费
- 配置热加载，修改队列配置后自动生效
- 支持 Windows & Linux 环境
- 提供 Web 管理界面，可视化监控

**快速使用：**
```php
// 发送消息
MqManager::set('queueName', ['userId' => 123, 'action' => 'register']);

// 启动消费者
// php index.php system/mq/daemon
```

**CLI 命令：**
```bash
php index.php system/mq/daemon    # 后台启动
php index.php system/mq/stop      # 停止
php index.php system/mq/restart   # 重启
php index.php system/mq/status    # 查看状态
```

详细使用说明参见：[消息队列使用说明文档](system/消息队列使用说明文档.md)

#### docs_md 文档
```shell script
window与linux远程文件同步.sh
```

