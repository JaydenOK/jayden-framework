## 制作composer包流程：

已加入composer包 可使用如下命令安装:  
composer require jaydenman/myframework   

### 到 https://packagist.org/packages 发布composer包       

git clone git@github.com:Jcai12321/myframework.git  

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
composer require jaydenman/myframework   
##################################################  
