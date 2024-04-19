
##### git 匹配删除多余的分支-hotfix-*(git 命令行)
```text
//windows
// git branch | Where-Object { $_ -match 'hotfix-' } | ForEach-Object { git branch -d $_.trim() }

//bash命令行:
//git branch|grep hotfix-|xargs -n 1 git branch -d
```
