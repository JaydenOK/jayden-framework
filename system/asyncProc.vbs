' 异步进程启动脚本
' 使用: SET data="命令" && cscript //E:vbscript asyncProc.vbs

On Error Resume Next

Set obj = WScript.CreateObject("WScript.Shell")

' 从环境变量获取命令
args = Trim(obj.ExpandEnvironmentStrings("%data%"))

' 检查参数
If args = "" Or args = "%data%" Then WScript.Quit 1

' 去除首尾引号，还原双引号
args = Replace(Mid(args, 2, Len(args) - 2), """""", """")

' 后台执行 (0=隐藏窗口, False=不等待)
obj.Run args, 0, False