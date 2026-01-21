<?php

namespace app\system;

use app\core\lib\controller\Controller;
use app\system\MqManager;

/**
 * 消息队列控制器
 * 
 * 提供消息队列的Web管理界面和CLI命令支持
 * 支持MySQL和Redis双存储引擎，根据虚拟主机配置自动切换
 *
 * Web接口列表：
 * - /system/mq/index      管理页面（HTML可视化，查看队列状态、启停服务）
 * - /system/mq/list       消息列表页面（查看、筛选、执行、删除消息）
 * - /system/mq/start      启动主进程
 * - /system/mq/stop       停止主进程
 * - /system/mq/restart    重启主进程
 * - /system/mq/status     查看状态（JSON）
 * - /system/mq/statusData 获取状态数据（AJAX接口）
 * - /system/mq/test       发送测试消息
 * - /system/mq/messages   获取消息列表（JSON）
 * - /system/mq/detail     获取消息详情（JSON）
 * - /system/mq/execute    执行指定消息
 * - /system/mq/reset      重置消息（清零重试次数）
 * - /system/mq/delete     删除消息
 *
 * CLI命令：
 * - php index.php system/mq/start    启动（前台运行，可查看日志输出）
 * - php index.php system/mq/daemon   启动（后台守护进程模式）
 * - php index.php system/mq/stop     停止主进程及所有消费者
 * - php index.php system/mq/restart  重启（先停止后启动）
 * - php index.php system/mq/status   查看运行状态
 *
 * 配置文件：
 * - system/config/db.php     数据库/Redis连接配置（支持多环境：db-local.php, db-dev.php等）
 * - system/config/mq.php     队列配置（队列名称、消费者数量、回调方法）
 *
 * 队列配置示例（system/config/mq.php）：
 * ```php
 * return [
 *     'default' => [                                    // 虚拟主机名（对应db.php中的配置）
 *         'testMq1' => [                                // 队列名称
 *             'cNum' => 2,                              // 消费者进程数量
 *             'call' => 'app\system\TestController::testMq1'  // 回调方法
 *         ],
 *     ],
 * ];
 * ```
 *
 * 回调方法返回值说明：
 * - return true;    处理成功，消息被删除
 * - return false;   处理失败，按指数退避重试（2^n 分钟）
 * - return 3600;    处理失败，指定3600秒后重试
 */
class MqController extends Controller
{
    protected $dataPath;
    protected $queuePath;
    protected $lockPath;
    protected $logPath;
    protected $configFile;
    protected $mqConfig = [];
    protected $configMtime = 0;
    protected $isWindows;
    protected $checkInterval = 5;
    protected $pollInterval = 100000;
    protected $running = true;

    public function init()
    {
        $this->dataPath = __DIR__ . '/data/mq';
        $this->queuePath = $this->dataPath . '/queue';
        $this->lockPath = $this->dataPath . '/lock';
        $this->logPath = $this->dataPath . '/log';
        $this->configFile = __DIR__ . '/config/mq.php';
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        foreach ([$this->dataPath, $this->queuePath, $this->lockPath, $this->logPath] as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }

        if (is_file($this->configFile)) {
            $this->mqConfig = include $this->configFile;
            $this->configMtime = filemtime($this->configFile);
        }
    }

    /**
     * 获取队列配置（支持多虚拟主机格式）
     * @param string $vHost 虚拟主机
     * @param string $mqName 队列名称
     * @return array|null
     */
    protected function getQueueConfig($vHost, $mqName)
    {
        return $this->mqConfig[$vHost][$mqName] ?? null;
    }

    /**
     * 获取所有虚拟主机列表
     * @return array
     */
    protected function getVHostList()
    {
        return array_keys($this->mqConfig);
    }

    /**
     * 获取指定虚拟主机的队列列表
     * @param string $vHost 虚拟主机
     * @return array
     */
    protected function getQueueListByVHost($vHost)
    {
        return $this->mqConfig[$vHost] ?? [];
    }

    /**
     * 管理页面
     * @link http://jayden.cc/system/mq/index
     */
    public function index()
    {
        // 页面先渲染，状态数据通过 AJAX 异步加载
        $this->renderHtml(null);
    }
    
    /**
     * 获取状态数据（AJAX接口）
     * @link http://jayden.cc/system/mq/statusData
     */
    public function statusData()
    {
        $status = $this->getStatusData();
        return $this->response(0, 'success', $status);
    }

    /**
     * 启动主进程
     */
    public function start()
    {
        if ($this->isMasterRunning()) {
            return $this->response(1, '主进程已在运行中');
        }

        if ($this->isHttpRequest()) {
            $this->startMasterAsync();
            usleep(1000000);

            if ($this->isMasterRunning()) {
                $lock = $this->readLockFile($this->getMasterLockFile());
                return $this->response(0, '启动成功', ['pid' => $lock['pid'] ?? null]);
            }
            return $this->response(1, '启动失败，请检查日志');
        }

        $this->runMaster();
    }

    /**
     * 停止主进程
     */
    public function stop()
    {
        $force = !empty($_GET['force']) || !empty($_POST['force']) || $this->getArg('force') === 'true';

        if (!$this->isMasterRunning()) {
            return $this->response(0, '主进程未运行');
        }

        $lock = $this->readLockFile($this->getMasterLockFile());

        if ($force) {
            $this->killProcess($lock['pid'] ?? 0);
            foreach ($this->mqConfig as $mqName => $config) {
                $cNum = (int)($config['cNum'] ?? 1);
                for ($i = 1; $i <= $cNum; $i++) {
                    $cLock = $this->readLockFile($this->getConsumerLockFile($mqName, $i));
                    $this->killProcess($cLock['pid'] ?? 0);
                }
            }
            $this->cleanAllLockFiles();
            return $this->response(0, '已强制停止');
        }

        $lock['stopping'] = true;
        file_put_contents($this->getMasterLockFile(), json_encode($lock));
        return $this->response(0, '已发送停止信号');
    }

    /**
     * 重启主进程
     */
    public function restart()
    {
        $wasRunning = $this->isMasterRunning();

        if ($wasRunning) {
            $lock = $this->readLockFile($this->getMasterLockFile());
            $this->killProcess($lock['pid'] ?? 0);
            foreach ($this->mqConfig as $mqName => $config) {
                $cNum = (int)($config['cNum'] ?? 1);
                for ($i = 1; $i <= $cNum; $i++) {
                    $cLock = $this->readLockFile($this->getConsumerLockFile($mqName, $i));
                    $this->killProcess($cLock['pid'] ?? 0);
                }
            }
            $this->cleanAllLockFiles();
            sleep(1);
        }

        if ($this->isHttpRequest()) {
            $this->startMasterAsync();
            usleep(1000000);

            if ($this->isMasterRunning()) {
                $lock = $this->readLockFile($this->getMasterLockFile());
                return $this->response(0, '重启成功', ['pid' => $lock['pid'] ?? null]);
            }
            return $this->response(1, '重启失败');
        }

        $this->runMaster();
    }

    /**
     * 查看状态（JSON）
     */
    public function status()
    {
        return $this->response(0, 'success', $this->getStatusData());
    }

    /**
     * 发送测试消息
     */
    public function test()
    {
        $vHost = $_GET['vhost'] ?? 'default';
        $mqName = $_GET['queue'] ?? 'testMq1';
        $count = (int)($_GET['count'] ?? 1);

        $config = $this->getQueueConfig($vHost, $mqName);
        if (!$config) {
            return $this->response(1, "队列 {$vHost}/{$mqName} 不存在");
        }

        $sent = 0;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = MqManager::set($mqName, [
                'index' => $i + 1,
                'time' => date('Y-m-d H:i:s'),
                'data' => "测试消息 #" . ($i + 1)
            ], $vHost);
            if ($id) {
                $sent++;
                $ids[] = $id;
            }
        }

        // 设置虚拟主机以获取正确的队列长度
        MqManager::setVHost($vHost);

