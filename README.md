
#### john-utils框架 

记录日常使用的php工具类库，mysql高阶查询等  
支持命名空间、多模块，控制器，方法小型PHP项目_  
utils php常用工具集合 (一般一个工具存放在一个文件夹)

```
-- autoloader 自动加载命名空间  
-- 数组与树型结构转换  
-- pdo操作mysql数据库，支持ORM  
```  

项目john-utils即为app应用目录:  

app架构遵循:应用-模块-控制器-方法结构
```
john-utils/api/UserController.php  
john-utils(app) 应用目录  
api   模块      
UserController  控制器    
add  方法名
```
  
## 
action方法内部获取请求数据，使用$this->body或注入run($body)获取  
访问路由：  
```
http://utils.cc/index.php?route=api/user/add  
```




