' ============================================
' 描述 : Windows 异步执行 PHP 命令脚本（增强版）
' 用途 : 配合 OF 框架 net::request 异步请求使用
' 功能 : 隐藏窗口异步执行命令，避免阻塞主进程
' ============================================

Option Explicit

' 创建 WScript.Shell 对象
Set obj = WScript.CreateObject("WScript.Shell")

' 从环境变量读取命令参数
Dim args
args = Trim(obj.ExpandEnvironmentStrings("%data%"))

' 参数验证
If Len(args) = 0 Then
    Set obj = Nothing
    WScript.Quit 1
End If

' 处理引号转义（需要至少2个字符才能去除引号）
If Len(args) >= 2 Then
    ' 去除外层引号
    args = Mid(args, 2, Len(args) - 2)
    ' 还原转义的双引号（"""" -> "）
    args = Replace(args, """""", """")
End If

' 执行命令（隐藏窗口，异步模式）
Dim execResult
On Error Resume Next
execResult = obj.Run(args, 0, False)
If Err.Number <> 0 Then
    ' 执行失败（可选：记录到日志文件）
    ' 这里静默失败，因为异步执行不需要反馈
    WScript.Quit Err.Number
End If
On Error Goto 0

' 清理资源
Set obj = Nothing

' 正常退出
WScript.Quit 0