        return $this->response(0, "已发送 {$sent}/{$count} 条消息到 {$vHost}/{$mqName}", [
            'vhost' => $vHost,
            'queue' => $mqName,
            'sent' => $sent,
            'total' => $count,
            'ids' => $ids,
            'queueLength' => MqManager::length($mqName)
        ]);
    }

    /**
     * 消息队列列表页面
     * @link http://jayden.cc/system/mq/list
     */
    public function list()
    {
        $this->renderListHtml();
    }

    /**
     * 获取消息列表（JSON接口）
     * @link http://jayden.cc/system/mq/messages
     */
    public function messages()
    {
        $vHost = $_GET['vhost'] ?? '';

        $filter = [];
        if (!empty($_GET['name'])) $filter['name'] = $_GET['name'];
        if (!empty($_GET['group'])) $filter['group'] = $_GET['group'];
        if (!empty($_GET['msgId'])) $filter['msgId'] = $_GET['msgId'];
        if (!empty($_GET['data'])) $filter['data'] = $_GET['data'];  // 消息内容模糊搜索
        if (isset($_GET['locked']) && $_GET['locked'] !== '') $filter['locked'] = (bool)$_GET['locked'];
        if (isset($_GET['syncLevel']) && $_GET['syncLevel'] !== '') $filter['syncLevel'] = (int)$_GET['syncLevel'];

        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($_GET['pageSize'] ?? 20)));

        // 获取所有虚拟主机列表
        $vhosts = $this->getVHostList();

        if (!empty($vHost) && in_array($vHost, $vhosts)) {
            // 查询指定虚拟主机
            MqManager::setVHost($vHost);
            $result = MqManager::getList($filter, $page, $pageSize);
            $result['names'] = MqManager::getNameList();
            $result['groups'] = MqManager::getGroupList();
        } else {
            // 查询所有虚拟主机（合并结果）
            $allList = [];
            $allTotal = 0;
            $allNames = [];
            $allGroups = [];

            foreach ($vhosts as $vh) {
                MqManager::setVHost($vh);
                $vhResult = MqManager::getList($filter, 1, 10000); // 获取所有用于合并

                // 为每条消息添加vHost字段
                foreach ($vhResult['list'] as &$item) {
                    $item['vHost'] = $vh;
                }

                $allList = array_merge($allList, $vhResult['list']);
                $allTotal += $vhResult['total'];
                $allNames = array_merge($allNames, MqManager::getNameList());
                $allGroups = array_merge($allGroups, MqManager::getGroupList());
            }

            // 排序（按创建时间倒序）
            usort($allList, function ($a, $b) {
                return strcmp($b['createTime'], $a['createTime']);
            });

            // 分页
            $total = count($allList);
            $offset = ($page - 1) * $pageSize;
            $list = array_slice($allList, $offset, $pageSize);

            $result = [
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
                'totalPages' => ceil($total / $pageSize),
                'names' => array_unique($allNames),
                'groups' => array_unique($allGroups)
            ];
        }

        $result['vhosts'] = $vhosts;

        return $this->response(0, 'success', $result);
    }

    /**
     * 手动执行单条消息
     * @link http://jayden.cc/system/mq/execute
     */
    public function execute()
    {
        $vHost = $_POST['vhost'] ?? $_GET['vhost'] ?? 'default';
        $name = $_POST['name'] ?? $_GET['name'] ?? '';
        $unqid = $_POST['unqid'] ?? $_GET['unqid'] ?? '';

        if (empty($name) || empty($unqid)) {
            return $this->response(1, '参数错误：name和unqid不能为空');
        }

        // 设置虚拟主机
        MqManager::setVHost($vHost);

        // 获取消息详情
        $message = MqManager::getByUnqid($name, $unqid);
        if (!$message) {
            return $this->response(1, '消息不存在');
        }

        // 检查队列配置
        $queueName = $message['name'];
        $config = $this->getQueueConfig($vHost, $queueName);
        if (!$config) {
            return $this->response(1, "队列 {$vHost}/{$queueName} 配置不存在，无法执行");
        }

        // 尝试锁定消息
        $lockMark = MqManager::lockByUnqid($name, $unqid);
        if (!$lockMark) {
            return $this->response(1, '消息正在被处理中，无法执行');
        }

        $messageData = $message['data'];

        // 开启输出缓冲捕获回调输出
        ob_start();

        try {
            // 执行回调
            $returnValue = $this->executeCallbackWithReturn($config, $messageData);
            $output = ob_get_clean();

            // 处理返回值：true=成功，false=失败，正整数=延迟N秒后重试
            if ($returnValue === true) {
                // 执行成功，删除消息
                MqManager::ackByUnqid($name, $unqid, $lockMark);
                return $this->response(0, '执行成功，消息已删除', [
                    'msgId' => $message['msgId'],
                    'name' => $queueName,
                    'returnValue' => $returnValue,
                    'output' => $output
                ]);
            } elseif (is_numeric($returnValue) && $returnValue > 0) {
                // 返回正整数，表示延迟指定秒数后重试
                $delaySeconds = (int)$returnValue;
                MqManager::unlockByUnqid($name, $unqid, $lockMark, true, $delaySeconds);
                return $this->response(1, "执行失败，消息将在{$delaySeconds}秒后重试", [
                    'msgId' => $message['msgId'],
                    'name' => $queueName,
                    'returnValue' => $returnValue,
                    'delaySeconds' => $delaySeconds,
                    'output' => $output
                ]);
            } else {
                // 执行失败，放回队列（使用默认延迟）
                MqManager::unlockByUnqid($name, $unqid, $lockMark, true);
                return $this->response(1, '执行失败，消息已放回队列', [
                    'msgId' => $message['msgId'],
                    'name' => $queueName,
                    'returnValue' => $returnValue,
                    'output' => $output
                ]);
            }
        } catch (\Throwable $e) {
            $output = ob_get_clean();
            // 异常，放回队列
            MqManager::unlockByUnqid($name, $unqid, $lockMark, true);
            return $this->response(1, '执行异常：' . $e->getMessage(), [
                'msgId' => $message['msgId'],
                'name' => $queueName,
                'returnValue' => false,
                'output' => $output,
                'error' => $e->getMessage() . "\n" . $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 执行回调并返回原始返回值
     * @return mixed 回调的原始返回值
     */
    protected function executeCallbackWithReturn($config, $message)
    {
        if (empty($config['call'])) return true;

        $cb = $config['call'];
        $result = false;

        if (is_string($cb) && strpos($cb, '::') !== false) {
            list($class, $method) = explode('::', $cb);

            if (!class_exists($class)) {
                echo "[ERROR] 回调类不存在: {$class}\n";
                return false;
            }

            if (!method_exists($class, $method)) {
                echo "[ERROR] 回调方法不存在: {$class}::{$method}\n";
                return false;
            }

            $result = call_user_func([$class, $method], $message);
        } elseif (is_callable($cb)) {
            $result = call_user_func($cb, $message);
        } else {
            echo "[ERROR] 回调不可调用: " . print_r($cb, true) . "\n";
            return false;
        }

        return $result;
    }

    /**
     * 删除单条消息
     * @link http://jayden.cc/system/mq/delete
     */
    public function delete()
    {
        $vHost = $_POST['vhost'] ?? $_GET['vhost'] ?? 'default';
        $name = $_POST['name'] ?? $_GET['name'] ?? '';
        $unqid = $_POST['unqid'] ?? $_GET['unqid'] ?? '';

        if (empty($name) || empty($unqid)) {
            return $this->response(1, '参数错误：name和unqid不能为空');
        }

        // 设置虚拟主机
        MqManager::setVHost($vHost);

        $message = MqManager::getByUnqid($name, $unqid);
        if (!$message) {
            return $this->response(1, '消息不存在');
        }

        // 检查是否被锁定
        if (!empty($message['lockMark'])) {
            return $this->response(1, '消息正在被处理中，无法删除');
        }

        if (MqManager::deleteByUnqid($name, $unqid)) {
            return $this->response(0, '删除成功', ['msgId' => $message['msgId']]);
        }

        return $this->response(1, '删除失败');
    }

    /**
     * 重置失败消息
     * 将锁定时间改为当前时间，重试次数清零
     * @link http://jayden.cc/system/mq/reset
     */
    public function reset()
    {
        $vHost = $_POST['vhost'] ?? $_GET['vhost'] ?? 'default';
        $name = $_POST['name'] ?? $_GET['name'] ?? '';
        $unqid = $_POST['unqid'] ?? $_GET['unqid'] ?? '';

        if (empty($name) || empty($unqid)) {
            return $this->response(1, '参数错误：name和unqid不能为空');
        }

        // 设置虚拟主机
        MqManager::setVHost($vHost);

        $message = MqManager::getByUnqid($name, $unqid);
        if (!$message) {
            return $this->response(1, '消息不存在');
        }

        // 检查是否被锁定
        if (!empty($message['lockMark'])) {
            return $this->response(1, '消息正在被处理中，无法重置');
        }

        if (MqManager::resetByUnqid($name, $unqid)) {
            return $this->response(0, '重置成功', ['msgId' => $message['msgId']]);
        }

        return $this->response(1, '重置失败');
    }

    /**
     * 获取消息详情
     * @link http://jayden.cc/system/mq/detail
     */
    public function detail()
    {
        $vHost = $_GET['vhost'] ?? 'default';
        $name = $_GET['name'] ?? '';
        $unqid = $_GET['unqid'] ?? '';

        if (empty($name) || empty($unqid)) {
            return $this->response(1, '参数错误：name和unqid不能为空');
        }

        // 设置虚拟主机
        MqManager::setVHost($vHost);

        $message = MqManager::getByUnqid($name, $unqid);
        if (!$message) {
            return $this->response(1, '消息不存在');
        }

        return $this->response(0, 'success', $message);
    }

    /**
     * 新页面执行消息（显示执行结果）
     * @link http://jayden.cc/system/mq/run?vhost=xxx&name=xxx&unqid=xxx
     */
    public function run()
    {
        $vHost = $_GET['vhost'] ?? 'default';
        $name = $_GET['name'] ?? '';
        $unqid = $_GET['unqid'] ?? '';

        // 开启输出缓冲捕获回调输出
        ob_start();

        $result = [
            'success' => false,
            'message' => '',
            'vHost' => $vHost,
            'msgId' => '',
            'name' => '',
            'returnValue' => null,
            'error' => '',
            'output' => ''
        ];

        if (empty($name) || empty($unqid)) {
            $result['message'] = '参数错误：name和unqid不能为空';
            $this->renderRunResult($result);
            return;
        }

        // 设置虚拟主机
        MqManager::setVHost($vHost);

        // 获取消息详情
        $message = MqManager::getByUnqid($name, $unqid);
        if (!$message) {
            $result['message'] = '消息不存在';
            $this->renderRunResult($result);
            return;
        }

        $result['msgId'] = $message['msgId'];
        $result['name'] = $message['name'];

        // 检查队列配置
        $queueName = $message['name'];
        $config = $this->getQueueConfig($vHost, $queueName);
        if (!$config) {
            $result['message'] = "队列 {$vHost}/{$queueName} 配置不存在，无法执行";
            $this->renderRunResult($result);
            return;
        }

        // 尝试锁定消息
        $lockMark = MqManager::lockByUnqid($name, $unqid);
        if (!$lockMark) {
            $result['message'] = '消息正在被处理中，无法执行';
            $this->renderRunResult($result);
            return;
        }

        $messageData = $message['data'];

        try {
            // 执行回调
            $returnValue = $this->executeCallbackWithReturn($config, $messageData);
            $result['output'] = ob_get_clean();
            $result['returnValue'] = $returnValue;

            // 处理返回值：true=成功，false=失败，正整数=延迟N秒后重试
            if ($returnValue === true) {
                // 执行成功，删除消息
                MqManager::ackByUnqid($name, $unqid, $lockMark);
                $result['success'] = true;
                $result['message'] = '执行成功，消息已删除';
            } elseif (is_numeric($returnValue) && $returnValue > 0) {
                // 返回正整数，表示延迟指定秒数后重试
                $delaySeconds = (int)$returnValue;
                MqManager::unlockByUnqid($name, $unqid, $lockMark, true, $delaySeconds);
                $result['delaySeconds'] = $delaySeconds;
                $result['message'] = "执行失败，消息将在{$delaySeconds}秒后重试";
            } else {
                // 执行失败，放回队列（使用默认延迟）
                MqManager::unlockByUnqid($name, $unqid, $lockMark, true);
                $result['message'] = '执行失败，消息已放回队列';
            }
        } catch (\Throwable $e) {
            $result['output'] = ob_get_clean();
            $result['returnValue'] = false;
            // 异常，放回队列
            MqManager::unlockByUnqid($name, $unqid, $lockMark, true);
            $result['message'] = '执行异常';
            $result['error'] = $e->getMessage() . "\n" . $e->getTraceAsString();
        }

        $this->renderRunResult($result);
    }

    /**
     * 渲染执行结果页面
     */
    protected function renderRunResult($result)
    {
        $statusClass = $result['success'] ? 'success' : 'error';
        $statusText = $result['success'] ? '成功' : '失败';
        $messageHtml = htmlspecialchars($result['message']);
        $msgIdHtml = htmlspecialchars($result['msgId']);
        $nameHtml = htmlspecialchars($result['name']);
        $outputHtml = htmlspecialchars($result['output'] ?: '(无输出)');
        $errorHtml = htmlspecialchars($result['error'] ?: '');

        // 格式化返回值显示
        $returnValue = $result['returnValue'] ?? null;
        $delaySeconds = $result['delaySeconds'] ?? 0;

        if ($returnValue === true) {
            $returnValueHtml = '<span style="color:#52c41a;font-weight:600;">true (成功)</span>';
        } elseif ($returnValue === false) {
            $returnValueHtml = '<span style="color:#ff4d4f;font-weight:600;">false (失败)</span>';
        } elseif ($returnValue === null) {
            $returnValueHtml = '<span style="color:#999;">null (未执行)</span>';
        } elseif (is_numeric($returnValue) && $returnValue > 0) {
            $returnValueHtml = '<span style="color:#faad14;font-weight:600;">' . (int)$returnValue . ' (延迟' . (int)$returnValue . '秒后重试)</span>';
        } else {
            $returnValueHtml = '<span style="color:#faad14;">' . htmlspecialchars(var_export($returnValue, true)) . '</span>';
        }

        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>消息执行结果</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { color: #333; margin-bottom: 20px; font-size: 24px; display: flex; align-items: center; gap: 15px; }
        h1 a { font-size: 14px; color: #1890ff; text-decoration: none; }
        h1 a:hover { text-decoration: underline; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
        .status { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .status-dot { width: 16px; height: 16px; border-radius: 50%; }
        .status-dot.success { background: #52c41a; }
        .status-dot.error { background: #ff4d4f; }
        .status-text { font-size: 18px; font-weight: 600; }
        .status-text.success { color: #52c41a; }
        .status-text.error { color: #ff4d4f; }
        .info-row { margin-bottom: 10px; font-size: 14px; }
        .info-row label { color: #666; display: inline-block; width: 100px; }
        .info-row span { color: #333; }
        .section { margin-top: 20px; }
        .section h3 { font-size: 14px; color: #666; margin-bottom: 10px; border-bottom: 1px solid #f0f0f0; padding-bottom: 8px; }
        .output { background: #f6f8fa; padding: 15px; border-radius: 6px; font-family: monospace; font-size: 13px; white-space: pre-wrap; word-break: break-all; max-height: 400px; overflow-y: auto; }
        .error-output { background: #fff2f0; border: 1px solid #ffccc7; color: #ff4d4f; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1890ff; color: #fff; }
        .btn-primary:hover { background: #40a9ff; }
        .actions { margin-top: 20px; display: flex; gap: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            消息执行结果
            <a href="/system/mq/list">返回列表</a>
        </h1>
        
        <div class="card">
            <div class="status">
                <div class="status-dot {$statusClass}"></div>
                <span class="status-text {$statusClass}">执行{$statusText}</span>
            </div>
            
            <div class="info-row">
                <label>结果说明:</label>
                <span>{$messageHtml}</span>
            </div>
            <div class="info-row">
                <label>消息ID:</label>
                <span>{$msgIdHtml}</span>
            </div>
            <div class="info-row">
                <label>队列名称:</label>
                <span>{$nameHtml}</span>
            </div>
            <div class="info-row">
                <label>回调返回值:</label>
                {$returnValueHtml}
            </div>
            
            <div class="section">
                <h3>回调输出</h3>
                <div class="output">{$outputHtml}</div>
            </div>
HTML;

        if (!empty($result['error'])) {
            echo <<<HTML
            
            <div class="section">
                <h3>错误信息</h3>
                <div class="output error-output">{$errorHtml}</div>
            </div>
HTML;
        }

        echo <<<HTML
            
            <div class="actions">
                <a href="/system/mq/list" class="btn btn-primary">返回列表</a>
                <a href="javascript:window.close();" class="btn">关闭窗口</a>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
        exit;
    }

    /**
     * 守护进程入口（后台运行）
     * 
     * CLI命令：php index.php system/mq/daemon
     * 
     * 与 start 的区别：
     * - daemon: 后台运行，适合生产环境
     * - start: 前台运行，可查看日志输出，适合调试
     */
    public function daemon()
    {
        if ($this->isHttpRequest()) {
            return $this->response(1, '仅支持CLI调用');
        }
        if ($this->isMasterRunning()) {
            echo "[ERROR] 主进程已在运行\n";
            return;
        }
        $this->runMaster();
    }

    /**
     * 消费者进程入口
     * 
     * 由主进程自动启动，不应手动调用
     * CLI命令：php index.php system/mq/consumer --vhost=xxx --name=xxx --id=xxx
     * 
     * 消费流程：
     * 1. 循环调用 MqManager::pop() 获取消息（已自动锁定）
     * 2. 执行配置的回调方法处理消息
     * 3. 根据返回值决定后续操作：
     *    - true:  调用 ack() 删除消息
     *    - false: 调用 nack() 解锁并设置指数退避重试
     *    - 正整数: 调用 nack() 解锁并设置指定秒数后重试
     * 4. 队列为空时休眠 pollInterval 微秒后继续轮询
     * 
     * 停止机制：
     * - 优雅停止：主进程设置 stopping=true，消费者处理完当前消息后退出
     * - 强制停止：主进程设置 force=true，消费者立即退出（即使正在处理消息）
     */
    public function consumer()
    {
        $vHost = $this->getArg('vhost') ?: 'default';
        $mqName = $this->getArg('name');
        $consumerId = (int)$this->getArg('id');

        $config = $this->getQueueConfig($vHost, $mqName);
        if (empty($mqName) || empty($consumerId) || !$config) {
            echo "[ERROR] 参数错误: vhost={$vHost}, name={$mqName}\n";
            return;
        }

        // 设置当前虚拟主机（影响 MqManager 的数据库/Redis连接）
        MqManager::setVHost($vHost);

        $lockFile = $this->getConsumerLockFile($mqName, $consumerId, $vHost);

        // 创建锁文件（记录消费者状态，用于主进程监控和停止控制）
        $this->createLockFile($lockFile, [
            'role' => 'consumer', 'vHost' => $vHost, 'mqName' => $mqName,
            'consumerId' => $consumerId, 'stopping' => false, 'busy' => false
        ]);

        echo "[INFO] 消费者启动: {$vHost}/{$mqName}#{$consumerId}\n";

        // 消费循环
        while (true) {
            // 检查停止信号（优雅停止：等待当前消息处理完；强制停止：立即退出）
            $lock = $this->readLockFile($lockFile);
            if ($lock && !empty($lock['stopping']) && (empty($lock['busy']) || !empty($lock['force']))) {
                break;
            }

            // pop() 会原子性地锁定一条消息并返回
            // MySQL: 通过 UPDATE + lockMark 实现锁定
            // Redis: 从 queue List 弹出并记录到 lock Hash
            $message = MqManager::pop($mqName);
            
            if ($message !== null) {
                // 标记为忙碌状态（防止优雅停止时被中断）
                $this->updateLockFile($lockFile, ['busy' => true]);
                $msgId = $message['id'] ?? $message['_unqid'] ?? 'unknown';
                
                try {
                    // 执行回调并获取返回值
                    $result = $this->executeCallbackWithReturn($config, $message);

                    // 根据返回值处理消息
                    if ($result === true) {
                        // 成功：确认消费，删除消息
                        MqManager::ack($mqName, $message);
                        echo "[INFO] 消息处理成功: {$msgId}\n";
                    } else {
                        // 失败：解锁消息，设置重试延迟
                        // - 返回正整数：使用自定义延迟秒数
                        // - 返回其他：使用指数退避（2^n 分钟）
                        $delaySeconds = (is_numeric($result) && $result > 0) ? (int)$result : 0;
                        MqManager::nack($mqName, $message, $delaySeconds);
                        
                        $syncCount = ($message['_syncCount'] ?? 0) + 1;
                        if ($delaySeconds > 0) {
                            echo "[WARN] 消息重试第{$syncCount}次, {$delaySeconds}秒后重试: {$msgId}\n";
                        } else {
                            $delayMinutes = pow(2, $syncCount);
                            echo "[WARN] 消息重试第{$syncCount}次, {$delayMinutes}分钟后重试: {$msgId}\n";
                        }
                    }
                } catch (\Throwable $e) {
                    // 异常：解锁消息，使用默认指数退避重试
                    MqManager::nack($mqName, $message, 0);
                    echo "[ERROR] 消息处理异常: {$msgId}, " . $e->getMessage() . "\n";
                }
                
                // 标记为空闲状态
                $this->updateLockFile($lockFile, ['busy' => false]);
            } else {
                // 队列为空，休眠后继续轮询（默认 100ms）
                usleep($this->pollInterval);
            }
        }

        // 清理：关闭数据库连接，删除锁文件
        MqManager::close($vHost);
        $this->deleteLockFile($lockFile);
        echo "[INFO] 消费者退出: {$vHost}/{$mqName}#{$consumerId}\n";
    }

    // ==================== 核心逻辑 ====================

    /**
     * 主进程运行逻辑
     * 
     * 主进程职责：
     * 1. 启动所有队列的消费者进程（根据 mq.php 配置的 cNum）
     * 2. 监听配置变化，动态调整消费者数量（热加载）
     * 3. 监控消费者健康状态，自动重启异常消费者
     * 4. 响应停止信号，优雅关闭所有消费者
     * 
     * 主循环（每 checkInterval 秒执行一次）：
     * - 检查停止信号（lock文件中的 stopping 标志）
     * - 检查配置变化（mq.php 文件修改时间）
     * - 检查消费者健康状态（通过 lock 文件和进程检测）
     */
    protected function runMaster()
    {
        echo "[INFO] 主进程启动 PID:" . getmypid() . "\n";
        // 创建主进程锁文件（记录PID，用于停止和状态查询）
        $this->createLockFile($this->getMasterLockFile(), ['role' => 'master']);

        // Linux下注册信号处理（Ctrl+C 和 kill）
        if (!$this->isWindows && function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () {
                $this->running = false;
            });
            pcntl_signal(SIGINT, function () {
                $this->running = false;
            });
        }

        // 启动所有消费者进程
        $consumers = [];  // 结构: [vHost => [mqName => [consumerId => startTime]]]
        $this->startAllConsumers($consumers);

        // 主循环
        while ($this->running) {
            // 处理信号（Linux）
            if (!$this->isWindows && function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // 检查停止信号（Web界面或CLI发送）
            $lock = $this->readLockFile($this->getMasterLockFile());
            if ($lock && !empty($lock['stopping'])) {
                break;
            }

            // 配置热加载：检测 mq.php 变化，动态调整消费者
            if ($this->isConfigChanged()) {
                $oldConfig = $this->mqConfig;
                $this->mqConfig = include $this->configFile;
                $this->configMtime = filemtime($this->configFile);
                $this->handleConfigChange($oldConfig, $this->mqConfig, $consumers);
            }

            // 健康检查：重启异常退出的消费者
            $this->checkConsumersHealth($consumers);
            
            // 休眠（默认5秒）
            sleep($this->checkInterval);
        }

        // 优雅停止所有消费者
        $this->stopAllConsumers($consumers);
        $this->deleteLockFile($this->getMasterLockFile());
        echo "[INFO] 主进程退出\n";
    }

    /**
     * 启动所有消费者进程
     * 
     * 遍历配置文件中的所有虚拟主机和队列，
     * 为每个队列启动 cNum 个消费者进程
     */
    protected function startAllConsumers(&$consumers)
    {
        foreach ($this->mqConfig as $vHost => $queues) {
            if (!isset($consumers[$vHost])) $consumers[$vHost] = [];

            foreach ($queues as $mqName => $config) {
                $cNum = (int)($config['cNum'] ?? 1);
                if (!isset($consumers[$vHost][$mqName])) $consumers[$vHost][$mqName] = [];

                for ($i = 1; $i <= $cNum; $i++) {
                    if (!$this->isConsumerRunning($mqName, $i, $vHost)) {
                        $this->startConsumer($vHost, $mqName, $i);
                        $consumers[$vHost][$mqName][$i] = time();
                    }
                }
            }
        }
    }

    /**
     * 启动单个消费者进程
     * 
     * 通过后台方式启动 consumer 方法，传递 vhost/name/id 参数
     * Windows: 使用 COM 对象或 VBS 脚本
     * Linux: 使用 nohup + & 后台运行
     */
    protected function startConsumer($vHost, $mqName, $consumerId)
    {
        $phpBin = $this->getPhpCliBinary();
        $script = realpath(__DIR__ . '/../index.php');

        if ($this->isWindows) {
            $this->startProcessWindows([$phpBin, $script, 'system/mq/consumer', "--vhost={$vHost}", "--name={$mqName}", "--id={$consumerId}"]);
        } else {
            $log = $this->logPath . "/consumer_{$vHost}_{$mqName}_{$consumerId}.log";
            pclose(popen("nohup \"{$phpBin}\" \"{$script}\" system/mq/consumer --vhost={$vHost} --name={$mqName} --id={$consumerId} >> \"{$log}\" 2>&1 &", 'r'));
        }
        echo "[INFO] 启动消费者: {$vHost}/{$mqName}#{$consumerId}\n";
    }

    /**
     * 异步启动主进程（用于Web界面启动）
     * 
     * 在后台启动 daemon 方法，立即返回
     */
    protected function startMasterAsync()
    {
        $phpBin = $this->getPhpCliBinary();
        $script = realpath(__DIR__ . '/../index.php');

        if ($this->isWindows) {
            $this->startProcessWindows([$phpBin, $script, 'system/mq/daemon']);
        } else {
            $log = $this->logPath . '/mq_' . date('Ymd') . '.log';
            pclose(popen("nohup \"{$phpBin}\" \"{$script}\" system/mq/daemon >> \"{$log}\" 2>&1 &", 'r'));
        }
    }

    protected function startProcessWindows($cmd)
    {
        if (class_exists('COM')) {
            try {
                $shell = new \COM("WScript.Shell");
                $shell->Run('"' . join('" "', $cmd) . '"', 0, false);
                return;
            } catch (\Exception $e) {
            }
        }

        $vbs = strtr(__DIR__, '/', '\\') . '\\bin\\asyncProc.vbs';
        if (is_file($vbs)) {
            $exec = str_replace('"', '""', '"' . join('" "', $cmd) . '"');
            pclose(popen('SET data="' . $exec . '" && cscript //E:vbscript "' . $vbs . '"', 'r'));
        }
    }

    /**
     * 处理配置变更（热加载核心逻辑）
     * 
     * 配置变更场景：
     * 1. 新增队列：启动对应数量的消费者
     * 2. 删除队列：停止该队列的所有消费者
     * 3. 增加 cNum：启动新增的消费者
     * 4. 减少 cNum：停止多余的消费者（优雅停止）
     * 
     * 注意：修改回调方法（call）需要重启消费者才能生效
     */
    protected function handleConfigChange($old, $new, &$consumers)
    {
        // 遍历所有虚拟主机（新旧配置的并集）
        $allVHosts = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($allVHosts as $vHost) {
            $oldQueues = $old[$vHost] ?? [];
            $newQueues = $new[$vHost] ?? [];

            if (!isset($consumers[$vHost])) $consumers[$vHost] = [];

            // 遍历该虚拟主机下的所有队列
            $allMqNames = array_unique(array_merge(array_keys($oldQueues), array_keys($newQueues)));

            foreach ($allMqNames as $mqName) {
                $oldN = (int)($oldQueues[$mqName]['cNum'] ?? 0);
                $newN = (int)($newQueues[$mqName]['cNum'] ?? 0);

                if (!isset($newQueues[$mqName])) {
                    // 场景1：队列被删除 -> 停止所有消费者
                    for ($i = 1; $i <= $oldN; $i++) $this->stopConsumer($vHost, $mqName, $i);
                    unset($consumers[$vHost][$mqName]);
                } elseif (!isset($oldQueues[$mqName])) {
                    // 场景2：新增队列 -> 启动消费者
                    $consumers[$vHost][$mqName] = [];
                    for ($i = 1; $i <= $newN; $i++) {
                        $this->startConsumer($vHost, $mqName, $i);
                        $consumers[$vHost][$mqName][$i] = time();
                    }
                } elseif ($newN > $oldN) {
                    // 场景3：增加消费者数量
                    for ($i = $oldN + 1; $i <= $newN; $i++) {
                        $this->startConsumer($vHost, $mqName, $i);
                        $consumers[$vHost][$mqName][$i] = time();
                    }
                } elseif ($newN < $oldN) {
                    // 场景4：减少消费者数量（优雅停止多余的）
                    for ($i = $oldN; $i > $newN; $i--) {
                        $this->stopConsumer($vHost, $mqName, $i);
                        unset($consumers[$vHost][$mqName][$i]);
                    }
                }
            }

            // 如果虚拟主机被删除
            if (empty($newQueues)) {
                unset($consumers[$vHost]);
            }
        }
    }

    /**
     * 健康检查：检测并重启异常消费者
     * 
     * 通过检查 lock 文件和进程状态判断消费者是否存活
     * 异常情况（进程不存在但应该存在）会自动重启
     */
    protected function checkConsumersHealth(&$consumers)
    {
        foreach ($this->mqConfig as $vHost => $queues) {
            if (!isset($consumers[$vHost])) $consumers[$vHost] = [];

            foreach ($queues as $mqName => $config) {
                $cNum = (int)($config['cNum'] ?? 1);
                if (!isset($consumers[$vHost][$mqName])) $consumers[$vHost][$mqName] = [];

                for ($i = 1; $i <= $cNum; $i++) {
                    // 检查消费者进程是否存活
                    if (!$this->isConsumerRunning($mqName, $i, $vHost)) {
                        echo "[WARN] 消费者 {$vHost}/{$mqName}#{$i} 异常，重启中...\n";
                        $this->startConsumer($vHost, $mqName, $i);
                        $consumers[$vHost][$mqName][$i] = time();
                    }
                }
            }
        }
    }

    /**
     * 停止单个消费者
     * 
     * @param bool $force true=强制停止（立即kill），false=优雅停止（等待当前消息处理完）
     */
    protected function stopConsumer($vHost, $mqName, $consumerId, $force = false)
    {
        $lockFile = $this->getConsumerLockFile($mqName, $consumerId, $vHost);
        $lock = $this->readLockFile($lockFile);
        if (!$lock) return;

        // 设置停止标志，消费者循环会检查此标志
        $lock['stopping'] = true;
        $lock['force'] = $force;
        file_put_contents($lockFile, json_encode($lock));

        // 强制停止：直接 kill 进程
        if ($force && !empty($lock['pid'])) {
            $this->killProcess($lock['pid']);
            $this->deleteLockFile($lockFile);
        }
    }

    /**
     * 停止所有消费者
     * 
     * 优雅停止时会等待最多30秒，让消费者处理完当前消息
     */
    protected function stopAllConsumers(&$consumers, $force = false)
    {
        // 向所有消费者发送停止信号
        foreach ($consumers as $vHost => $queues) {
            foreach ($queues as $mqName => $list) {
                foreach ($list as $id => $time) {
                    $this->stopConsumer($vHost, $mqName, $id, $force);
                }
            }
        }

        // 优雅停止：等待消费者自行退出（最多30秒）
        if (!$force) {
            for ($i = 0; $i < 30; $i++) {
                $allStopped = true;
                foreach ($consumers as $vHost => $queues) {
                    foreach ($queues as $mqName => $list) {
                        foreach ($list as $id => $time) {
                            if ($this->isConsumerRunning($mqName, $id, $vHost)) {
                                $allStopped = false;
                                break 3;
                            }
                        }
                    }
                }
                if ($allStopped) break;
                sleep(1);
            }
        }
    }

    /**
     * 执行回调（仅返回布尔值，用于内部逻辑）
     * 
     * @return bool true=消费成功, false=消费失败(消息放回队列)
     */
    protected function executeCallback($config, $message)
    {
        if (empty($config['call'])) return true;

        try {
            $cb = $config['call'];
            $result = false;

            if (is_string($cb) && strpos($cb, '::') !== false) {
                list($class, $method) = explode('::', $cb);

                // 检查类是否存在
                if (!class_exists($class)) {
                    echo "[ERROR] 回调类不存在: {$class}\n";
                    return false;
                }

                // 检查方法是否存在
                if (!method_exists($class, $method)) {
                    echo "[ERROR] 回调方法不存在: {$class}::{$method}\n";
                    return false;
                }

                $result = call_user_func([$class, $method], $message);
            } elseif (is_callable($cb)) {
                $result = call_user_func($cb, $message);
            } else {
                echo "[ERROR] 回调不可调用: " . print_r($cb, true) . "\n";
                return false;
            }

            return $result === true;
        } catch (\Throwable $e) {
            // 异常时记录错误，返回false进入重试
            echo "[ERROR] 回调异常: " . $e->getMessage() . "\n";
            echo "[ERROR] 堆栈: " . $e->getTraceAsString() . "\n";
            return false;
        }
    }

    // ==================== 工具方法 ====================

    protected function getMasterLockFile()
    {
        return $this->lockPath . '/master.lock';
    }

    protected function getConsumerLockFile($mqName, $id, $vHost = 'default')
    {
        return $this->lockPath . "/consumer_{$vHost}_{$mqName}_{$id}.lock";
    }

    protected function createLockFile($file, $data = [])
    {
        $data['pid'] = getmypid();
        $data['time'] = time();
        file_put_contents($file, json_encode($data));
    }

    protected function readLockFile($file)
    {
        if (!is_file($file)) return null;
        $c = @file_get_contents($file);
        return $c ? json_decode($c, true) : null;
    }

    protected function updateLockFile($file, $updates)
    {
        $data = $this->readLockFile($file);
        if ($data) file_put_contents($file, json_encode(array_merge($data, $updates)));
    }

    protected function deleteLockFile($file)
    {
        if (is_file($file)) @unlink($file);
    }

    protected function cleanAllLockFiles()
    {
        foreach (glob($this->lockPath . '/*.lock') as $f) @unlink($f);
    }

    protected function isProcessRunning($pid)
    {
        if (empty($pid)) return false;
        if ($this->isWindows) {
            exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $out);
            return strpos(implode('', $out), (string)$pid) !== false;
        }
        return posix_kill($pid, 0);
    }

    protected function killProcess($pid)
    {
        if (empty($pid)) return;
        if ($this->isWindows) {
            exec("taskkill /F /PID {$pid} 2>NUL");
        } else {
            posix_kill($pid, SIGKILL);
        }
    }

    protected function isMasterRunning()
    {
        $lock = $this->readLockFile($this->getMasterLockFile());
        return $lock && $this->isProcessRunning($lock['pid']);
    }

    protected function isConsumerRunning($mqName, $id, $vHost = 'default')
    {
        $lock = $this->readLockFile($this->getConsumerLockFile($mqName, $id, $vHost));
        return $lock && $this->isProcessRunning($lock['pid']);
    }

    protected function isConfigChanged()
    {
        if (!is_file($this->configFile)) return false;
        clearstatcache(true, $this->configFile);
        return filemtime($this->configFile) !== $this->configMtime;
    }

    protected function getPhpCliBinary()
    {
        $php = PHP_BINARY;
        if ($this->isWindows && stripos($php, 'php-cgi') !== false) {
            $cli = str_ireplace(['php-cgi.exe', 'php-cgi'], ['php.exe', 'php'], $php);
            if (is_file($cli)) return $cli;
        }
        return $php;
    }

    protected function getArg($name)
    {
        global $argv;
        foreach ($argv ?? [] as $arg) {
            if (strpos($arg, "--{$name}=") === 0) {
                return substr($arg, strlen("--{$name}="));
            }
        }
        return null;
    }

    protected function isHttpRequest()
    {
        return php_sapi_name() !== 'cli' && !empty($_SERVER['REQUEST_METHOD']);
    }

    protected function response($code, $msg, $data = [])
    {
        if ($this->isHttpRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['code' => $code, 'message' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo ($code ? "[ERROR] " : "[INFO] ") . $msg . "\n";
    }

    protected function getStatusData()
    {
        $masterLock = $this->readLockFile($this->getMasterLockFile());
        $masterRunning = $masterLock && $this->isProcessRunning($masterLock['pid']);

        $status = [
            'master' => [
                'running' => $masterRunning,
                'pid' => $masterRunning ? $masterLock['pid'] : null,
                'startTime' => $masterRunning && isset($masterLock['time']) ? date('Y-m-d H:i:s', $masterLock['time']) : null
            ],
            'vhosts' => []
        ];

        foreach ($this->mqConfig as $vHost => $queues) {
            // 设置虚拟主机以获取正确的数据库连接
            MqManager::setVHost($vHost);

            $vhostData = [
                'queues' => []
            ];

            foreach ($queues as $mqName => $config) {
                $cNum = (int)($config['cNum'] ?? 1);
                $msgCount = MqManager::length($mqName);

                $queue = [
                    'call' => $config['call'],
                    'messageCount' => $msgCount,
                    'consumerConfig' => $cNum,
                    'consumers' => [],
                    'runningCount' => 0
                ];

                for ($i = 1; $i <= $cNum; $i++) {
                    $cLock = $this->readLockFile($this->getConsumerLockFile($mqName, $i, $vHost));
                    $running = $cLock && $this->isProcessRunning($cLock['pid']);
                    $queue['consumers'][] = [
                        'id' => $i,
                        'running' => $running,
                        'pid' => $running ? $cLock['pid'] : null,
                        'busy' => $running && !empty($cLock['busy'])
                    ];
                    if ($running) $queue['runningCount']++;
                }

                $vhostData['queues'][$mqName] = $queue;
            }

            $status['vhosts'][$vHost] = $vhostData;
        }

        return $status;
    }

    // ==================== HTML 页面 ====================

    protected function renderHtml($status)
    {
        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>消息队列管理</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; padding: 15px; }
        .container { max-width: 1800px; margin: 0 auto; }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; flex-wrap: wrap; gap: 10px; }
        .header h1 { color: #333; font-size: 20px; display: flex; align-items: center; gap: 8px; }
        .help-icon { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; background: #1890ff; color: #fff; border-radius: 50%; font-size: 12px; cursor: help; position: relative; }
        .help-icon:hover .help-tooltip { display: block; }
        .help-tooltip { display: none; position: absolute; top: 28px; left: 50%; transform: translateX(-50%); width: 500px; background: #fff; border: 1px solid #e8e8e8; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); padding: 15px; z-index: 1000; font-size: 12px; font-weight: normal; text-align: left; color: #333; }
        .help-tooltip::before { content: ''; position: absolute; top: -6px; left: 50%; transform: translateX(-50%); border: 6px solid transparent; border-top: none; border-bottom-color: #fff; }
        .help-tooltip pre { background: #f6f8fa; padding: 10px; border-radius: 4px; font-size: 11px; overflow-x: auto; white-space: pre-wrap; margin: 8px 0; }
        .help-tooltip code { color: #e83e8c; }
        .help-tooltip strong { color: #1890ff; }
        
        .card { background: #fff; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 15px; margin-bottom: 15px; }
        .master-bar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .status-info { display: flex; align-items: center; gap: 8px; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; }
        .status-dot.running { background: #52c41a; box-shadow: 0 0 6px rgba(82,196,26,0.5); }
        .status-dot.stopped { background: #ff4d4f; }
        .status-text { font-size: 14px; }
        .status-text.running { color: #52c41a; }
        .status-text.stopped { color: #ff4d4f; }
        
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; transition: all 0.2s; text-decoration: none; display: inline-block; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-primary { background: #1890ff; color: #fff; }
        .btn-primary:hover:not(:disabled) { background: #40a9ff; }
        .btn-danger { background: #ff4d4f; color: #fff; }
        .btn-danger:hover:not(:disabled) { background: #ff7875; }
        .btn-warning { background: #faad14; color: #fff; }
        .btn-warning:hover:not(:disabled) { background: #ffc53d; }
        .btn-default { background: #f0f0f0; color: #666; }
        .btn-default:hover { background: #e0e0e0; }
        .btn-purple { background: #722ed1; color: #fff; }
        .btn-purple:hover { background: #9254de; }
        
        /* 表格样式 */
        .queue-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .queue-table th, .queue-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #f0f0f0; }
        .queue-table th { background: #fafafa; font-weight: 600; color: #666; white-space: nowrap; position: sticky; top: 0; }
        .queue-table tr:hover { background: #fafafa; }
        .queue-table .vhost-cell { background: #f0f5ff; vertical-align: middle; text-align: center; border-right: 2px solid #1890ff; }
        .vhost-tag { display: inline-block; padding: 4px 10px; background: #1890ff; color: #fff; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .queue-name { font-weight: 600; color: #333; }
        .msg-count { display: inline-block; min-width: 30px; text-align: center; background: #e6f7ff; color: #1890ff; padding: 2px 8px; border-radius: 10px; font-weight: 500; }
        .status-ok { color: #52c41a; font-weight: 500; }
        .status-warn { color: #faad14; font-weight: 500; }
        .status-error { color: #ff4d4f; font-weight: 500; }
        .consumer-list { font-size: 12px; color: #666; max-width: 200px; }
        .text-danger { color: #ff4d4f; }
        .callback-cell { font-family: monospace; font-size: 11px; color: #666; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .action-cell { white-space: nowrap; }
        .btn-mini { padding: 3px 8px; font-size: 11px; background: #f5f5f5; border: 1px solid #d9d9d9; border-radius: 3px; cursor: pointer; margin-right: 4px; }
        .btn-mini:hover { background: #e6f7ff; border-color: #1890ff; color: #1890ff; }
        
        .table-wrapper { max-height: 600px; overflow-y: auto; border: 1px solid #e8e8e8; border-radius: 4px; }
        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .card-header h3 { font-size: 15px; color: #333; }
        
        .toast { position: fixed; top: 20px; right: 20px; padding: 10px 16px; border-radius: 4px; color: #fff; font-size: 13px; z-index: 1001; animation: slideIn 0.3s; }
        .toast.success { background: #52c41a; }
        .toast.error { background: #ff4d4f; }
        .toast.info { background: #1890ff; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .empty-tip { text-align: center; padding: 40px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                消息队列管理
                <span class="help-icon">?
                    <div class="help-tooltip">
                        <strong>1. 配置队列</strong> - 编辑 <code>system/config/mq.php</code>
<pre>return [
    'default' => [  // 虚拟主机
        'testMq1' => [
            'cNum' => 3,  // 消费者进程数
            'call' => 'app\\system\\TestController::testMq1'
        ],
    ],
];</pre>
                        <strong>2. 发送消息</strong>
<pre>use app\\system\\MqManager;
MqManager::set('testMq1', ['data' => '内容']);
// 指定虚拟主机
MqManager::set('testMq2', ['data' => '内容'], 'iscs');</pre>
                        <strong>3. CLI命令</strong>
<pre>php index.php system/mq/daemon  # 后台启动
php index.php system/mq/stop    # 停止
php index.php system/mq/status  # 查看状态</pre>
                    </div>
                </span>
            </h1>
            <div class="actions">
                <button onclick="doAction('start')" class="btn btn-primary">启动</button>
                <button onclick="doAction('stop')" class="btn btn-danger">停止</button>
                <button onclick="doAction('restart')" class="btn btn-warning">重启</button>
                <button onclick="refresh()" class="btn btn-default">刷新</button>
                <a href="/system/mq/list" class="btn btn-purple">消息列表</a>
            </div>
        </div>
        
        <div class="card" id="masterCard">
            <div class="master-bar">
                <div class="status-info">
                    <div class="status-dot stopped" id="masterDot"></div>
                    <span class="status-text stopped" id="masterText">主进程: 加载中...</span>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3>队列列表</h3>
            </div>
            <div class="table-wrapper">
                <table class="queue-table">
                    <thead>
                        <tr>
                            <th style="width:80px;">虚拟主机</th>
                            <th style="width:140px;">队列名称</th>
                            <th>回调方法</th>
                            <th style="width:80px;">消费者</th>
                            <th style="width:200px;">运行状态</th>
                            <th style="width:80px;">待处理消息数</th>
                            <th style="width:100px;">发送测试</th>
                        </tr>
                    </thead>
                    <tbody id="queueList">
                        <tr><td colspan="7" class="empty-tip">加载中...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function showToast(msg, type = 'info') {
            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            toast.textContent = msg;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        
        async function doAction(action) {
            const btns = document.querySelectorAll('.btn');
            btns.forEach(b => b.disabled = true);
            
            try {
                const res = await fetch('/system/mq/' + action);
                const data = await res.json();
                showToast(data.message, data.code === 0 ? 'success' : 'error');
                setTimeout(() => loadStatus(), 1000);
            } catch (e) {
                showToast('请求失败: ' + e.message, 'error');
            }
            btns.forEach(b => b.disabled = false);
        }
        
        async function sendTest(vhost, queue, count = 1) {
            try {
                const res = await fetch('/system/mq/test?vhost=' + encodeURIComponent(vhost) + '&queue=' + encodeURIComponent(queue) + '&count=' + count);
                const data = await res.json();
                showToast(data.message, data.code === 0 ? 'success' : 'error');
                setTimeout(() => loadStatus(), 500);
            } catch (e) {
                showToast('发送失败: ' + e.message, 'error');
            }
        }
        
        function refresh() { loadStatus(); }
        
        // 异步加载状态数据
        async function loadStatus() {
            try {
                const res = await fetch('/system/mq/statusData');
                const result = await res.json();
                if (result.code === 0) {
                    renderStatus(result.data);
                }
            } catch (e) {
                showToast('加载状态失败', 'error');
            }
        }
        
        // 渲染状态数据
        function renderStatus(status) {
            // 主进程状态
            const masterDot = document.getElementById('masterDot');
            const masterText = document.getElementById('masterText');
            const masterClass = status.master.running ? 'running' : 'stopped';
            masterDot.className = 'status-dot ' + masterClass;
            masterText.className = 'status-text ' + masterClass;
            masterText.textContent = status.master.running 
                ? '主进程: 运行中 (PID: ' + status.master.pid + ')' 
                : '主进程: 已停止';
            
            // 队列列表
            let html = '';
            for (const vHost in status.vhosts) {
                const vhostData = status.vhosts[vHost];
                const queueNames = Object.keys(vhostData.queues);
                const queueCount = queueNames.length;
                
                queueNames.forEach((mqName, rowIndex) => {
                    const queue = vhostData.queues[mqName];
                    
                    // 消费者状态
                    let runningPids = [];
                    let stoppedCount = 0;
                    queue.consumers.forEach(c => {
                        if (c.running) {
                            runningPids.push('#' + c.id + (c.busy ? '(忙)' : ''));
                        } else {
                            stoppedCount++;
                        }
                    });
                    let consumerText = runningPids.length ? runningPids.join(', ') : '-';
                    if (stoppedCount > 0) {
                        consumerText += (consumerText !== '-' ? ', ' : '') + "<span class='text-danger'>" + stoppedCount + "个已停止</span>";
                    }
                    
                    const statusClass = queue.runningCount === queue.consumerConfig 
                        ? 'status-ok' 
                        : (queue.runningCount > 0 ? 'status-warn' : 'status-error');
                    
                    const vhostCell = rowIndex === 0 
                        ? "<td class='vhost-cell' rowspan='" + queueCount + "'><span class='vhost-tag'>" + vHost + "</span></td>" 
                        : '';
                    
                    html += "<tr>" + vhostCell +
                        "<td class='queue-name'>" + mqName + "</td>" +
                        "<td class='callback-cell' title='" + queue.call + "'>" + queue.call + "</td>" +
                        "<td><span class='" + statusClass + "'>" + queue.runningCount + "/" + queue.consumerConfig + "</span></td>" +
                        "<td class='consumer-list'>" + consumerText + "</td>" +
                        "<td><span class='msg-count'>" + queue.messageCount + "</span></td>" +
                        "<td class='action-cell'>" +
                            "<button onclick=\"sendTest('" + vHost + "', '" + mqName + "')\" class='btn-mini'>+1</button>" +
                            "<button onclick=\"sendTest('" + vHost + "', '" + mqName + "', 10)\" class='btn-mini'>+10</button>" +
                        "</td></tr>";
                });
            }
            
            document.getElementById('queueList').innerHTML = html || '<tr><td colspan="7" class="empty-tip">暂无队列配置</td></tr>';
        }
        
        // 页面加载时自动获取状态
        loadStatus();
    </script>
</body>
</html>
HTML;
        exit;
    }

    /**
     * 渲染消息列表页面
     */
    protected function renderListHtml()
    {
        echo <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>消息队列列表</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; padding: 20px; }
        .container { max-width: 1800px; margin: 0 auto; }
        h1 { color: #333; margin-bottom: 20px; font-size: 24px; display: flex; align-items: center; gap: 15px; }
        h1 a { font-size: 14px; color: #1890ff; text-decoration: none; }
        h1 a:hover { text-decoration: underline; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
        
        /* 筛选区域 */
        .filter-bar { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-item { display: flex; flex-direction: column; gap: 5px; }
        .filter-item label { font-size: 13px; color: #666; font-weight: 500; }
        .filter-item select, .filter-item input { 
            padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 4px; font-size: 14px; min-width: 150px;
            transition: border-color 0.2s;
        }
        .filter-item select:focus, .filter-item input:focus { border-color: #1890ff; outline: none; }
        
        /* 按钮样式 */
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; transition: all 0.2s; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-primary { background: #1890ff; color: #fff; }
        .btn-primary:hover:not(:disabled) { background: #40a9ff; }
        .btn-success { background: #52c41a; color: #fff; }
        .btn-success:hover:not(:disabled) { background: #73d13d; }
        .btn-danger { background: #ff4d4f; color: #fff; }
        .btn-danger:hover:not(:disabled) { background: #ff7875; }
        .btn-sm { padding: 4px 8px; font-size: 12px; }
        .btn-link { background: none; color: #1890ff; padding: 4px 8px; }
        .btn-link:hover { background: #e6f7ff; }
        .btn-view { background: #722ed1; color: #fff; }
        .btn-view:hover { background: #9254de; }
        
        /* 表格样式 */
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #f0f0f0; }
        th { background: #fafafa; font-weight: 600; color: #333; white-space: nowrap; }
        tr:hover { background: #fafafa; }
        
        /* 状态标签 */
        .tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
        .tag-success { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .tag-warning { background: #fffbe6; color: #faad14; border: 1px solid #ffe58f; }
        .tag-error { background: #fff2f0; color: #ff4d4f; border: 1px solid #ffccc7; }
        .tag-info { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        
        /* 数据展示 */
        .data-preview { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: monospace; font-size: 12px; color: #666; cursor: pointer; }
        .data-preview:hover { color: #1890ff; }
        .msg-id { font-family: monospace; font-size: 12px; color: #666; }
        .queue-name { font-weight: 500; color: #333; }
        .time-cell { font-size: 12px; color: #999; white-space: nowrap; }
        
        /* 分页 */
        .pagination { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; flex-wrap: wrap; gap: 15px; }
        .pagination-info { font-size: 13px; color: #666; }
        .pagination-btns { display: flex; gap: 5px; }
        .pagination-btns button { min-width: 36px; }
        
        /* 操作按钮组 */
        .action-btns { display: flex; gap: 5px; }
        
        /* 弹窗 */
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; border-radius: 8px; max-width: 900px; width: 90%; max-height: 85vh; overflow: hidden; display: flex; flex-direction: column; }
        .modal-header { padding: 16px 20px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 16px; color: #333; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #999; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 4px; }
        .modal-close:hover { color: #333; background: #f0f0f0; }
        .modal-body { padding: 20px; overflow-y: auto; flex: 1; }
        .modal-body pre { background: #f6f8fa; padding: 15px; border-radius: 6px; font-size: 13px; overflow-x: auto; white-space: pre-wrap; word-break: break-all; }
        .modal-footer { padding: 16px 20px; border-top: 1px solid #f0f0f0; display: flex; justify-content: flex-end; gap: 10px; }
        
        /* 执行结果样式 */
        .exec-result { margin-bottom: 15px; }
        .exec-result .status { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .exec-result .status-dot { width: 12px; height: 12px; border-radius: 50%; }
        .exec-result .status-dot.success { background: #52c41a; }
        .exec-result .status-dot.error { background: #ff4d4f; }
        .exec-result .status-text { font-weight: 600; }
        .exec-result .status-text.success { color: #52c41a; }
        .exec-result .status-text.error { color: #ff4d4f; }
        .exec-result .info-row { margin-bottom: 8px; font-size: 14px; }
        .exec-result .info-row label { color: #666; display: inline-block; width: 100px; }
        .exec-result .section { margin-top: 15px; }
        .exec-result .section h4 { font-size: 13px; color: #666; margin-bottom: 8px; }
        .exec-result .output { background: #f6f8fa; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 12px; white-space: pre-wrap; word-break: break-all; max-height: 200px; overflow-y: auto; }
        .exec-result .output.error { background: #fff2f0; border: 1px solid #ffccc7; color: #ff4d4f; }
        
        /* Toast */
        .toast { position: fixed; top: 20px; right: 20px; padding: 12px 20px; border-radius: 4px; color: #fff; font-size: 14px; z-index: 1001; animation: slideIn 0.3s; }
        .toast.success { background: #52c41a; }
        .toast.error { background: #ff4d4f; }
        .toast.info { background: #1890ff; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        /* 加载中 */
        .loading { text-align: center; padding: 40px; color: #999; }
        
        /* 空状态 */
        .empty { text-align: center; padding: 60px 20px; color: #999; }
        .empty-icon { font-size: 48px; margin-bottom: 15px; }
        
        /* 统计信息 */
        .stats { display: flex; gap: 20px; margin-bottom: 15px; flex-wrap: wrap; }
        .stat-item { font-size: 13px; color: #666; }
        .stat-item strong { color: #333; }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            消息队列列表
            <a href="/system/mq/index">返回管理页</a>
        </h1>
        
        <!-- 筛选区域 -->
        <div class="card">
            <div class="filter-bar">
                <div class="filter-item">
                    <label>虚拟主机</label>
                    <select id="filterVHost">
                        <option value="">全部主机</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label>队列名称</label>
                    <input type="text" id="filterName" placeholder="输入队列名称">
                </div>
                <div class="filter-item">
                    <label>队列组</label>
                    <select id="filterGroup">
                        <option value="">全部分组</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label>消息ID</label>
                    <input type="text" id="filterMsgId" placeholder="输入消息ID">
                </div>
                <div class="filter-item">
                    <label>消息内容</label>
                    <input type="text" id="filterData" placeholder="模糊搜索内容">
                </div>
                <div class="filter-item">
                    <label>锁定状态</label>
                    <select id="filterLocked">
                        <option value="">全部</option>
                        <option value="0">未锁定</option>
                        <option value="1">已锁定</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label>重试等级</label>
                    <select id="filterSyncLevel">
                        <option value="">全部</option>
                        <option value="0">0 (新消息)</option>
                        <option value="1">1 (重试1次)</option>
                        <option value="2">2 (重试2次)</option>
                        <option value="3">3+ (多次重试)</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label>&nbsp;</label>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="loadMessages()">搜索</button>
                        <button class="btn" onclick="resetFilter()">重置</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 列表区域 -->
        <div class="card">
            <div class="stats" id="stats"></div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>虚拟主机</th>
                            <th>队列名称</th>
                            <th>队列组</th>
                            <th>消息ID</th>
                            <th>重试</th>
                            <th>状态</th>
                            <th>锁定时间</th>
                            <th>创建时间</th>
                            <th>更新时间</th>
                            <th style="width: 360px;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="messageList">
                        <tr><td colspan="11" class="loading">加载中...</td></tr>
                    </tbody>
                </table>
            </div>
            
            <!-- 分页 -->
            <div class="pagination">
                <div class="pagination-info" id="pageInfo">共 0 条</div>
                <div class="pagination-btns">
                    <button class="btn btn-sm" id="btnFirst" onclick="goPage(1)">首页</button>
                    <button class="btn btn-sm" id="btnPrev" onclick="goPage(currentPage - 1)">上一页</button>
                    <span style="padding: 0 10px; line-height: 28px;" id="pageNum">1 / 1</span>
                    <button class="btn btn-sm" id="btnNext" onclick="goPage(currentPage + 1)">下一页</button>
                    <button class="btn btn-sm" id="btnLast" onclick="goPage(totalPages)">末页</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 详情弹窗 -->
    <div class="modal" id="detailModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>消息详情</h3>
                <button class="modal-close" onclick="closeModal('detailModal')">&times;</button>
            </div>
            <div class="modal-body">
                <pre id="detailContent"></pre>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeModal('detailModal')">关闭</button>
            </div>
        </div>
    </div>
    
    <!-- 执行结果弹窗 -->
    <div class="modal" id="execModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>执行结果</h3>
                <button class="modal-close" onclick="closeModal('execModal')">&times;</button>
            </div>
            <div class="modal-body" id="execResultBody">
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="closeModal('execModal');loadMessages();">关闭并刷新</button>
            </div>
        </div>
    </div>
    
    <script>
        let currentPage = 1;
        let totalPages = 1;
        let pageSize = 20;
        
        // 显示Toast
        function showToast(msg, type = 'info') {
            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            toast.textContent = msg;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        
        // 加载消息列表
        async function loadMessages() {
            const params = new URLSearchParams();
            params.append('page', currentPage);
            params.append('pageSize', pageSize);
            
            const vhost = document.getElementById('filterVHost').value;
            const name = document.getElementById('filterName').value;
            const group = document.getElementById('filterGroup').value;
            const msgId = document.getElementById('filterMsgId').value;
            const data = document.getElementById('filterData').value;
            const locked = document.getElementById('filterLocked').value;
            const syncLevel = document.getElementById('filterSyncLevel').value;
            
            if (vhost) params.append('vhost', vhost);
            if (name) params.append('name', name);
            if (group) params.append('group', group);
            if (msgId) params.append('msgId', msgId);
            if (data) params.append('data', data);
            if (locked !== '') params.append('locked', locked);
            if (syncLevel !== '') params.append('syncLevel', syncLevel);
            
            document.getElementById('messageList').innerHTML = '<tr><td colspan="11" class="loading">加载中...</td></tr>';
            
            try {
                const res = await fetch('/system/mq/messages?' + params.toString());
                const result = await res.json();
                
                if (result.code !== 0) {
                    showToast(result.message, 'error');
                    return;
                }
                
                const respData = result.data;
                totalPages = respData.totalPages || 1;
                
                // 更新筛选下拉框
                updateFilterOptions('filterVHost', respData.vhosts || []);
                updateFilterOptions('filterGroup', respData.groups || []);
                
                // 更新统计
                document.getElementById('stats').innerHTML = `
                    <div class="stat-item">共 <strong>${respData.total}</strong> 条消息</div>
                `;
                
                // 更新分页信息
                document.getElementById('pageInfo').textContent = `共 ${respData.total} 条，每页 ${pageSize} 条`;
                document.getElementById('pageNum').textContent = `${currentPage} / ${totalPages}`;
                
                // 更新按钮状态
                document.getElementById('btnFirst').disabled = currentPage <= 1;
                document.getElementById('btnPrev').disabled = currentPage <= 1;
                document.getElementById('btnNext').disabled = currentPage >= totalPages;
                document.getElementById('btnLast').disabled = currentPage >= totalPages;
                
                // 渲染列表
                renderList(respData.list || []);
                
            } catch (e) {
                showToast('加载失败: ' + e.message, 'error');
                document.getElementById('messageList').innerHTML = '<tr><td colspan="11" class="empty"><div class="empty-icon">❌</div>加载失败</td></tr>';
            }
        }
        
        // 更新筛选下拉框选项
        function updateFilterOptions(id, options) {
            const select = document.getElementById(id);
            if (!select) return;
            const currentValue = select.value;
            const firstOption = select.options[0];
            
            select.innerHTML = '';
            select.appendChild(firstOption);
            
            options.forEach(opt => {
                const option = document.createElement('option');
                option.value = opt;
                option.textContent = opt;
                if (opt === currentValue) option.selected = true;
                select.appendChild(option);
            });
        }
        
        // 渲染列表
        function renderList(list) {
            const tbody = document.getElementById('messageList');
            
            if (!list || list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" class="empty"><div class="empty-icon">📭</div>暂无消息</td></tr>';
                return;
            }
            
            let html = '';
            list.forEach((item, index) => {
                const rowNum = (currentPage - 1) * pageSize + index + 1;
                const vHost = item.vHost || 'default';
                
                // 状态标签
                let statusTag = '';
                if (item.lockMark) {
                    statusTag = '<span class="tag tag-warning">锁定中</span>';
                } else if (item.syncLevel > 0) {
                    statusTag = `<span class="tag tag-error">重试${item.syncLevel}次</span>`;
                } else {
                    statusTag = '<span class="tag tag-success">待处理</span>';
                }
                
                // 是否为失败消息（重试次数>0且未锁定）
                const isFailed = item.syncCount > 0 && !item.lockMark;
                
                html += `
                <tr>
                    <td>${rowNum}</td>
                    <td><span class="tag tag-info">${escapeHtml(vHost)}</span></td>
                    <td><span class="queue-name">${escapeHtml(item.name)}</span></td>
                    <td><span class="tag tag-info">${escapeHtml(item.group)}</span></td>
                    <td><span class="msg-id">${escapeHtml(item.msgId)}</span></td>
                    <td>${item.syncCount}</td>
                    <td>${statusTag}</td>
                    <td class="time-cell">${item.lockTime || '-'}</td>
                    <td class="time-cell">${item.createTime}</td>
                    <td class="time-cell">${item.updateTime}</td>
                    <td>
                        <div class="action-btns">
                            <button class="btn btn-sm btn-view" onclick="showDataModal('${escapeAttr(vHost)}', '${escapeAttr(item.name)}', '${escapeAttr(item.unqid)}')">查看数据</button>
                            <button class="btn btn-sm btn-success" onclick="executeMessage('${escapeAttr(vHost)}', '${escapeAttr(item.name)}', '${escapeAttr(item.unqid)}')" ${item.lockMark ? 'disabled' : ''}>执行</button>
                            <button class="btn btn-sm btn-primary" onclick="executeInNewPage('${escapeAttr(vHost)}', '${escapeAttr(item.name)}', '${escapeAttr(item.unqid)}')" ${item.lockMark ? 'disabled' : ''}>新页面执行</button>
                            <button class="btn btn-sm btn-warning" onclick="resetMessage('${escapeAttr(vHost)}', '${escapeAttr(item.name)}', '${escapeAttr(item.unqid)}')" ${isFailed ? '' : 'disabled'}>重置</button>
                            <button class="btn btn-sm btn-danger" onclick="deleteMessage('${escapeAttr(vHost)}', '${escapeAttr(item.name)}', '${escapeAttr(item.unqid)}')" ${item.lockMark ? 'disabled' : ''}>删除</button>
                        </div>
                    </td>
                </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }
        
        // HTML转义
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/[&<>"']/g, m => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[m]));
        }
        
        // 属性转义
        function escapeAttr(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/'/g, "\\'").replace(/"/g, '\\"');
        }
        
        // 显示数据弹窗
        async function showDataModal(vhost, name, unqid) {
            try {
                const res = await fetch(`/system/mq/detail?vhost=${encodeURIComponent(vhost)}&name=${encodeURIComponent(name)}&unqid=${encodeURIComponent(unqid)}`);
                const result = await res.json();
                
                if (result.code !== 0) {
                    showToast(result.message, 'error');
                    return;
                }
                
                document.getElementById('detailContent').textContent = JSON.stringify(result.data, null, 2);
                document.getElementById('detailModal').classList.add('show');
            } catch (e) {
                showToast('获取详情失败: ' + e.message, 'error');
            }
        }
        
        // 关闭弹窗
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }
        
        // 新页面执行
        function executeInNewPage(vhost, name, unqid) {
            window.open(`/system/mq/run?vhost=${encodeURIComponent(vhost)}&name=${encodeURIComponent(name)}&unqid=${encodeURIComponent(unqid)}`, '_blank');
        }
        
        // 显示执行结果弹窗
        function showExecResult(result) {
            const data = result.data || {};
            const isSuccess = result.code === 0;
            const statusClass = isSuccess ? 'success' : 'error';
            const statusText = isSuccess ? '执行成功' : '执行失败';
            
            // 格式化返回值显示
            let returnValueHtml = '';
            const rv = data.returnValue;
            if (rv === true) {
                returnValueHtml = '<span style="color:#52c41a;font-weight:600;">true (成功)</span>';
            } else if (rv === false) {
                returnValueHtml = '<span style="color:#ff4d4f;font-weight:600;">false (失败)</span>';
            } else if (typeof rv === 'number' && rv > 0) {
                returnValueHtml = `<span style="color:#faad14;font-weight:600;">${rv} (延迟${rv}秒后重试)</span>`;
            } else if (rv === null || rv === undefined) {
                returnValueHtml = '<span style="color:#999;">null (未执行)</span>';
            } else {
                returnValueHtml = `<span style="color:#faad14;">${escapeHtml(String(rv))}</span>`;
            }
            
            let html = `
                <div class="exec-result">
                    <div class="status">
                        <div class="status-dot ${statusClass}"></div>
                        <span class="status-text ${statusClass}">${statusText}</span>
                    </div>
                    <div class="info-row"><label>结果说明:</label> ${escapeHtml(result.message)}</div>
                    <div class="info-row"><label>消息ID:</label> ${escapeHtml(data.msgId || '-')}</div>
                    <div class="info-row"><label>队列名称:</label> ${escapeHtml(data.name || '-')}</div>
                    <div class="info-row"><label>回调返回值:</label> ${returnValueHtml}</div>
            `;
            
            if (data.output) {
                html += `
                    <div class="section">
                        <h4>回调输出</h4>
                        <div class="output">${escapeHtml(data.output)}</div>
                    </div>
                `;
            }
            
            if (data.error) {
                html += `
                    <div class="section">
                        <h4>错误信息</h4>
                        <div class="output error">${escapeHtml(data.error)}</div>
                    </div>
                `;
            }
            
            html += '</div>';
            
            document.getElementById('execResultBody').innerHTML = html;
            document.getElementById('execModal').classList.add('show');
        }
        
        // 执行消息
        async function executeMessage(vhost, name, unqid) {
            if (!confirm('确定要执行此消息吗？')) return;
            
            try {
                const res = await fetch('/system/mq/execute', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `vhost=${encodeURIComponent(vhost)}&name=${encodeURIComponent(name)}&unqid=${encodeURIComponent(unqid)}`
                });
                const result = await res.json();
                
                // 显示执行结果弹窗
                showExecResult(result);
            } catch (e) {
                showToast('执行失败: ' + e.message, 'error');
            }
        }
        
        // 删除消息
        async function deleteMessage(vhost, name, unqid) {
            if (!confirm('确定要删除此消息吗？此操作不可恢复。')) return;
            
            try {
                const res = await fetch('/system/mq/delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `vhost=${encodeURIComponent(vhost)}&name=${encodeURIComponent(name)}&unqid=${encodeURIComponent(unqid)}`
                });
                const result = await res.json();
                
                showToast(result.message, result.code === 0 ? 'success' : 'error');
                
                if (result.code === 0) {
                    loadMessages();
                }
            } catch (e) {
                showToast('删除失败: ' + e.message, 'error');
            }
        }
        
        // 重置失败消息
        async function resetMessage(vhost, name, unqid) {
            if (!confirm('确定要重置此消息吗？将清零重试次数并立即可被消费。')) return;
            
            try {
                const res = await fetch('/system/mq/reset', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `vhost=${encodeURIComponent(vhost)}&name=${encodeURIComponent(name)}&unqid=${encodeURIComponent(unqid)}`
                });
                const result = await res.json();
                
                showToast(result.message, result.code === 0 ? 'success' : 'error');
                
                if (result.code === 0) {
                    loadMessages();
                }
            } catch (e) {
                showToast('重置失败: ' + e.message, 'error');
            }
        }
        
        // 翻页
        function goPage(page) {
            if (page < 1 || page > totalPages) return;
            currentPage = page;
            loadMessages();
        }
        
        // 重置筛选
        function resetFilter() {
            document.getElementById('filterVHost').value = '';
            document.getElementById('filterName').value = '';
            document.getElementById('filterGroup').value = '';
            document.getElementById('filterMsgId').value = '';
            document.getElementById('filterData').value = '';
            document.getElementById('filterLocked').value = '';
            document.getElementById('filterSyncLevel').value = '';
            currentPage = 1;
            loadMessages();
        }
        
        // 点击弹窗外部关闭
        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal('detailModal');
        });
        document.getElementById('execModal').addEventListener('click', function(e) {
            if (e.target === this) { closeModal('execModal'); loadMessages(); }
        });
        
        // 回车搜索
        document.getElementById('filterMsgId').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                currentPage = 1;
                loadMessages();
            }
        });
        
        // 初始化加载
        loadMessages();
    </script>
</body>
</html>
HTML;
        exit;
    }
}
