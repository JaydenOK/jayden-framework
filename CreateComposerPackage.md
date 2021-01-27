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

提交到github

git tag v1.0  (并发布)  
git push origin --tags  
git add .  
git commit -m "小型php框架"  
git push  

8，访问 packagist 添加 package包地址，及git地址git@github.com:Jcai12321/myframework.git  
check检查，再submit提交即可  
 

测试在其他项目拉取包：  
composer require jaydenman/myframework   