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


)))))))))
查看源：composer config -g -l

修改镜像源命令
composer config -g repo.packagist composer (镜像地址)
composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/

阿里云 Composer 全量镜像
镜像地址：https://mirrors.aliyun.com/composer/
官方地址：https://mirrors.aliyun.com/composer/index.html
说明：终于接上大厂水管了，还没来得急测，先更新，估计阿里云做的也不会差。
 
腾讯云 Composer 全量镜像
镜像地址：https://mirrors.cloud.tencent.com/composer/
官方地址：https://mirrors.cloud.tencent.com/composer
说明：若您使用腾讯云服务器，可以将源的域名从 mirrors.cloud.tencent.com 改为 mirrors.tencentyun.com，使用内网流量不占用公网流量，是不是非常良心。
 
华为 Composer 全量镜像
镜像地址：https://mirrors.huaweicloud.com/repository/php/
官方地址：https://mirrors.huaweicloud.com/
说明：华为 composer 镜像目前还不够完善，composer i 时会出现一些 bug ，而且同步速度也比较慢，好像并非是全量的。
 
Packagist / Composer 中国全量镜像
镜像地址：https://packagist.phpcomposer.com
官方地址：https://pkg.phpcomposer.com/
说明：Packagist 中国全量镜像是从 2014 年 9 月上线的，在安装和同步方面都比较完善，也一直是公益运营，但不知道目前这个镜像是否还是可用状态。
 
Composer / Packagist 中国全量镜像
镜像地址：https://php.cnpkg.org
官方地址：https://php.cnpkg.org/
说明：此 composer 镜像由安畅网络赞助，目前支持元数据、下载包全量代理，还是不错的，推荐使用。
 
Packagist / JP
镜像地址：https://packagist.jp
官方地址：https://packagist.jp
说明：这是日本开发者搭建的 composer 镜像，早上测了一下，感觉速度还不错。
 
Packagist Mirror
镜像地址：https://packagist.mirrors.sjtug.sjtu.edu.cn
官方地址：https://mirrors.sjtug.sjtu.edu.cn/packagist/
说明：上海交通大学提供的 composer 镜像，稳定、快速、现代的镜像服务，推荐使用。
 
Laravel China Composer 全量镜像
镜像地址：https://packagist.laravel-china.org
官方地址：https://learnku.com/laravel
说明：这个就不多了，国内 PHP 开发者使用量最多的 composer 镜像，同步速度快、稳定，推荐使用。