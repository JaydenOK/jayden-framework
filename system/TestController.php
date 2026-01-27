<?php

namespace app\system;

use app\core\lib\controller\Controller;
use app\language\Api;
use app\module\utils\ResponseUtil;
use app\utils\HttpClient;
use app\utils\LoggerUtil;

class TestController extends Controller
{
    // 计划任务测试1
    public static function testTask1()
    {
        return true;
    }

    /**
     * 测试队列1 - 消费者回调
     *
     * @param array $message 消息数据，结构如下：
     *                       - _msgId: 消息ID
     *                       - data: 实际数据（MqManager::set 传入的第二个参数）
     *                       - time: 消息创建时间戳
     *                       - microtime: 消息创建微秒时间
     * @return void
     */
    public static function testMq1($message)
    {
        $syncCount = $message['_syncCount'] ?? 1;
        $msgId = $message['msgId'] ?? '';
        // 记录开始处理
        $logFile = __DIR__ . '/data/mq/log/testMq1.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        file_put_contents($logFile, date('[Y-m-d H:i:s]') . ':syncCount:' . $syncCount . ':' . json_encode($message, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
        sleep(3);       //模拟程序正在执行，延迟锁定
        if ($syncCount > 5) {
            //超过指定次数，删除队列信息
            return true;
        }
        return false;
    }

    //测试队列 - 消费者回调
    public static function checkUser($message)
    {
        // 记录开始处理
        $syncCount = $message['_syncCount'] ?? 1;
        $msgId = $message['msgId'] ?? '';
        $logFile = __DIR__ . '/data/mq/log/testMq2.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        file_put_contents($logFile, date('[Y-m-d H:i:s]') . ':syncCount:' . $syncCount . ':' . json_encode($message, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
        sleep(3);       //模拟程序正在执行，延迟锁定
        if ($syncCount > 5) {
            //超过指定次数，删除队列信息
            return true;
        }
        return false;
    }

    //测试队列2 - 消费者回调
    public static function testMq2($message)
    {
        $syncCount = $message['_syncCount'] ?? 1;
        $msgId = $message['msgId'] ?? '';
        // 记录开始处理
        $logFile = __DIR__ . '/data/mq/log/testMq2.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        file_put_contents($logFile, date('[Y-m-d H:i:s]') . ':syncCount:' . $syncCount . ':' . json_encode($message, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
        sleep(3);       //模拟程序正在执行，延迟锁定
        if ($syncCount > 5) {
            //超过指定次数，删除队列信息
            return true;
        }
        return false;
    }

    //测试队列testRedisMq - 消费者回调
    public static function testRedisMq($message)
    {
        $syncCount = $message['_syncCount'] ?? 1;
        $msgId = $message['msgId'] ?? '';
        // 记录开始处理
        $logFile = __DIR__ . '/data/mq/log/testRedisMq.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        file_put_contents($logFile, date('[Y-m-d H:i:s]') . ':syncCount:' . $syncCount . ':' . json_encode($message, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
        sleep(3);       //模拟程序正在执行，延迟锁定
        if ($syncCount > 5) {
            //超过指定次数，删除队列信息
            return true;
        }
        return false;
    }

    /**
     * 测试发送消息到队列
     * @link http://jayden.cc/system/test/sendMq
     */
    public function sendMq()
    {
        $count = isset($_GET['count']) ? (int)$_GET['count'] : 1;
        $mqName = isset($_GET['name']) ? $_GET['name'] : 'testMq1';

        $results = [];
        for ($i = 1; $i <= $count; $i++) {
            $data = [
                'index' => $i,
                'message' => "测试消息 #{$i}",
                'sleep' => rand(1, 3), // 随机睡眠1-3秒
                'timestamp' => date('Y-m-d H:i:s')
            ];

            $messageId = MqManager::set($mqName, $data);
            $results[] = [
                'index' => $i,
                'messageId' => $messageId,
                'success' => $messageId !== false
            ];
        }

        return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, [
            'total' => $count,
            'sent' => count(array_filter($results, function ($r) {
                return $r['success'];
            })),
            'results' => $results
        ]);
    }

    /**
     * 查看队列状态
     * @link http://jayden.cc/system/test/mqStats
     */
    public function mqStats()
    {
        $mqName = isset($_GET['name']) ? $_GET['name'] : null;
        $stats = MqManager::stats($mqName);

        return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, $stats);
    }

    /**
     * 查看队列消息（预览，不消费）
     * @link http://jayden.cc/system/test/mqPeek
     */
    public function mqPeek()
    {
        $mqName = isset($_GET['name']) ? $_GET['name'] : 'testMq1';
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

        $messages = MqManager::peek($mqName, $limit);

        return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, [
            'queue' => $mqName,
            'count' => count($messages),
            'messages' => $messages
        ]);
    }
}
