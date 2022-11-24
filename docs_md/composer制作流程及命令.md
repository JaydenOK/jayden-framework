## 制作composer包流程：

已加入composer包 可使用如下命令安装:  
composer require jaydenman/jayden-framework

### 到 https://packagist.org/packages 发布composer包       

git clone git@github.com:JaydenOK/jayden-framework.git  

cd myframework  

composer init  （使用composer工具删除composer.json）  
或直接编辑composer.json文件（规定版本，自动加载文件及其他支持扩展）  
过滤.gitignore  

/vendor/  
composer.lock  

提交代码到github
git add .  
git commit -m "jayden-framework框架"  
git push  

添加版本  号
git tag v1.0.1      #   版本号  
git tag -l          #  查看有哪些版本号  
git push origin --tags   #   将所有版本号push到远程仓库  

8，访问 packagist :  
https://packagist.org/packages/submit  

添加 package包地址，及git地址 git@github.com:JaydenMan/myframework.git
check检查，再submit提交即可  


实现 Packagist网站 package自动更新  
设置git仓库webhook地址: https://packagist.org/api/bitbucket?username=jaydenman&apiToken=API_TOKEN  
API_TOKEN 在Packagist 网站的个人中心找到: https://packagist.org/profile/

GitHub的仓库



##################################################  
测试在其他项目拉取包：  
composer require jaydenman/jayden-framework  
##################################################  

1，composer 移除依赖 操作
如按照了第三方亚马逊包 composer require jlevers/selling-partner-api  
但里边包含了亚马逊官方包，并与当前安装的包无关，并且文件多，需要移除  
找到composer.lock，  jlevers/selling-partner-api包信息，删除jlevers/selling-partner-api的require依赖行 "aws/aws-sdk-php" 信息
执行命令> composer remove aws/aws-sdk-php  即可



)))))))))
composer 忽略依赖
提示我的PHP 7版本太高，不符合composer.json需要的版本，但是在PHP 7下应该也是可以运行的，composer可以设置忽略版本匹配，命令是：
composer install -vvv --profile --ignore-platform-reqs
composer update -vvv --profile --ignore-platform-reqs


)))))))
下载第三方代码，在autoloader中手动注册命名空间：
```php
$loader = include 'vendor/autoload.php';
$loader->setPsr4('jaydenok\\', 'third_party/src/');
$loader->addClassMap([
    'jaydenok\\module' => 'third_party/src/serv.php'
]);
```