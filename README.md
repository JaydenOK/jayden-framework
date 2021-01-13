
#### Jayden-Framework 框架 

项目Jayden-Framework即为app应用目录:  

app架构遵循:应用-模块-控制器-方法结构  
```
john-utils/api/UserController.php  
john-utils(app) 应用目录  
api   模块      
api - UserController  控制器    
api - UserController - add 添加方法
add  方法名
config 配置目录
core 系统核心目录
language  言语目录
module   模块
vendor  第三方扩展目录
```
  
## 
action方法内部获取请求数据，使用$this->body或注入run($body)获取  
访问路由：  
```
http://jayden.cc/index.php?route=api/user/add  
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


