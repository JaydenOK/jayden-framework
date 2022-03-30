
## Jayden-Framework 框架  

`
（注意：一般目录下带有bootstrap.php文件的为独立于框架的用例模块）  
php version >7.3
`

项目jayden-framework即为app应用根目录，命名空间根目录app:    

app架构遵循:应用-模块-控制器-方法结构  
```
jayden-framework/api/UserController.php  
jayden-framework(app) 应用目录  
api   模块      
command   命令行模块      
api - UserController  控制器    
api - UserController - add 添加方法
add  方法名
config 配置目录
core 系统核心目录
language  言语目录
module   模块
third_party 第三方模块 (一般存放为直接下载的第三方项目)
vendor  第三方扩展目录 (通过composer下载的扩展)
```
  
## 
action方法内部获取请求数据，使用$this->body或注入run($body)获取  
访问路由：  
```
HTTP:
http://jayden.cc/index.php?r=api/user/add
  
命令行：
php index.php "r=command/Smc/start&param1=1&param2=2"
```

##########################################################  
  其它目录为单个模块库  （换行加2个空格）
##########################################################  

记录日常使用的php工具类库，mysql高阶查询等  
支持命名空间、多模块，控制器，方法小型PHP项目_  
utils php常用工具集合 (一般一个工具存放在一个文件夹)  

```
-- autoloader 自动加载命名空间  
-- 数组与树型结构转换  
-- pdo操作mysql数据库，支持ORM  
```  

```$xslt
如 php-event 模块库：
模块跟目录下一个固定PSR-4 自动加载类，使用时引入此文件，功能：将当前根目录注册为自动加载的命名空间 module
require '../bootstrap.php';
```

存在的独立功能模块
```text
PSR 注册命名空间   | autoloader/Autoloader.php
雪花算法 （分布式ID生成） | php-snowflake
DI容器  | di
php事件机制 | php-event
树的多级数组转换 （对象，节点方式）| tree
util工具类 XML，alias转盘抽奖算法， | util 
php-di容器 | vendor 
rakit 参数验证器 | rakit 
```

 

