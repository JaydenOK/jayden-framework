# utils  
utils php常用工具集合 (一般一个工具存放在一个文件夹)  
-- autoloader 自动加载命名空间  
-- 数组与树型结构转换  
-- pdo操作mysql数据库，支持ORM  

项目john-utils即为应用目录:

app
架构遵循:应用-模块-控制器-方法结构
john-utils/test/Validate.php
john-utils 应用目录
test   模块
Validate  控制器
run  方法名
## 
方法入口，获取请求数据可从Request::getBody(), 或注入run($body)获取
访问路由：
http://utils.cc/index.php?route=test/validate/run




