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

    /**
     * 初始化方法
     * 
     * 在控制器实例化时自动调用，初始化进程管理器
     */
    public function init()
    {
        // 初始化进程管理器（设置路径、加载配置等）
        MqManager::initProcessManager();
    }

    /**
     * 获取队列配置（支持多虚拟主机格式）
     * @param string $vHost 虚拟主机
     * @param string $mqName 队列名称
     * @return array|null
     */
    protected function getQueueConfig($vHost, $mqName)
    {
        return MqManager::getQueueConfig($vHost, $mqName);
    }

    /**
     * 获取所有虚拟主机列表
     * @return array
     */
    protected function getVHostList()
    {
        return MqManager::getVHostList();
    }

    /**
     * 获取指定虚拟主机的队列列表
     * @param string $vHost 虚拟主机
     * @return array
     */
    protected function getQueueListByVHost($vHost)
    {
        $config = MqManager::getMqConfig();
        return $config[$vHost] ?? [];
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
        $status = MqManager::getStatusData();
        return $this->response(0, 'success', $status);
    }

    /**
     * 启动主进程
     * 
     * 支持两种调用方式：
     * 1. Web请求：异步启动后台进程，立即返回
     * 2. CLI命令：前台运行，阻塞当前终端，可查看实时日志
     * 
     * @return void|mixed Web请求返回JSON响应，CLI直接运行主进程
     */
    public function start()
    {
        // 检查主进程是否已在运行
        if (MqManager::isMasterRunning()) {
            return $this->response(1, '主进程已在运行中');
        }

        // Web请求：异步启动后台进程
        if ($this->isHttpRequest()) {
            MqManager::startMasterAsync();
            // 等待1秒，确保进程已启动
            usleep(1000000);

            // 再次检查是否启动成功
            if (MqManager::isMasterRunning()) {
                // 通过状态数据获取PID
                $status = MqManager::getStatusData();
                return $this->response(0, '启动成功', ['pid' => $status['master']['pid'] ?? null]);
            }
            return $this->response(1, '启动失败，请检查日志');
        }

        // CLI命令：直接运行主进程（阻塞）
        MqManager::runMaster();
    }

    /**
     * 停止主进程
     * 
     * 支持两种停止方式：
     * 1. 优雅停止（默认）：发送停止信号，等待主进程和消费者处理完当前任务后退出（最多等待60秒）
     * 2. 强制停止（force=1）：立即kill所有进程，不等待任务完成
     * 
     * @return mixed JSON响应（Web请求）或直接输出（CLI）
     */
    public function stop()
    {
        // 获取是否强制停止参数（支持GET/POST/CLI参数）
        // 支持 force=1, force=true, force=yes 等多种形式
        $forceParam = $_GET['force'] ?? $_POST['force'] ?? $this->getArg('force');
        $force = !empty($forceParam) && in_array(strtolower($forceParam), ['1', 'true', 'yes', 'on'], true);

        // 强制停止：立即kill所有进程（包括主进程和所有消费者）
        // 即使主进程未运行，也要停止所有残留的消费者进程
        if ($force) {
            MqManager::forceStopAll();
            // 等待进程完全退出
            sleep(1);
            
            // 检查是否还有进程在运行
            $masterRunning = MqManager::isMasterRunning();
            if ($masterRunning) {
                return $this->response(1, '强制停止失败，主进程可能仍在运行');
            }
            
            // 通过状态数据检查是否还有消费者进程在运行
            $status = MqManager::getStatusData();
            $stillRunning = 0;
            foreach ($status['vhosts'] ?? [] as $vhostData) {
                foreach ($vhostData['queues'] ?? [] as $queue) {
                    foreach ($queue['consumers'] ?? [] as $consumer) {
                        if ($consumer['running'] ?? false) {
                            $stillRunning++;
                        }
                    }
                }
            }
            
            if ($stillRunning > 0) {
                return $this->response(1, "强制停止完成，但仍有 {$stillRunning} 个消费者进程在运行");
            }
            
            return $this->response(0, '已强制停止所有进程');
        }

        // 优雅停止：需要主进程在运行
        if (!MqManager::isMasterRunning()) {
            return $this->response(0, '主进程未运行');
        }

        // 优雅停止：通过设置锁文件中的停止标志，带超时机制
        $success = MqManager::stopMaster(60);
        if ($success) {
            return $this->response(0, '已优雅停止');
        } else {
            // 如果优雅停止失败，尝试强制停止
            MqManager::forceStopAll();
            sleep(1);
            if (MqManager::isMasterRunning()) {
                return $this->response(1, '停止失败，请尝试强制停止');
            }
            return $this->response(0, '优雅停止超时，已自动强制停止');
        }
    }

    /**
     * 重启主进程
     * 
     * 执行流程：
     * 1. 如果主进程在运行，先强制停止主进程和所有消费者
     * 2. 等待1秒确保进程完全退出
     * 3. 重新启动主进程
     * 
     * @return void|mixed Web请求返回JSON响应，CLI直接运行主进程
     */
    public function restart()
    {
        // 记录重启前主进程是否在运行
        $wasRunning = MqManager::isMasterRunning();

        // 如果主进程在运行，先强制停止它
        if ($wasRunning) {
            MqManager::forceStopAll();
            // 等待1秒，确保进程完全退出
            sleep(1);
        }

        // Web请求：异步启动后台进程
        if ($this->isHttpRequest()) {
            MqManager::startMasterAsync();
            // 等待1秒，确保进程已启动
            usleep(1000000);

            // 检查是否启动成功
            if (MqManager::isMasterRunning()) {
                $status = MqManager::getStatusData();
                return $this->response(0, '重启成功', ['pid' => $status['master']['pid'] ?? null]);
            }
            return $this->response(1, '重启失败');
        }

        // CLI命令：直接运行主进程（阻塞）
        MqManager::runMaster();
    }

    /**
     * 查看状态（JSON）
     */
    public function status()
    {
        return $this->response(0, 'success', MqManager::getStatusData());
    }

    /**
     * 发送测试消息
     * 
     * 用于测试队列功能，向指定队列发送一条或多条测试消息
     * 
     * GET参数：
     * - vhost: 虚拟主机名，默认'default'
     * - queue: 队列名称，默认'testMq1'
     * - count: 发送数量，默认1条
     * 
     * @return mixed JSON响应，包含发送结果和队列长度
     */
    public function test()
    {
        // 获取请求参数
        $vHost = $_GET['vhost'] ?? 'default';
        $mqName = $_GET['queue'] ?? 'testMq1';
        $count = (int)($_GET['count'] ?? 1);

        // 验证队列配置是否存在
        $config = $this->getQueueConfig($vHost, $mqName);
        if (!$config) {
            return $this->response(1, "队列 {$vHost}/{$mqName} 不存在");
        }

        // 批量发送测试消息
        $sent = 0;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            // 发送测试消息，包含索引、时间戳和测试数据
            $id = MqManager::set($mqName, [
                'index' => $i + 1,
                'time' => date('Y-m-d H:i:s'),
                'data' => "测试消息 #" . ($i + 1)
            ], $vHost);
            
            // 记录成功发送的消息ID
            if ($id) {
                $sent++;
                $ids[] = $id;
            }
        }

        // 设置虚拟主机以获取正确的队列长度（影响数据库/Redis连接）
        MqManager::setVHost($vHost);

        // 返回发送结果
        return $this->response(0, "已发送 {$sent}/{$count} 条消息到 {$vHost}/{$mqName}", [
            'vhost' => $vHost,
            'queue' => $mqName,
            'sent' => $sent,
            'total' => $count,
            'ids' => $ids,
            'queueLength' => MqManager::length($mqName)  // 当前队列长度
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
     * 
     * 支持多虚拟主机查询，可查询单个虚拟主机或合并所有虚拟主机的消息
     * 
     * GET参数：
     * - vhost: 虚拟主机名，为空时查询所有虚拟主机
     * - name: 队列名称（精确匹配）
     * - group: 队列组名（精确匹配）
     * - msgId: 消息ID（精确匹配）
     * - data: 消息内容（模糊搜索）
     * - locked: 锁定状态（0=未锁定，1=已锁定）
     * - syncLevel: 重试等级（0=新消息，1+=重试次数）
     * - page: 页码，默认1
     * - pageSize: 每页数量，默认20，最大100
     * 
     * @link http://jayden.cc/system/mq/messages
     * @return mixed JSON响应，包含消息列表、总数、分页信息等
     */
    public function messages()
    {
        // 获取虚拟主机参数
        $vHost = $_GET['vhost'] ?? '';

        // 构建筛选条件
        $filter = [];
        if (!empty($_GET['name'])) $filter['name'] = $_GET['name'];  // 队列名称
        if (!empty($_GET['group'])) $filter['group'] = $_GET['group'];  // 队列组
        if (!empty($_GET['msgId'])) $filter['msgId'] = $_GET['msgId'];  // 消息ID
        if (!empty($_GET['data'])) $filter['data'] = $_GET['data'];  // 消息内容模糊搜索
        if (isset($_GET['locked']) && $_GET['locked'] !== '') $filter['locked'] = (bool)$_GET['locked'];  // 锁定状态
        if (isset($_GET['syncLevel']) && $_GET['syncLevel'] !== '') $filter['syncLevel'] = (int)$_GET['syncLevel'];  // 重试等级

        // 分页参数（防止无效值）
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($_GET['pageSize'] ?? 20)));

        // 获取所有虚拟主机列表
        $vhosts = MqManager::getVHostList();

        // 如果指定了虚拟主机，只查询该虚拟主机
        if (!empty($vHost) && in_array($vHost, $vhosts)) {
            // 设置虚拟主机（影响数据库/Redis连接）
            MqManager::setVHost($vHost);
            // 查询消息列表
            $result = MqManager::getList($filter, $page, $pageSize);
            // 获取队列名称列表和组名列表（用于筛选下拉框）
            $result['names'] = MqManager::getNameList();
            $result['groups'] = MqManager::getGroupList();
        } else {
            // 查询所有虚拟主机（合并结果）
            $allList = [];
            $allTotal = 0;
            $allNames = [];
            $allGroups = [];

            // 遍历所有虚拟主机，分别查询并合并结果
            foreach ($vhosts as $vh) {
                // 设置当前虚拟主机
                MqManager::setVHost($vh);
                // 获取该虚拟主机的所有消息（不分页，用于后续合并排序）
                $vhResult = MqManager::getList($filter, 1, 10000);

                // 为每条消息添加vHost字段，标识消息所属的虚拟主机
                foreach ($vhResult['list'] as &$item) {
                    $item['vHost'] = $vh;
                }

                // 合并结果
                $allList = array_merge($allList, $vhResult['list']);
                $allTotal += $vhResult['total'];
                $allNames = array_merge($allNames, MqManager::getNameList());
                $allGroups = array_merge($allGroups, MqManager::getGroupList());
            }

            // 排序（按创建时间倒序，最新的在前）
            usort($allList, function ($a, $b) {
                return strcmp($b['createTime'], $a['createTime']);
            });

            // 内存分页（因为已经合并了所有虚拟主机的数据）
            $total = count($allList);
            $offset = ($page - 1) * $pageSize;
            $list = array_slice($allList, $offset, $pageSize);

            // 构建返回结果
            $result = [
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
                'totalPages' => ceil($total / $pageSize),
                'names' => array_unique($allNames),  // 去重后的队列名称列表
                'groups' => array_unique($allGroups)  // 去重后的组名列表
            ];
        }

        // 添加虚拟主机列表到返回结果
        $result['vhosts'] = $vhosts;

        return $this->response(0, 'success', $result);
    }

    /**
     * 手动执行单条消息
     * 
     * 用于在Web界面手动触发消息处理，常用于：
     * 1. 测试回调方法是否正确
     * 2. 手动重试失败的消息
     * 3. 调试消息处理逻辑
     * 
     * 执行流程：
     * 1. 验证参数和消息存在性
     * 2. 检查队列配置是否存在
     * 3. 尝试锁定消息（防止并发执行）
     * 4. 执行回调方法并捕获输出
     * 5. 根据返回值处理消息（删除/重试）
     * 
     * POST/GET参数：
     * - vhost: 虚拟主机名，默认'default'
     * - name: 队列名称
     * - unqid: 消息唯一标识
     * 
     * @link http://jayden.cc/system/mq/execute
     * @return mixed JSON响应，包含执行结果、返回值、输出等
     */
    public function execute()
    {
        // 获取参数（支持POST和GET）
        $vHost = $_POST['vhost'] ?? $_GET['vhost'] ?? 'default';
        $name = $_POST['name'] ?? $_GET['name'] ?? '';
        $unqid = $_POST['unqid'] ?? $_GET['unqid'] ?? '';

        // 参数验证
        if (empty($name) || empty($unqid)) {
            return $this->response(1, '参数错误：name和unqid不能为空');
        }

        // 设置虚拟主机（影响数据库/Redis连接）
        MqManager::setVHost($vHost);

        // 获取消息详情
        $message = MqManager::getByUnqid($name, $unqid);
        if (!$message) {
            return $this->response(1, '消息不存在');
        }

        // 检查队列配置是否存在（需要配置才能执行回调）
        $queueName = $message['name'];
        $config = $this->getQueueConfig($vHost, $queueName);
        if (!$config) {
            return $this->response(1, "队列 {$vHost}/{$queueName} 配置不存在，无法执行");
        }

        // 尝试锁定消息（防止被其他消费者同时处理）
        $lockMark = MqManager::lockByUnqid($name, $unqid);
        if (!$lockMark) {
            return $this->response(1, '消息正在被处理中，无法执行');
        }

        // 获取消息数据（传递给回调方法的参数）
        $messageData = $message['data'];

        // 开启输出缓冲，捕获回调方法中的echo/print输出
        ob_start();

        try {
            // 执行回调方法并获取返回值
            $returnValue = MqManager::executeCallbackWithReturn($config, $messageData);
            // 获取回调方法的输出内容
            $output = ob_get_clean();

            // 处理返回值：
            // - true: 处理成功，删除消息
            // - false: 处理失败，使用指数退避重试（2^n 分钟）
            // - 正整数: 处理失败，指定秒数后重试
            if ($returnValue === true) {
                // 执行成功，确认消费并删除消息
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
                // 解锁消息并设置延迟重试时间
                MqManager::unlockByUnqid($name, $unqid, $lockMark, true, $delaySeconds);
                return $this->response(1, "执行失败，消息将在{$delaySeconds}秒后重试", [
                    'msgId' => $message['msgId'],
                    'name' => $queueName,
                    'returnValue' => $returnValue,
                    'delaySeconds' => $delaySeconds,
                    'output' => $output
                ]);
            } else {
                // 执行失败，放回队列（使用默认指数退避延迟）
                MqManager::unlockByUnqid($name, $unqid, $lockMark, true);
                return $this->response(1, '执行失败，消息已放回队列', [
                    'msgId' => $message['msgId'],
                    'name' => $queueName,
                    'returnValue' => $returnValue,
                    'output' => $output
                ]);
            }
        } catch (\Throwable $e) {
            // 捕获异常，获取输出内容
            $output = ob_get_clean();
            // 异常时，解锁消息并放回队列（使用默认延迟）
            MqManager::unlockByUnqid($name, $unqid, $lockMark, true);
            return $this->response(1, '执行异常：' . $e->getMessage(), [
                'msgId' => $message['msgId'],
                'name' => $queueName,
                'returnValue' => false,
                'output' => $output,
                'error' => $e->getMessage() . "\n" . $e->getTraceAsString()  // 包含堆栈信息
            ]);
        }
    }


    /**
     * 删除单条消息
     * 
     * 用于手动删除不需要处理的消息，注意：
     * - 只能删除未锁定的消息（未被消费者处理中）
     * - 删除操作不可恢复
     * 
     * POST/GET参数：
     * - vhost: 虚拟主机名，默认'default'
     * - name: 队列名称
     * - unqid: 消息唯一标识
     * 
     * @link http://jayden.cc/system/mq/delete
     * @return mixed JSON响应
     */
    public function delete()
    {
        // 获取参数（支持POST和GET）
        $vHost = $_POST['vhost'] ?? $_GET['vhost'] ?? 'default';
        $name = $_POST['name'] ?? $_GET['name'] ?? '';
        $unqid = $_POST['unqid'] ?? $_GET['unqid'] ?? '';

        // 参数验证
        if (empty($name) || empty($unqid)) {
            return $this->response(1, '参数错误：name和unqid不能为空');
        }

        // 设置虚拟主机（影响数据库/Redis连接）
        MqManager::setVHost($vHost);

        // 获取消息详情
        $message = MqManager::getByUnqid($name, $unqid);
        if (!$message) {
            return $this->response(1, '消息不存在');
        }

        // 检查是否被锁定（正在被消费者处理的消息不能删除）
        if (!empty($message['lockMark'])) {
            return $this->response(1, '消息正在被处理中，无法删除');
        }

        // 执行删除
        if (MqManager::deleteByUnqid($name, $unqid)) {
            return $this->response(0, '删除成功', ['msgId' => $message['msgId']]);
        }

        return $this->response(1, '删除失败');
    }

    /**
     * 重置失败消息
     * 
     * 用于重置失败的消息，使其可以立即被重新消费：
     * - 将锁定时间改为当前时间
     * - 重试次数清零（syncCount = 0）
     * - 清除延迟重试时间
     * 
     * 常用于：
     * - 修复代码bug后，重置之前失败的消息
     * - 手动触发失败消息的重新处理
     * 
     * POST/GET参数：
     * - vhost: 虚拟主机名，默认'default'
     * - name: 队列名称
     * - unqid: 消息唯一标识
     * 
     * @link http://jayden.cc/system/mq/reset
     * @return mixed JSON响应
     */
    public function reset()
    {
        // 获取参数（支持POST和GET）
        $vHost = $_POST['vhost'] ?? $_GET['vhost'] ?? 'default';
        $name = $_POST['name'] ?? $_GET['name'] ?? '';
        $unqid = $_POST['unqid'] ?? $_GET['unqid'] ?? '';

        // 参数验证
        if (empty($name) || empty($unqid)) {
            return $this->response(1, '参数错误：name和unqid不能为空');
        }

        // 设置虚拟主机（影响数据库/Redis连接）
        MqManager::setVHost($vHost);

        // 获取消息详情
        $message = MqManager::getByUnqid($name, $unqid);
        if (!$message) {
            return $this->response(1, '消息不存在');
        }

        // 检查是否被锁定（正在被消费者处理的消息不能重置）
        if (!empty($message['lockMark'])) {
            return $this->response(1, '消息正在被处理中，无法重置');
        }

        // 执行重置
        if (MqManager::resetByUnqid($name, $unqid)) {
            return $this->response(0, '重置成功', ['msgId' => $message['msgId']]);
        }

        return $this->response(1, '重置失败');
    }

    /**
     * 获取消息详情
     * 
     * 用于查看消息的完整信息，包括：
     * - 消息ID、队列名称、队列组
     * - 消息数据内容
     * - 创建时间、更新时间、锁定时间
     * - 重试次数、锁定状态等
     * 
     * GET参数：
     * - vhost: 虚拟主机名，默认'default'
     * - name: 队列名称
     * - unqid: 消息唯一标识
     * 
     * @link http://jayden.cc/system/mq/detail
     * @return mixed JSON响应，包含消息的完整信息
     */
    public function detail()
    {
        // 获取参数
        $vHost = $_GET['vhost'] ?? 'default';
        $name = $_GET['name'] ?? '';
        $unqid = $_GET['unqid'] ?? '';

        // 参数验证
        if (empty($name) || empty($unqid)) {
            return $this->response(1, '参数错误：name和unqid不能为空');
        }

        // 设置虚拟主机（影响数据库/Redis连接）
        MqManager::setVHost($vHost);

        // 获取消息详情
        $message = MqManager::getByUnqid($name, $unqid);
        if (!$message) {
            return $this->response(1, '消息不存在');
        }

        // 返回消息详情
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
            $returnValue = MqManager::executeCallbackWithReturn($config, $messageData);
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
        if (MqManager::isMasterRunning()) {
            echo "[ERROR] 主进程已在运行\n";
            return;
        }
        MqManager::runMaster();
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

        if (empty($mqName) || empty($consumerId)) {
            echo "[ERROR] 参数错误: vhost={$vHost}, name={$mqName}, id={$consumerId}\n";
            return;
        }

        // 调用 MqManager 的消费者运行方法
        MqManager::runConsumer($vHost, $mqName, $consumerId);
    }

    // ==================== 工具方法 ====================

    /**
     * 从CLI参数中获取指定参数的值
     * 
     * 支持格式：--name=value
     * 
     * @param string $name 参数名
     * @return string|null 参数值，不存在返回null
     */
    protected function getArg($name)
    {
        global $argv;
        foreach ($argv ?? [] as $arg) {
            // 查找 --name=value 格式的参数
            if (strpos($arg, "--{$name}=") === 0) {
                return substr($arg, strlen("--{$name}="));
            }
        }
        return null;
    }

    /**
     * 判断当前是否为HTTP请求
     * 
     * @return bool true=HTTP请求，false=CLI命令
     */
    protected function isHttpRequest()
    {
        return php_sapi_name() !== 'cli' && !empty($_SERVER['REQUEST_METHOD']);
    }

    /**
     * 统一响应方法
     * 
     * 根据请求类型返回不同格式的响应：
     * - HTTP请求: 返回JSON格式
     * - CLI命令: 直接输出文本
     * 
     * @param int $code 状态码，0=成功，非0=失败
     * @param string $msg 消息内容
     * @param array $data 响应数据
     * @return void HTTP请求会exit，CLI直接输出
     */
    protected function response($code, $msg, $data = [])
    {
        if ($this->isHttpRequest()) {
            // HTTP请求：返回JSON格式
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['code' => $code, 'message' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // CLI命令：直接输出文本（带错误/信息前缀）
        echo ($code ? "[ERROR] " : "[INFO] ") . $msg . "\n";
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
                <button onclick="doAction('stop', true)" class="btn btn-danger" title="强制停止所有进程，不等待任务完成">强制停止</button>
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
        
        async function doAction(action, force = false) {
            const btns = document.querySelectorAll('.btn');
            btns.forEach(b => b.disabled = true);
            
            try {
                const url = '/system/mq/' + action + (force ? '?force=1' : '');
                const res = await fetch(url);
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
