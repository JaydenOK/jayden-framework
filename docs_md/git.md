
##### git 匹配删除多余的分支-hotfix-*(git 命令行)
```text
//windows
// git branch | Where-Object { $_ -match 'hotfix-' } | ForEach-Object { git branch -d $_.trim() }

//bash命令行:
//git branch|grep hotfix-|xargs -n 1 git branch -d
```



```text
Quick setup — if you’ve done this kind of thing before
or
git@github.com:JaydenOK/a.git
Get started by creating a new file or uploading an existing file. We recommend every repository include a README, LICENSE, and .gitignore.

# 1推送新仓库代码到github (都需远程创建仓库)
…or create a new repository on the command line

echo "# a" >> README.md
git init
git add README.md
git commit -m "first commit"
git branch -M main
git remote add origin git@github.com:JaydenOK/a.git
git push -u origin main

# 2推送已存在的仓库代码到github
…or push an existing repository from the command line

git remote add origin git@github.com:JaydenOK/a.git
git branch -M main
git push -u origin main
…or import code from another repository


You can initialize this repository with code from a Subversion, Mercurial, or TFS project.

```