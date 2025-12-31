<?php
/**
 * 描述 : 简单测试 windowAsync.bin 脚本
 * 说明 : 直接测试脚本是否能正常执行
 */

// 设置脚本路径（根据实际情况修改）
$scriptPath = __DIR__ . '/winAsync.bin';

echo "========== 简单测试 winAsync.bin ==========\n\n";

// 1. 检查文件是否存在
if (!file_exists($scriptPath)) {
    die("❌ 脚本文件不存在: {$scriptPath}\n");
}
echo "✓ 脚本文件存在\n\n";

// 2. 检查是否为 Windows
$osType = strtolower(substr(PHP_OS, 0, 3));
if ($osType !== 'win') {
    die("❌ 此测试仅支持 Windows 系统\n");
}
echo "✓ Windows 系统检测通过\n\n";

// 3. 构建测试命令
$testCommand = array(
    'php',
    '-r',
    'echo "Hello from windowAsync.bin! Time: " . date("Y-m-d H:i:s") . PHP_EOL;'
);

// 4. 转义引号（模拟 net.php 的处理方式）
$exec = str_replace('"', '""', '"' . join('" "', $testCommand) . '"');

// 5. 获取 OF_DIR（如果定义了）
$ofDir = defined('OF_DIR') ? OF_DIR : dirname(dirname($scriptPath));
$scriptFullPath = strtr($ofDir, '/', '\\') . '\\accy\\com\\net\\windowAsync.bin';

// 6. 构建完整命令
$fullCommand = "SET data=\"{$exec}\" && cscript //E:vbscript \"{$scriptFullPath}\"";

echo "执行命令:\n";
echo substr($fullCommand, 0, 150) . "...\n\n";

// 7. 执行命令
echo "正在执行...\n";
$startTime = microtime(true);

$fp = @popen($fullCommand, 'r');
if ($fp) {
    pclose($fp);
    $duration = microtime(true) - $startTime;
    echo "✓ 命令已异步执行（耗时: " . round($duration, 3) . " 秒）\n";
    echo "✓ 如果成功，应该会在后台输出 'Hello from windowAsync.bin!'\n";
    echo "\n提示: 由于是异步执行，输出可能不会显示在这里\n";
    echo "可以查看 PHP 进程或日志确认是否执行成功\n";
} else {
    echo "❌ 执行失败，请检查:\n";
    echo "  1. 是否有执行 popen 的权限\n";
    echo "  2. cscript 是否可用\n";
    echo "  3. 脚本路径是否正确\n";
}

echo "\n========== 测试完成 ==========\n";