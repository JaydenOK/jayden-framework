<?php

namespace app\system;

use app\system\DbManager;
use app\system\RedisManager;

/**
 * 消息队列管理器（静态类）- 支持MySQL和Redis双存储
 *
 * 功能特性：
 * - 双存储引擎：支持 MySQL 和 Redis，根据虚拟主机配置自动切换
 * - 自定义消息ID：支持指定 msgId，相同 msgId 覆盖写入（去重）
 * - 延迟消费：支持指定延迟秒数，消息在指定时间后才能被消费
 * - 消息锁机制：确保同一消息同一时间只被一个消费者处理
 * - 失败重试：指数退避重试，消息永不丢失
 *
 * 队列存储方式：根据虚拟主机配置的adapter字段自动选择存储后端
 * - adapter = 'mysql' 使用MySQL数据库存储（默认）
 * - adapter = 'redis' 使用Redis存储
 *
 * MySQL表结构：
 * CREATE TABLE `mq` (
 *   `unqid` char(35) NOT NULL COMMENT '消息唯一ID(虚拟主机+队列组+队列名称+消息ID)',
 *   `vHost` char(50) NOT NULL COMMENT '虚拟主机，默认default',
 *   `group` char(50) NOT NULL COMMENT '队列组，默认default',
 *   `name` char(50) NOT NULL COMMENT '队列名称',
 *   `msgId` char(110) NOT NULL COMMENT '消息ID',
 *   `data` longtext NOT NULL COMMENT '队列数据',
 *   `syncCount` int(11) NOT NULL COMMENT '已同步次数',
 *   `updateTime` timestamp NOT NULL DEFAULT '2000-01-01 00:00:00' COMMENT '消息最后更新时间',
 *   `createTime` timestamp NOT NULL DEFAULT '2000-01-01 00:00:00' COMMENT '消息首次创建时间',
 *   `syncLevel` int(11) NOT NULL COMMENT '同步等级, 数值越大优先级越低',
 *   `lockTime` timestamp NOT NULL DEFAULT '2000-01-01 00:00:00' COMMENT '锁定时间/延迟执行时间',
 *   `lockMark` char(32) NOT NULL COMMENT '锁定时生成的唯一ID',
 *   PRIMARY KEY (`name`,`unqid`) USING BTREE,
 *   KEY `idx_msgId` (`msgId`) USING BTREE,
 *   KEY `idx_consumer` (`lockTime`,`name`,`group`) USING BTREE
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息队列表'
 *
 * Redis存储结构：
 * - mq:{vHost}:{mqName}:queue      - List，待处理消息队列（存储unqid）
 * - mq:{vHost}:{mqName}:messages   - Hash，消息详情（unqid => json数据）
 * - mq:{vHost}:{mqName}:delay      - Sorted Set，延迟消息（score为可执行时间戳）
 * - mq:{vHost}:{mqName}:lock       - Hash，锁定的消息（unqid => lockMark）
 * - mq:{vHost}:all_messages        - Sorted Set，所有消息索引（用于分页，score为创建时间戳）
 * - mq:{vHost}:names               - Set，队列名称索引
 * - mq:{vHost}:groups              - Set，队列组索引
 *
 * 使用示例：
 * ```php
 * // ========== 基本发送 ==========
 * // 发送消息到队列（立即可消费）
 * MqManager::set('testMq1', ['key' => 'value']);
 * 
 * // 发送到指定虚拟主机
 * MqManager::set('testMq1', $data, 'redis_vhost');
 *
 * // ========== 指定消息ID（去重/覆盖） ==========
 * // 使用数组格式：[$mqName, $msgId]
 * // 如果 msgId 已存在，则覆盖原消息
 * MqManager::set(['orderQueue', 'order_123'], $orderData);
 *
 * // ========== 延迟消费 ==========
 * // 使用数组格式：[$mqName, $msgId, $delaySecond]
 * // msgId 可以为 null（系统自动生成）
 * 
 * // 延迟 60 秒后消费，系统自动生成 msgId
 * MqManager::set(['queueName', null, 60], $data);
 * 
 * // 延迟 5 分钟后消费，指定 msgId
 * MqManager::set(['orderQueue', 'order_123', 300], $orderData);
 *
 * // ========== 组合使用：订单超时取消 ==========
 * // 下单时发送延迟消息，30分钟后检查支付状态
 * $orderId = 'ORD202401010001';
 * MqManager::set(
 *     ['orderTimeoutQueue', "timeout_{$orderId}", 1800],
 *     ['orderId' => $orderId, 'action' => 'check_payment']
 * );
 * 
 * // 用户支付后，覆盖为立即执行的确认消息
 * MqManager::set(
 *     ['orderTimeoutQueue', "timeout_{$orderId}"],
 *     ['orderId' => $orderId, 'action' => 'payment_confirmed']
 * );
 *
 * // ========== 其他操作 ==========
 * // 批量发送
 * MqManager::setBatch('testMq1', [['a' => 1], ['a' => 2]]);
 *
 * // 获取队列长度
 * $length = MqManager::length('testMq1');
 *
 * // 清空队列
 * MqManager::clear('testMq1');
 * ```
 */
class MqManager
{
    /** @var string 当前虚拟主机名称 */
    protected static $vHost = 'default';

    /** @var string 队列组 */
    protected static $group = 'default';

    /** @var int 锁定超时时间（秒） */
    protected static $lockTimeout = 300;

    /**
     * 获取当前虚拟主机的适配器类型
     * @param string|null $vHost 虚拟主机名称
     * @return string 'mysql' 或 'redis'
     */
    protected static function getAdapter($vHost = null)
    {
        return DbManager::getAdapter($vHost ?? self::$vHost);
    }

    /**
     * 获取MySQL数据库连接
     * @param string|null $vHost 虚拟主机名称
     * @return \PDO
     */
    protected static function getMySQLConnection($vHost = null)
    {
        return DbManager::getConnection($vHost ?? self::$vHost);
    }

    /**
     * 获取Redis连接
     * @param string|null $vHost 虚拟主机名称
     * @return \Redis
     */
    protected static function getRedisConnection($vHost = null)
    {
        return RedisManager::getConnection($vHost ?? self::$vHost);
    }

    /**
     * 设置当前虚拟主机
     * @param string $vHost
     */
    public static function setVHost($vHost)
    {
        self::$vHost = $vHost;
    }

    /**
     * 获取当前虚拟主机
     * @return string
     */
    public static function getVHost()
    {
        return self::$vHost;
    }

    /**
     * 设置队列组
     * @param string $group
     */
    public static function setGroup($group)
    {
        self::$group = $group;
    }

    /**
     * 获取当前队列组
     * @return string
     */
    public static function getGroup()
    {
        return self::$group;
    }

    /**
     * 生成唯一消息ID
     * @return string
     */
    protected static function generateMsgId()
    {
        return sprintf('%s_%06d_%04d', date('YmdHis'), (int)(microtime(true) * 1000000) % 1000000, mt_rand(0, 9999));
    }

    /**
     * 生成消息唯一标识（unqid）- 使用当前全局设置
     * @param string $mqName 队列名称
     * @param string $msgId 消息ID
     * @return string
     */
    protected static function generateUnqid($mqName, $msgId)
    {
        return md5(self::$vHost . self::$group . $mqName . $msgId);
    }

    /**
     * 生成消息唯一标识（unqid）- 指定虚拟主机和组
     * @param string $mqName 队列名称
     * @param string $msgId 消息ID
     * @param string $vHost 虚拟主机
     * @param string $group 队列组
     * @return string
     */
    protected static function generateUnqidWithVHost($mqName, $msgId, $vHost, $group)
    {
        return md5($vHost . $group . $mqName . $msgId);
    }

    /**
     * 生成锁标识
     * @return string
     */
    protected static function generateLockMark()
    {
        return md5(uniqid(mt_rand(), true) . getmypid());
    }

    // ==================== 公共接口（自动路由到对应存储） ====================

    /**
     * 发送消息到队列
     * 
     * @param string|array $mqName 队列名称，当为数组时格式为 [$mqName, $msgId, $delaySecond]
     *                             - $mqName: 队列名称（必填）
     *                             - $msgId: 消息ID（可选），未传则系统自动生成，如果重复则覆盖写入
     *                             - $delaySecond: 延迟秒数（可选），未填写则立即执行，填写则延迟指定秒数后执行
     * @param mixed $data 消息数据
     * @param string|null $vhost 虚拟主机（可选，默认default）
     * @param string|null $group 队列组（可选，默认default）
     * @return string|false 成功返回消息ID
     */
    public static function set($mqName, $data, $vhost = null, $group = null)
    {
        // 解析参数
        $customMsgId = null;
        $delaySecond = 0;
        
        if (is_array($mqName)) {
            $customMsgId = $mqName[1] ?? null;
            $delaySecond = (int)($mqName[2] ?? 0);
            $mqName = $mqName[0];
        }
        
        $msgVHost = $vhost ?? self::$vHost;
        $adapter = self::getAdapter($msgVHost);
        
        if ($adapter === 'redis') {
            return self::setRedis($mqName, $data, $vhost, $group, $customMsgId, $delaySecond);
        }
        return self::setMySQL($mqName, $data, $vhost, $group, $customMsgId, $delaySecond);
    }

    /**
     * 批量发送消息
     * @param string $mqName 队列名称
     * @param array $dataList 消息数据列表
     * @param string|null $vhost 虚拟主机（可选，默认default）
     * @param string|null $group 队列组（可选，默认default）
     * @return array 成功发送的消息ID列表
     */
    public static function setBatch($mqName, $dataList, $vhost = null, $group = null)
    {
        $ids = [];
        foreach ($dataList as $data) {
            $id = self::set($mqName, $data, $vhost, $group);
            if ($id) $ids[] = $id;
        }
        return $ids;
    }

    /**
     * 将消息追加到队列（用于重试）
     * 会更新消息的同步次数和同步等级
     * 
     * @param string $mqName 队列名称
     * @param array $message 消息数据
     * @param int $delaySeconds 自定义延迟秒数，0表示使用默认延迟（syncLevel * 5分钟）
     * @return bool
     */
    public static function push($mqName, $message, $delaySeconds = 0)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::pushRedis($mqName, $message, $delaySeconds);
        }
        return self::pushMySQL($mqName, $message, $delaySeconds);
    }

    /**
     * 获取队列长度
     * @param string $mqName 队列名称
     * @return int
     */
    public static function length($mqName)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::lengthRedis($mqName);
        }
        return self::lengthMySQL($mqName);
    }

    /**
     * 清空队列
     * @param string $mqName 队列名称
     * @return bool
     */
    public static function clear($mqName)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::clearRedis($mqName);
        }
        return self::clearMySQL($mqName);
    }

    /**
     * 预览队列消息（不消费）
     * @param string $mqName 队列名称
     * @param int $limit 限制数量
     * @return array
     */
    public static function peek($mqName, $limit = 10)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::peekRedis($mqName, $limit);
        }
        return self::peekMySQL($mqName, $limit);
    }

    /**
     * 弹出一条消息（消费）
     * 使用锁机制防止多进程竞争
     * 只锁定消息，不删除。消费成功后调用 ack() 删除，失败调用 nack() 解锁重试
     * 
     * @param string $mqName 队列名称
     * @return array|null 消息数据（含 _unqid, _lockMark 用于后续操作），无消息时返回null
     */
    public static function pop($mqName)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::popRedis($mqName);
        }
        return self::popMySQL($mqName);
    }

    /**
     * 确认消息消费成功，删除消息
     * @param string $mqName 队列名称
     * @param array $message pop返回的消息（需含 _unqid, _lockMark）
     * @return bool
     */
    public static function ack($mqName, $message)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::ackRedis($mqName, $message);
        }
        return self::ackMySQL($mqName, $message);
    }

    /**
     * 消息消费失败，解锁并设置重试
     * @param string $mqName 队列名称
     * @param array $message pop返回的消息（需含 _unqid, _lockMark, _syncCount）
     * @param int $delaySeconds 延迟秒数，0表示使用默认指数延迟
     * @return bool
     */
    public static function nack($mqName, $message, $delaySeconds = 0)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::nackRedis($mqName, $message, $delaySeconds);
        }
        return self::nackMySQL($mqName, $message, $delaySeconds);
    }

    /**
     * 将消息重新放回队列头部（消费失败时）
     * @param string $mqName 队列名称
     * @param array $message 消息数据
     * @return bool
     */
    public static function unshift($mqName, $message)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::unshiftRedis($mqName, $message);
        }
        return self::unshiftMySQL($mqName, $message);
    }

    /**
     * 根据消息ID获取消息
     * @param string $msgId 消息ID
     * @return array|null
     */
    public static function getByMsgId($msgId)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::getByMsgIdRedis($msgId);
        }
        return self::getByMsgIdMySQL($msgId);
    }

    /**
     * 删除指定消息
     * @param string $msgId 消息ID
     * @return bool
     */
    public static function delete($msgId)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::deleteRedis($msgId);
        }
        return self::deleteMySQL($msgId);
    }

    /**
     * 获取所有队列统计信息
     * @return array
     */
    public static function stats()
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::statsRedis();
        }
        return self::statsMySQL();
    }

    /**
     * 释放超时锁定的消息
     * @return int 释放的消息数量
     */
    public static function releaseExpiredLocks()
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::releaseExpiredLocksRedis();
        }
        return self::releaseExpiredLocksMySQL();
    }

    /**
     * 关闭数据库连接
     * @param string|null $vHost 虚拟主机名称，null表示关闭所有连接
     */
    public static function close($vHost = null)
    {
        if ($vHost !== null) {
            $adapter = self::getAdapter($vHost);
            if ($adapter === 'redis') {
                RedisManager::close($vHost);
            } else {
                DbManager::close($vHost);
            }
        } else {
            DbManager::closeAll();
            RedisManager::closeAll();
        }
    }

    /**
     * 分页查询消息列表
     * @param array $filter 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @return array ['list' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20]
     */
    public static function getList($filter = [], $page = 1, $pageSize = 20)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::getListRedis($filter, $page, $pageSize);
        }
        return self::getListMySQL($filter, $page, $pageSize);
    }

    /**
     * 获取所有队列名称列表
     * @return array
     */
    public static function getNameList()
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::getNameListRedis();
        }
        return self::getNameListMySQL();
    }

    /**
     * 获取所有队列组列表
     * @return array
     */
    public static function getGroupList()
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::getGroupListRedis();
        }
        return self::getGroupListMySQL();
    }

    /**
     * 根据unqid和name获取消息详情
     * @param string $name 队列名称
     * @param string $unqid 消息唯一标识
     * @return array|null
     */
    public static function getByUnqid($name, $unqid)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::getByUnqidRedis($name, $unqid);
        }
        return self::getByUnqidMySQL($name, $unqid);
    }

    /**
     * 根据unqid和name删除消息
     * @param string $name 队列名称
     * @param string $unqid 消息唯一标识
     * @return bool
     */
    public static function deleteByUnqid($name, $unqid)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::deleteByUnqidRedis($name, $unqid);
        }
        return self::deleteByUnqidMySQL($name, $unqid);
    }

    /**
     * 重置失败消息
     * 将锁定时间改为当前时间，重试次数和等级清零
     * @param string $name 队列名称
     * @param string $unqid 消息唯一标识
     * @return bool
     */
    public static function resetByUnqid($name, $unqid)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::resetByUnqidRedis($name, $unqid);
        }
        return self::resetByUnqidMySQL($name, $unqid);
    }

    /**
     * 锁定消息（用于手动执行）
     * @param string $name 队列名称
     * @param string $unqid 消息唯一标识
     * @return string|false 返回锁标识，失败返回false
     */
    public static function lockByUnqid($name, $unqid)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::lockByUnqidRedis($name, $unqid);
        }
        return self::lockByUnqidMySQL($name, $unqid);
    }

    /**
     * 解锁消息（执行失败时放回队列）
     * @param string $name 队列名称
     * @param string $unqid 消息唯一标识
     * @param string $lockMark 锁标识
     * @param bool $incrementRetry 是否增加重试次数
     * @param int $delaySeconds 自定义延迟秒数，0表示使用默认延迟
     * @return bool
     */
    public static function unlockByUnqid($name, $unqid, $lockMark, $incrementRetry = true, $delaySeconds = 0)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::unlockByUnqidRedis($name, $unqid, $lockMark, $incrementRetry, $delaySeconds);
        }
        return self::unlockByUnqidMySQL($name, $unqid, $lockMark, $incrementRetry, $delaySeconds);
    }

    /**
     * 确认消息消费完成并删除（通过unqid）
     * @param string $name 队列名称
     * @param string $unqid 消息唯一标识
     * @param string $lockMark 锁标识
     * @return bool
     */
    public static function ackByUnqid($name, $unqid, $lockMark)
    {
        $adapter = self::getAdapter();
        
        if ($adapter === 'redis') {
            return self::ackByUnqidRedis($name, $unqid, $lockMark);
        }
        return self::ackByUnqidMySQL($name, $unqid, $lockMark);
    }

    // ==================== MySQL 存储实现 ====================

    /**
     * MySQL存储：发送消息到队列
     * 
     * 核心逻辑：
     * 1. unqid 由 vHost+group+mqName+msgId 的MD5生成，确保同一msgId在同一队列中唯一
     * 2. 使用 REPLACE INTO 实现覆盖写入，相同msgId的消息会被新消息替换
     * 3. 延迟消费通过 lockTime 实现：
     *    - 非延迟消息：lockTime='2000-01-01'（早于当前时间，可立即被消费）
     *    - 延迟消息：lockTime=当前时间+延迟秒数（到期后才能被消费）
     * 4. 消费者通过 popMySQL 获取消息时会检查 lockTime <= now
     */
    protected static function setMySQL($mqName, $data, $vhost = null, $group = null, $customMsgId = null, $delaySecond = 0)
    {
        try {
            $msgVHost = $vhost ?? self::$vHost;
            $msgGroup = $group ?? self::$group;
            $pdo = self::getMySQLConnection($msgVHost);
            // 使用自定义msgId或系统生成（格式：YmdHis_微秒_随机数）
            $msgId = $customMsgId ?? self::generateMsgId();
            // unqid = MD5(vHost + group + mqName + msgId)，用于唯一标识消息
            $unqid = self::generateUnqidWithVHost($mqName, $msgId, $msgVHost, $msgGroup);
            $now = time();
            $nowStr = date('Y-m-d H:i:s', $now);
            
            // 延迟消费核心：lockTime 决定消息何时可被消费
            // - 延迟消息：lockTime = 当前时间 + 延迟秒数
            // - 立即消息：lockTime = '2000-01-01'（历史时间，可立即被pop获取）
            $lockTime = $delaySecond > 0 ? date('Y-m-d H:i:s', $now + $delaySecond) : '2000-01-01 00:00:00';
            
            // 消息体结构：id(消息ID), data(业务数据), time(发送时间戳)
            $message = [
                'id' => $msgId,
                'data' => $data,
                'time' => $now
            ];
            
            // REPLACE INTO：如果unqid已存在则先删除再插入（实现覆盖写入）
            // 适用场景：相同msgId的消息需要被新消息替换（如订单状态更新）
            $sql = "REPLACE INTO `mq` (`unqid`, `vHost`, `group`, `name`, `msgId`, `data`, `syncCount`, `updateTime`, `createTime`, `syncLevel`, `lockTime`, `lockMark`) 
                    VALUES (:unqid, :vHost, :group, :name, :msgId, :data, 0, :updateTime, :createTime, 0, :lockTime, '')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':unqid'      => $unqid,
                ':vHost'      => $msgVHost,
                ':group'      => $msgGroup,
                ':name'       => $mqName,
                ':msgId'      => $msgId,
                ':data'       => json_encode($message, JSON_UNESCAPED_UNICODE),
                ':updateTime' => $nowStr,
                ':createTime' => $nowStr,
                ':lockTime'   => $lockTime
            ]);
            
            return $msgId;
        } catch (\PDOException $e) {
            error_log("[MqManager::setMySQL] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * MySQL存储：将消息重新放回队列（用于重试）
     * 
     * 核心逻辑：
     * 1. 用于消费失败后的重试，会更新 syncCount/syncLevel
     * 2. 延迟时间计算：
     *    - 指定 delaySeconds > 0：使用自定义延迟
     *    - 未指定：默认按 syncLevel * 5 分钟延迟
     * 3. 清空 lockMark 解除锁定，等待下次消费
     */
    protected static function pushMySQL($mqName, $message, $delaySeconds = 0)
    {
        try {
            $pdo = self::getMySQLConnection();
            $msgId = $message['id'] ?? self::generateMsgId();
            $unqid = self::generateUnqid($mqName, $msgId);
            $now = date('Y-m-d H:i:s');
            
            // 获取重试次数：_syncCount 是 pop 时附加的内部字段
            $syncCount = $message['_syncCount'] ?? $message['syncCount'] ?? 0;
            $syncLevel = $syncCount;
            
            // 存储前移除内部字段（以 _ 开头的是运行时附加字段）
            $storeMessage = $message;
            unset($storeMessage['_syncCount'], $storeMessage['_syncLevel']);
            
            // 计算下次可执行时间（lockTime）
            if ($delaySeconds > 0) {
                // 自定义延迟秒数（回调返回正整数时使用）
                $lockTime = date('Y-m-d H:i:s', time() + $delaySeconds);
            } else {
                // 默认延迟：syncLevel * 5 分钟（线性增长）
                $delayMinutes = $syncLevel * 5;
                $lockTime = date('Y-m-d H:i:s', time() + $delayMinutes * 60);
            }
            
            // 检查消息是否已存在
            $checkSql = "SELECT `unqid` FROM `mq` WHERE `name` = :name AND `unqid` = :unqid";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([':name' => $mqName, ':unqid' => $unqid]);
            
            if ($checkStmt->fetch()) {
                // 更新已存在的消息（重试时 syncCount 已经在调用方加1）
                $sql = "UPDATE `mq` SET 
                        `data` = :data, 
                        `syncCount` = :syncCount, 
                        `syncLevel` = :syncLevel,
                        `updateTime` = :updateTime, 
                        `lockTime` = :lockTime,
                        `lockMark` = ''
                        WHERE `name` = :name AND `unqid` = :unqid";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':data'       => json_encode($storeMessage, JSON_UNESCAPED_UNICODE),
                    ':syncCount'  => $syncCount,
                    ':syncLevel'  => $syncLevel,
                    ':updateTime' => $now,
                    ':lockTime'   => $lockTime,
                    ':name'       => $mqName,
                    ':unqid'      => $unqid
                ]);
            } else {
                // 插入新消息
                $sql = "INSERT INTO `mq` (`unqid`, `vHost`, `group`, `name`, `msgId`, `data`, `syncCount`, `updateTime`, `createTime`, `syncLevel`, `lockTime`, `lockMark`) 
                        VALUES (:unqid, :vHost, :group, :name, :msgId, :data, :syncCount, :updateTime, :createTime, :syncLevel, :lockTime, '')";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':unqid'      => $unqid,
                    ':vHost'      => self::$vHost,
                    ':group'      => self::$group,
                    ':name'       => $mqName,
                    ':msgId'      => $msgId,
                    ':data'       => json_encode($storeMessage, JSON_UNESCAPED_UNICODE),
                    ':syncCount'  => $syncCount,
                    ':updateTime' => $now,
                    ':createTime' => $now,
                    ':syncLevel'  => $syncLevel,
                    ':lockTime'   => $lockTime
                ]);
            }
            
            return true;
        } catch (\PDOException $e) {
            error_log("[MqManager::pushMySQL] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function lengthMySQL($mqName)
    {
        try {
            $pdo = self::getMySQLConnection();
            $sql = "SELECT COUNT(*) as cnt FROM `mq` WHERE `name` = :name AND `vHost` = :vHost";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':name' => $mqName, ':vHost' => self::$vHost]);
            $row = $stmt->fetch();
            return (int)($row['cnt'] ?? 0);
        } catch (\PDOException $e) {
            error_log("[MqManager::lengthMySQL] Error: " . $e->getMessage());
            return 0;
        }
    }

    protected static function clearMySQL($mqName)
    {
        try {
            $pdo = self::getMySQLConnection();
            $sql = "DELETE FROM `mq` WHERE `name` = :name AND `vHost` = :vHost";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':name' => $mqName, ':vHost' => self::$vHost]);
            return true;
        } catch (\PDOException $e) {
            error_log("[MqManager::clearMySQL] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function peekMySQL($mqName, $limit = 10)
    {
        try {
            $pdo = self::getMySQLConnection();
            $sql = "SELECT `data` FROM `mq` 
                    WHERE `name` = :name AND `vHost` = :vHost 
                    ORDER BY `syncLevel` ASC, `createTime` ASC 
                    LIMIT :limit";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':name', $mqName, \PDO::PARAM_STR);
            $stmt->bindValue(':vHost', self::$vHost, \PDO::PARAM_STR);
            $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
            $stmt->execute();
            
            $messages = [];
            while ($row = $stmt->fetch()) {
                $msg = json_decode($row['data'], true);
                if ($msg) $messages[] = $msg;
            }
            return $messages;
        } catch (\PDOException $e) {
            error_log("[MqManager::peekMySQL] Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * MySQL存储：弹出一条消息进行消费
     * 
     * 核心逻辑（消息锁机制）：
     * 1. 使用 UPDATE + LIMIT 1 原子操作锁定消息，防止多消费者竞争
     * 2. 锁定条件：
     *    - lockMark = ''（未锁定）或 lockTime < 超时时间（锁定已超时，防止进程崩溃导致消息丢失）
     *    - lockTime <= now（消息已到可执行时间，支持延迟消费）
     * 3. 优先级：syncLevel ASC（重试次数少的优先）, createTime ASC（先进先出）
     * 4. 返回消息时附加内部字段：_unqid, _lockMark, _syncCount, _syncLevel
     *    - 这些字段用于后续 ack/nack 操作验证
     * 5. 消费成功调用 ack() 删除消息，失败调用 nack() 解锁并设置重试
     */
    protected static function popMySQL($mqName)
    {
        try {
            $pdo = self::getMySQLConnection();
            $now = date('Y-m-d H:i:s');
            // 生成唯一锁标识，用于验证消息归属
            $lockMark = self::generateLockMark();
            
            // 原子操作：尝试锁定一条可消费的消息
            // 关键条件解释：
            // - lockMark = '' 或 lockTime < expireTime：消息未被锁定或锁已超时
            // - lockTime <= now：消息的延迟时间已到（支持延迟消费和重试延迟）
            $sql = "UPDATE `mq` SET 
                    `lockMark` = :lockMark, 
                    `lockTime` = :lockTime 
                    WHERE `name` = :name 
                    AND `vHost` = :vHost 
                    AND (`lockMark` = '' OR `lockTime` < :expireTime)
                    AND `lockTime` <= :now
                    ORDER BY `syncLevel` ASC, `createTime` ASC 
                    LIMIT 1";
            
            $stmt = $pdo->prepare($sql);
            // 锁超时时间 = 当前时间 - lockTimeout（默认5分钟）
            $expireTime = date('Y-m-d H:i:s', time() - self::$lockTimeout);
            $stmt->execute([
                ':lockMark'   => $lockMark,
                ':lockTime'   => $now,
                ':name'       => $mqName,
                ':vHost'      => self::$vHost,
                ':expireTime' => $expireTime,
                ':now'        => $now
            ]);
            
            if ($stmt->rowCount() === 0) {
                return null; // 没有可用消息（队列空或全部被锁定/延迟中）
            }
            
            // 通过 lockMark 查找刚被锁定的消息
            $sql = "SELECT `unqid`, `name`, `data`, `syncCount`, `syncLevel` FROM `mq` 
                    WHERE `name` = :name 
                    AND `vHost` = :vHost 
                    AND `lockMark` = :lockMark 
                    LIMIT 1";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':name'     => $mqName,
                ':vHost'    => self::$vHost,
                ':lockMark' => $lockMark
            ]);
            
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            
            // 解析消息数据，附加锁信息和重试信息（用于后续 ack/nack）
            $message = json_decode($row['data'], true);
            if ($message) {
                $message['_unqid'] = $row['unqid'];
                $message['_lockMark'] = $lockMark;
                $message['_syncCount'] = (int)$row['syncCount'];
                $message['_syncLevel'] = (int)$row['syncLevel'];
            }
            return $message;
        } catch (\PDOException $e) {
            error_log("[MqManager::popMySQL] Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * MySQL存储：确认消费成功，删除消息
     * 
     * 核心逻辑：
     * 1. 必须验证 lockMark 匹配，确保只有锁定该消息的消费者才能删除
     * 2. 防止误删其他消费者正在处理的消息
     */
    protected static function ackMySQL($mqName, $message)
    {
        // _unqid 和 _lockMark 是 pop 时附加的内部字段
        if (empty($message['_unqid']) || empty($message['_lockMark'])) {
            return false;
        }
        
        try {
            $pdo = self::getMySQLConnection();
            // 删除时必须匹配 lockMark，确保消息归属正确
            $sql = "DELETE FROM `mq` 
                    WHERE `name` = :name 
                    AND `unqid` = :unqid 
                    AND `lockMark` = :lockMark 
                    AND `vHost` = :vHost";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':name'     => $mqName,
                ':unqid'    => $message['_unqid'],
                ':lockMark' => $message['_lockMark'],
                ':vHost'    => self::$vHost
            ]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("[MqManager::ackMySQL] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * MySQL存储：消费失败，解锁消息并设置重试
     * 
     * 核心逻辑：
     * 1. 重试次数 syncCount + 1，影响重试优先级
     * 2. 延迟策略（指数退避）：
     *    - 指定 delaySeconds：使用自定义延迟
     *    - 未指定：2^n 分钟（第1次2分钟，第2次4分钟，第3次8分钟...）
     * 3. 清空 lockMark 解除锁定，更新 lockTime 为下次可执行时间
     * 4. 消息永不删除，直到回调返回 true
     */
    protected static function nackMySQL($mqName, $message, $delaySeconds = 0)
    {
        if (empty($message['_unqid']) || empty($message['_lockMark'])) {
            return false;
        }
        
        try {
            $pdo = self::getMySQLConnection();
            $now = date('Y-m-d H:i:s');
            
            // 重试次数 + 1
            $syncCount = ($message['_syncCount'] ?? 0) + 1;
            $syncLevel = $syncCount;
            
            // 计算下次可执行时间
            if ($delaySeconds > 0) {
                // 自定义延迟（回调返回正整数秒数）
                $lockTime = date('Y-m-d H:i:s', time() + $delaySeconds);
            } else {
                // 指数退避：2^n 分钟（1->2, 2->4, 3->8, 4->16...）
                $delayMinutes = pow(2, $syncCount);
                $lockTime = date('Y-m-d H:i:s', time() + $delayMinutes * 60);
            }
            
            // 清空 lockMark 解锁，设置新的 lockTime 延迟重试
            $sql = "UPDATE `mq` SET 
                    `lockMark` = '', 
                    `syncCount` = :syncCount, 
                    `syncLevel` = :syncLevel,
                    `lockTime` = :lockTime, 
                    `updateTime` = :updateTime 
                    WHERE `name` = :name 
                    AND `unqid` = :unqid 
                    AND `lockMark` = :lockMark 
                    AND `vHost` = :vHost";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':syncCount'  => $syncCount,
                ':syncLevel'  => $syncLevel,
                ':lockTime'   => $lockTime,
                ':updateTime' => $now,
                ':name'       => $mqName,
                ':unqid'      => $message['_unqid'],
                ':lockMark'   => $message['_lockMark'],
                ':vHost'      => self::$vHost
            ]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("[MqManager::nackMySQL] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function unshiftMySQL($mqName, $message)
    {
        try {
            $pdo = self::getMySQLConnection();
            $msgId = $message['id'] ?? self::generateMsgId();
            $unqid = self::generateUnqid($mqName, $msgId);
            $now = date('Y-m-d H:i:s');
            
            // 检查消息是否已存在
            $checkSql = "SELECT `unqid` FROM `mq` WHERE `name` = :name AND `unqid` = :unqid";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([':name' => $mqName, ':unqid' => $unqid]);
            
            if ($checkStmt->fetch()) {
                // 更新已存在的消息，重置锁定状态，保持高优先级（syncLevel=0）
                $sql = "UPDATE `mq` SET 
                        `data` = :data, 
                        `updateTime` = :updateTime, 
                        `lockTime` = '2000-01-01 00:00:00',
                        `lockMark` = '',
                        `syncLevel` = 0
                        WHERE `name` = :name AND `unqid` = :unqid";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':data'       => json_encode($message, JSON_UNESCAPED_UNICODE),
                    ':updateTime' => $now,
                    ':name'       => $mqName,
                    ':unqid'      => $unqid
                ]);
            } else {
                // 插入新消息，优先级最高（syncLevel=0，lockTime最早）
                $sql = "INSERT INTO `mq` (`unqid`, `vHost`, `group`, `name`, `msgId`, `data`, `syncCount`, `updateTime`, `createTime`, `syncLevel`, `lockTime`, `lockMark`) 
                        VALUES (:unqid, :vHost, :group, :name, :msgId, :data, 0, :updateTime, :createTime, 0, '2000-01-01 00:00:00', '')";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':unqid'      => $unqid,
                    ':vHost'      => self::$vHost,
                    ':group'      => self::$group,
                    ':name'       => $mqName,
                    ':msgId'      => $msgId,
                    ':data'       => json_encode($message, JSON_UNESCAPED_UNICODE),
                    ':updateTime' => $now,
                    ':createTime' => $now
                ]);
            }
            
            return true;
        } catch (\PDOException $e) {
            error_log("[MqManager::unshiftMySQL] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function getByMsgIdMySQL($msgId)
    {
        try {
            $pdo = self::getMySQLConnection();
            $sql = "SELECT `data` FROM `mq` WHERE `msgId` = :msgId AND `vHost` = :vHost LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':msgId' => $msgId, ':vHost' => self::$vHost]);
            $row = $stmt->fetch();
            return $row ? json_decode($row['data'], true) : null;
        } catch (\PDOException $e) {
            error_log("[MqManager::getByMsgIdMySQL] Error: " . $e->getMessage());
            return null;
        }
    }

    protected static function deleteMySQL($msgId)
    {
        try {
            $pdo = self::getMySQLConnection();
            $sql = "DELETE FROM `mq` WHERE `msgId` = :msgId AND `vHost` = :vHost";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':msgId' => $msgId, ':vHost' => self::$vHost]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("[MqManager::deleteMySQL] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function statsMySQL()
    {
        try {
            $pdo = self::getMySQLConnection();
            $sql = "SELECT `name`, COUNT(*) as cnt, 
                    SUM(CASE WHEN `lockMark` != '' AND `lockTime` > :expireTime THEN 1 ELSE 0 END) as locked
                    FROM `mq` 
                    WHERE `vHost` = :vHost 
                    GROUP BY `name`";
            
            $expireTime = date('Y-m-d H:i:s', time() - self::$lockTimeout);
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':vHost' => self::$vHost, ':expireTime' => $expireTime]);
            
            $stats = [];
            while ($row = $stmt->fetch()) {
                $stats[$row['name']] = [
                    'total'  => (int)$row['cnt'],
                    'locked' => (int)$row['locked'],
                    'pending' => (int)$row['cnt'] - (int)$row['locked']
                ];
            }
            return $stats;
        } catch (\PDOException $e) {
            error_log("[MqManager::statsMySQL] Error: " . $e->getMessage());
            return [];
        }
    }

    protected static function releaseExpiredLocksMySQL()
    {
        try {
            $pdo = self::getMySQLConnection();
            $expireTime = date('Y-m-d H:i:s', time() - self::$lockTimeout);
            
            $sql = "UPDATE `mq` SET `lockMark` = '', `lockTime` = '2000-01-01 00:00:00' 
                    WHERE `lockMark` != '' AND `lockTime` < :expireTime AND `vHost` = :vHost";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':expireTime' => $expireTime, ':vHost' => self::$vHost]);
            
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            error_log("[MqManager::releaseExpiredLocksMySQL] Error: " . $e->getMessage());
            return 0;
        }
    }

    protected static function getListMySQL($filter = [], $page = 1, $pageSize = 20)
    {
        try {
            $pdo = self::getMySQLConnection();
            $where = ['`vHost` = :vHost'];
            $params = [':vHost' => self::$vHost];
            
            // 按队列名称模糊搜索
            if (!empty($filter['name'])) {
                $where[] = '`name` LIKE :name';
                $params[':name'] = '%' . $filter['name'] . '%';
            }
            
            // 按队列组筛选
            if (!empty($filter['group'])) {
                $where[] = '`group` = :group';
                $params[':group'] = $filter['group'];
            }
            
            // 按消息ID模糊搜索
            if (!empty($filter['msgId'])) {
                $where[] = '`msgId` LIKE :msgId';
                $params[':msgId'] = '%' . $filter['msgId'] . '%';
            }
            
            // 按消息内容模糊搜索
            if (!empty($filter['data'])) {
                $where[] = '`data` LIKE :data';
                $params[':data'] = '%' . $filter['data'] . '%';
            }
            
            // 按锁定状态筛选
            if (isset($filter['locked'])) {
                if ($filter['locked']) {
                    $where[] = "`lockMark` != ''";
                } else {
                    $where[] = "`lockMark` = ''";
                }
            }
            
            // 按同步等级筛选
            if (isset($filter['syncLevel']) && $filter['syncLevel'] !== '') {
                $where[] = '`syncLevel` = :syncLevel';
                $params[':syncLevel'] = (int)$filter['syncLevel'];
            }
            
            $whereStr = implode(' AND ', $where);
            
            // 查询总数
            $countSql = "SELECT COUNT(*) as cnt FROM `mq` WHERE {$whereStr}";
            $stmt = $pdo->prepare($countSql);
            $stmt->execute($params);
            $total = (int)$stmt->fetch()['cnt'];
            
            // 查询列表（不返回data字段，提升性能）
            $offset = ($page - 1) * $pageSize;
            $sql = "SELECT `unqid`, `vHost`, `group`, `name`, `msgId`, `syncCount`, `updateTime`, `createTime`, `syncLevel`, `lockTime`, `lockMark`
                    FROM `mq` 
                    WHERE {$whereStr} 
                    ORDER BY `syncLevel` ASC, `createTime` DESC 
                    LIMIT {$offset}, {$pageSize}";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $list = [];
            while ($row = $stmt->fetch()) {
                $list[] = $row;
            }
            
            return [
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
                'totalPages' => ceil($total / $pageSize)
            ];
        } catch (\PDOException $e) {
            error_log("[MqManager::getListMySQL] Error: " . $e->getMessage());
            return ['list' => [], 'total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'totalPages' => 0];
        }
    }

    protected static function getNameListMySQL()
    {
        try {
            $pdo = self::getMySQLConnection();
            $sql = "SELECT DISTINCT `name` FROM `mq` WHERE `vHost` = :vHost ORDER BY `name`";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':vHost' => self::$vHost]);
            
            $list = [];
            while ($row = $stmt->fetch()) {
                $list[] = $row['name'];
            }
            return $list;
        } catch (\PDOException $e) {
            error_log("[MqManager::getNameListMySQL] Error: " . $e->getMessage());
            return [];
        }
    }

    protected static function getGroupListMySQL()
    {
        try {
            $pdo = self::getMySQLConnection();
            $sql = "SELECT DISTINCT `group` FROM `mq` WHERE `vHost` = :vHost ORDER BY `group`";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':vHost' => self::$vHost]);
            
            $list = [];
            while ($row = $stmt->fetch()) {
                $list[] = $row['group'];
            }
            return $list;
        } catch (\PDOException $e) {
            error_log("[MqManager::getGroupListMySQL] Error: " . $e->getMessage());
            return [];
        }
    }

    protected static function getByUnqidMySQL($name, $unqid)
    {
        try {
            $pdo = self::getMySQLConnection();
            $sql = "SELECT * FROM `mq` WHERE `name` = :name AND `unqid` = :unqid AND `vHost` = :vHost LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':name' => $name, ':unqid' => $unqid, ':vHost' => self::$vHost]);
            $row = $stmt->fetch();
            if ($row) {
                $row['data'] = json_decode($row['data'], true);
            }
            return $row ?: null;
        } catch (\PDOException $e) {
            error_log("[MqManager::getByUnqidMySQL] Error: " . $e->getMessage());
            return null;
        }
    }

    protected static function deleteByUnqidMySQL($name, $unqid)
    {
        try {
            $pdo = self::getMySQLConnection();
            $sql = "DELETE FROM `mq` WHERE `name` = :name AND `unqid` = :unqid AND `vHost` = :vHost";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':name' => $name, ':unqid' => $unqid, ':vHost' => self::$vHost]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("[MqManager::deleteByUnqidMySQL] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function resetByUnqidMySQL($name, $unqid)
    {
        try {
            $pdo = self::getMySQLConnection();
            $now = date('Y-m-d H:i:s');
            
            $sql = "UPDATE `mq` SET 
                    `syncCount` = 0, 
                    `syncLevel` = 0, 
                    `lockTime` = :lockTime, 
                    `updateTime` = :updateTime 
                    WHERE `name` = :name AND `unqid` = :unqid AND `vHost` = :vHost AND `lockMark` = ''";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':lockTime'   => $now,
                ':updateTime' => $now,
                ':name'       => $name,
                ':unqid'      => $unqid,
                ':vHost'      => self::$vHost
            ]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("[MqManager::resetByUnqidMySQL] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function lockByUnqidMySQL($name, $unqid)
    {
        try {
            $pdo = self::getMySQLConnection();
            $lockMark = self::generateLockMark();
            $now = date('Y-m-d H:i:s');
            
            $sql = "UPDATE `mq` SET `lockMark` = :lockMark, `lockTime` = :lockTime 
                    WHERE `name` = :name AND `unqid` = :unqid AND `vHost` = :vHost AND `lockMark` = ''";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':lockMark' => $lockMark,
                ':lockTime' => $now,
                ':name' => $name,
                ':unqid' => $unqid,
                ':vHost' => self::$vHost
            ]);
            
            return $stmt->rowCount() > 0 ? $lockMark : false;
        } catch (\PDOException $e) {
            error_log("[MqManager::lockByUnqidMySQL] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function unlockByUnqidMySQL($name, $unqid, $lockMark, $incrementRetry = true, $delaySeconds = 0)
    {
        try {
            $pdo = self::getMySQLConnection();
            
            if ($incrementRetry) {
                if ($delaySeconds > 0) {
                    // 使用自定义延迟秒数
                    $sql = "UPDATE `mq` SET 
                            `lockMark` = '', 
                            `syncCount` = `syncCount` + 1,
                            `syncLevel` = `syncLevel` + 1,
                            `lockTime` = DATE_ADD(NOW(), INTERVAL :delaySeconds SECOND),
                            `updateTime` = NOW()
                            WHERE `name` = :name AND `unqid` = :unqid AND `lockMark` = :lockMark AND `vHost` = :vHost";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':delaySeconds' => $delaySeconds,
                        ':name' => $name,
                        ':unqid' => $unqid,
                        ':lockMark' => $lockMark,
                        ':vHost' => self::$vHost
                    ]);
                } else {
                    // 使用默认延迟（syncLevel * 5分钟）
                    $sql = "UPDATE `mq` SET 
                            `lockMark` = '', 
                            `syncCount` = `syncCount` + 1,
                            `syncLevel` = `syncLevel` + 1,
                            `lockTime` = DATE_ADD(NOW(), INTERVAL (`syncLevel` + 1) * 5 MINUTE),
                            `updateTime` = NOW()
                            WHERE `name` = :name AND `unqid` = :unqid AND `lockMark` = :lockMark AND `vHost` = :vHost";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':name' => $name,
                        ':unqid' => $unqid,
                        ':lockMark' => $lockMark,
                        ':vHost' => self::$vHost
                    ]);
                }
            } else {
                $sql = "UPDATE `mq` SET 
                        `lockMark` = '', 
                        `lockTime` = '2000-01-01 00:00:00',
                        `updateTime` = NOW()
                        WHERE `name` = :name AND `unqid` = :unqid AND `lockMark` = :lockMark AND `vHost` = :vHost";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':name' => $name,
                    ':unqid' => $unqid,
                    ':lockMark' => $lockMark,
                    ':vHost' => self::$vHost
                ]);
            }
            
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("[MqManager::unlockByUnqidMySQL] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function ackByUnqidMySQL($name, $unqid, $lockMark)
    {
        try {
            $pdo = self::getMySQLConnection();
            $sql = "DELETE FROM `mq` WHERE `name` = :name AND `unqid` = :unqid AND `lockMark` = :lockMark AND `vHost` = :vHost";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':name' => $name,
                ':unqid' => $unqid,
                ':lockMark' => $lockMark,
                ':vHost' => self::$vHost
            ]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("[MqManager::ackByUnqidMySQL] Error: " . $e->getMessage());
            return false;
        }
    }

    // ==================== Redis 存储实现 ====================

    /**
     * 获取Redis键名
     * 
     * Redis存储结构说明：
     * - queue    (List)       待处理消息队列，存储 unqid，FIFO
     * - messages (Hash)       消息详情，unqid => JSON数据
     * - delay    (Sorted Set) 延迟消息，score 为可执行时间戳
     * - lock     (Hash)       锁定的消息，unqid => {lockMark, lockTime}
     * - all      (Sorted Set) 全局消息索引，用于分页查询
     * - names    (Set)        队列名称索引
     * - groups   (Set)        队列组索引
     */
    protected static function getRedisKey($type, $mqName = '')
    {
        $vHost = self::$vHost;
        switch ($type) {
            case 'queue':
                return "mq:{$vHost}:{$mqName}:queue";      // List: 待处理队列
            case 'messages':
                return "mq:{$vHost}:{$mqName}:messages";   // Hash: 消息详情
            case 'delay':
                return "mq:{$vHost}:{$mqName}:delay";      // Sorted Set: 延迟队列
            case 'lock':
                return "mq:{$vHost}:{$mqName}:lock";       // Hash: 锁定的消息
            case 'all':
                return "mq:{$vHost}:all_messages";         // Sorted Set: 全局索引
            case 'names':
                return "mq:{$vHost}:names";                // Set: 队列名称索引
            case 'groups':
                return "mq:{$vHost}:groups";               // Set: 队列组索引
            default:
                return "mq:{$vHost}:{$type}";
        }
    }

    /**
     * Redis存储：发送消息到队列
     * 
     * 核心逻辑：
     * 1. 消息存储在 Hash（messages）中，unqid 为键
     * 2. 延迟消费通过两个队列实现：
     *    - 非延迟消息：直接加入 queue（List），可立即被 pop
     *    - 延迟消息：加入 delay（Sorted Set），score 为可执行时间戳
     * 3. 覆盖写入：相同 msgId 会先从旧队列移除，再加入新队列
     * 4. 消费者 pop 时会先检查 delay 队列，将到期消息移到 queue
     */
    protected static function setRedis($mqName, $data, $vhost = null, $group = null, $customMsgId = null, $delaySecond = 0)
    {
        try {
            $msgVHost = $vhost ?? self::$vHost;
            $msgGroup = $group ?? self::$group;
            $redis = self::getRedisConnection($msgVHost);
            $msgId = $customMsgId ?? self::generateMsgId();
            // unqid = MD5(vHost + group + mqName + msgId)
            $unqid = self::generateUnqidWithVHost($mqName, $msgId, $msgVHost, $msgGroup);
            $now = time();
            $nowStr = date('Y-m-d H:i:s', $now);
            
            // 计算可执行时间戳（用于延迟队列的 score）
            $executeTime = $delaySecond > 0 ? $now + $delaySecond : $now;
            $lockTime = $delaySecond > 0 ? date('Y-m-d H:i:s', $executeTime) : '2000-01-01 00:00:00';
            
            // 消息体：id + data + time
            $message = [
                'id' => $msgId,
                'data' => $data,
                'time' => $now
            ];
            
            // 检查消息是否已存在（实现覆盖写入）
            $messagesKey = "mq:{$msgVHost}:{$mqName}:messages";
            $existingData = $redis->hGet($messagesKey, $unqid);
            $isUpdate = !empty($existingData);
            
            // 构建消息元数据（与MySQL字段保持一致）
            $msgData = [
                'unqid' => $unqid,
                'vHost' => $msgVHost,
                'group' => $msgGroup,
                'name' => $mqName,
                'msgId' => $msgId,
                'data' => json_encode($message, JSON_UNESCAPED_UNICODE),
                'syncCount' => 0,
                'syncLevel' => 0,
                'lockTime' => $lockTime,
                'lockMark' => '',
                'createTime' => $nowStr,
                'updateTime' => $nowStr
            ];
            
            // 覆盖写入：先从旧队列移除
            if ($isUpdate) {
                $oldData = json_decode($existingData, true);
                $msgData['createTime'] = $oldData['createTime'] ?? $nowStr;  // 保留原创建时间
                
                // 从 queue 和 delay 两个队列中移除（不确定消息在哪个队列）
                $queueKey = "mq:{$msgVHost}:{$mqName}:queue";
                $delayKey = "mq:{$msgVHost}:{$mqName}:delay";
                $redis->lRem($queueKey, 0, $unqid);  // List: 移除所有匹配项
                $redis->zRem($delayKey, $unqid);     // Sorted Set: 移除成员
            }
            
            // 保存消息到Hash
            $redis->hSet($messagesKey, $unqid, json_encode($msgData, JSON_UNESCAPED_UNICODE));
            
            // 根据是否延迟决定添加到哪个队列
            if ($delaySecond > 0) {
                // 添加到延迟队列
                $delayKey = "mq:{$msgVHost}:{$mqName}:delay";
                $redis->zAdd($delayKey, $executeTime, $unqid);
            } else {
                // 添加到待处理队列
                $queueKey = "mq:{$msgVHost}:{$mqName}:queue";
                $redis->rPush($queueKey, $unqid);
            }
            
            // 添加到全局索引（用于分页查询）- 只有新消息才添加
            if (!$isUpdate) {
                $allKey = "mq:{$msgVHost}:all_messages";
                $redis->zAdd($allKey, $now, "{$mqName}:{$unqid}");
            }
            
            // 添加队列名称和组到索引
            $namesKey = "mq:{$msgVHost}:names";
            $groupsKey = "mq:{$msgVHost}:groups";
            $redis->sAdd($namesKey, $mqName);
            $redis->sAdd($groupsKey, $msgGroup);
            
            return $msgId;
        } catch (\Exception $e) {
            error_log("[MqManager::setRedis] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function pushRedis($mqName, $message, $delaySeconds = 0)
    {
        try {
            $redis = self::getRedisConnection();
            $msgId = $message['id'] ?? self::generateMsgId();
            $unqid = self::generateUnqid($mqName, $msgId);
            $now = time();
            $nowStr = date('Y-m-d H:i:s', $now);
            
            $syncCount = $message['_syncCount'] ?? $message['syncCount'] ?? 0;
            $syncLevel = $syncCount;
            
            // 存储前移除内部字段
            $storeMessage = $message;
            unset($storeMessage['_syncCount'], $storeMessage['_syncLevel'], $storeMessage['_unqid'], $storeMessage['_lockMark']);
            
            // 计算可执行时间
            if ($delaySeconds > 0) {
                $executeTime = $now + $delaySeconds;
            } else {
                $delayMinutes = $syncLevel * 5;
                $executeTime = $now + $delayMinutes * 60;
            }
            
            $messagesKey = self::getRedisKey('messages', $mqName);
            $existingData = $redis->hGet($messagesKey, $unqid);
            
            if ($existingData) {
                // 更新已存在的消息
                $msgData = json_decode($existingData, true);
                $msgData['data'] = json_encode($storeMessage, JSON_UNESCAPED_UNICODE);
                $msgData['syncCount'] = $syncCount;
                $msgData['syncLevel'] = $syncLevel;
                $msgData['lockTime'] = date('Y-m-d H:i:s', $executeTime);
                $msgData['lockMark'] = '';
                $msgData['updateTime'] = $nowStr;
            } else {
                // 新消息
                $msgData = [
                    'unqid' => $unqid,
                    'vHost' => self::$vHost,
                    'group' => self::$group,
                    'name' => $mqName,
                    'msgId' => $msgId,
                    'data' => json_encode($storeMessage, JSON_UNESCAPED_UNICODE),
                    'syncCount' => $syncCount,
                    'syncLevel' => $syncLevel,
                    'lockTime' => date('Y-m-d H:i:s', $executeTime),
                    'lockMark' => '',
                    'createTime' => $nowStr,
                    'updateTime' => $nowStr
                ];
                
                // 添加到全局索引
                $allKey = self::getRedisKey('all');
                $redis->zAdd($allKey, $now, "{$mqName}:{$unqid}");
            }
            
            $redis->hSet($messagesKey, $unqid, json_encode($msgData, JSON_UNESCAPED_UNICODE));
            
            // 如果有延迟，添加到延迟队列
            if ($executeTime > $now) {
                $delayKey = self::getRedisKey('delay', $mqName);
                $redis->zAdd($delayKey, $executeTime, $unqid);
            } else {
                // 立即可执行，添加到待处理队列
                $queueKey = self::getRedisKey('queue', $mqName);
                $redis->rPush($queueKey, $unqid);
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("[MqManager::pushRedis] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function lengthRedis($mqName)
    {
        try {
            $redis = self::getRedisConnection();
            $queueKey = self::getRedisKey('queue', $mqName);
            $delayKey = self::getRedisKey('delay', $mqName);
            $lockKey = self::getRedisKey('lock', $mqName);
            
            // 总数 = 待处理 + 延迟 + 锁定
            $queueLen = $redis->lLen($queueKey) ?: 0;
            $delayLen = $redis->zCard($delayKey) ?: 0;
            $lockLen = $redis->hLen($lockKey) ?: 0;
            
            return $queueLen + $delayLen + $lockLen;
        } catch (\Exception $e) {
            error_log("[MqManager::lengthRedis] Error: " . $e->getMessage());
            return 0;
        }
    }

    protected static function clearRedis($mqName)
    {
        try {
            $redis = self::getRedisConnection();
            
            // 删除所有相关键
            $redis->del(self::getRedisKey('queue', $mqName));
            $redis->del(self::getRedisKey('messages', $mqName));
            $redis->del(self::getRedisKey('delay', $mqName));
            $redis->del(self::getRedisKey('lock', $mqName));
            
            // 从全局索引中移除
            $allKey = self::getRedisKey('all');
            $allMessages = $redis->zRange($allKey, 0, -1);
            foreach ($allMessages as $item) {
                if (strpos($item, "{$mqName}:") === 0) {
                    $redis->zRem($allKey, $item);
                }
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("[MqManager::clearRedis] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function peekRedis($mqName, $limit = 10)
    {
        try {
            $redis = self::getRedisConnection();
            $queueKey = self::getRedisKey('queue', $mqName);
            $messagesKey = self::getRedisKey('messages', $mqName);
            
            $unqids = $redis->lRange($queueKey, 0, $limit - 1);
            
            $messages = [];
            foreach ($unqids as $unqid) {
                $msgData = $redis->hGet($messagesKey, $unqid);
                if ($msgData) {
                    $row = json_decode($msgData, true);
                    $msg = json_decode($row['data'], true);
                    if ($msg) $messages[] = $msg;
                }
            }
            return $messages;
        } catch (\Exception $e) {
            error_log("[MqManager::peekRedis] Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Redis存储：弹出一条消息进行消费
     * 
     * 核心逻辑（延迟队列转移机制）：
     * 1. 先检查 delay（Sorted Set），将 score <= now 的到期消息移到 queue
     * 2. 从 queue（List）左端弹出一条消息（FIFO）
     * 3. 锁定消息：
     *    - 更新 messages Hash 中的 lockMark 和 lockTime
     *    - 同时在 lock Hash 中记录锁信息（用于超时检测）
     * 4. 返回消息时附加内部字段：_unqid, _lockMark, _syncCount, _syncLevel
     * 
     * 注意：Redis 版本没有 MySQL 的锁超时检测机制，
     * 如果消费者崩溃，需要外部定时任务清理超时锁
     */
    protected static function popRedis($mqName)
    {
        try {
            $redis = self::getRedisConnection();
            $now = time();
            $lockMark = self::generateLockMark();
            
            $delayKey = self::getRedisKey('delay', $mqName);    // 延迟队列
            $queueKey = self::getRedisKey('queue', $mqName);    // 待处理队列
            $messagesKey = self::getRedisKey('messages', $mqName);  // 消息详情
            $lockKey = self::getRedisKey('lock', $mqName);      // 锁定集合
            
            // 步骤1：将到期的延迟消息移到待处理队列
            // zRangeByScore: 获取 score <= now 的所有成员（即已到执行时间的消息）
            $dueMessages = $redis->zRangeByScore($delayKey, '-inf', $now);
            foreach ($dueMessages as $unqid) {
                $redis->zRem($delayKey, $unqid);     // 从延迟队列移除
                $redis->rPush($queueKey, $unqid);   // 加入待处理队列尾部
            }
            
            // 步骤2：从待处理队列左端弹出（FIFO）
            $unqid = $redis->lPop($queueKey);
            if (!$unqid) {
                return null;  // 队列为空
            }
            
            // 步骤3：获取消息详情
            $msgData = $redis->hGet($messagesKey, $unqid);
            if (!$msgData) {
                return null;  // 消息不存在（可能已被删除）
            }
            
            $row = json_decode($msgData, true);
            
            // 步骤4：锁定消息（更新 messages 和 lock）
            $row['lockMark'] = $lockMark;
            $row['lockTime'] = date('Y-m-d H:i:s', $now);
            $redis->hSet($messagesKey, $unqid, json_encode($row, JSON_UNESCAPED_UNICODE));
            
            // 记录锁信息（用于超时检测和 ack 验证）
            $redis->hSet($lockKey, $unqid, json_encode([
                'lockMark' => $lockMark,
                'lockTime' => $now
            ]));
            
            // 步骤5：构建返回消息（附加内部字段）
            $message = json_decode($row['data'], true);
            if ($message) {
                $message['_unqid'] = $unqid;           // 用于 ack/nack 定位消息
                $message['_lockMark'] = $lockMark;    // 用于验证锁归属
                $message['_syncCount'] = (int)$row['syncCount'];  // 重试次数
                $message['_syncLevel'] = (int)$row['syncLevel'];  // 重试等级
            }
            
            return $message;
        } catch (\Exception $e) {
            error_log("[MqManager::popRedis] Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Redis存储：确认消费成功，删除消息
     * 
     * 核心逻辑：
     * 1. 验证 lockMark 匹配，确保只有锁定者能删除
     * 2. 同时删除 messages、lock、all 三处数据
     */
    protected static function ackRedis($mqName, $message)
    {
        if (empty($message['_unqid']) || empty($message['_lockMark'])) {
            return false;
        }
        
        try {
            $redis = self::getRedisConnection();
            $unqid = $message['_unqid'];
            $lockMark = $message['_lockMark'];
            
            $messagesKey = self::getRedisKey('messages', $mqName);
            $lockKey = self::getRedisKey('lock', $mqName);
            $allKey = self::getRedisKey('all');
            
            // 验证锁标识
            $lockData = $redis->hGet($lockKey, $unqid);
            if ($lockData) {
                $lock = json_decode($lockData, true);
                if (($lock['lockMark'] ?? '') !== $lockMark) {
                    return false;
                }
            }
            
            // 删除消息
            $redis->hDel($messagesKey, $unqid);
            $redis->hDel($lockKey, $unqid);
            $redis->zRem($allKey, "{$mqName}:{$unqid}");
            
            return true;
        } catch (\Exception $e) {
            error_log("[MqManager::ackRedis] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Redis存储：消费失败，解锁消息并设置重试
     * 
     * 核心逻辑：
     * 1. 重试次数 syncCount + 1
     * 2. 延迟策略（指数退避）：
     *    - 指定 delaySeconds：使用自定义延迟
     *    - 未指定：2^n 分钟
     * 3. 清空 lockMark，从 lock Hash 移除
     * 4. 加入 delay（Sorted Set），score 为下次执行时间戳
     *    - 下次 pop 时会检查 delay，到期后自动移到 queue
     */
    protected static function nackRedis($mqName, $message, $delaySeconds = 0)
    {
        if (empty($message['_unqid']) || empty($message['_lockMark'])) {
            return false;
        }
        
        try {
            $redis = self::getRedisConnection();
            $now = time();
            $nowStr = date('Y-m-d H:i:s', $now);
            $unqid = $message['_unqid'];
            
            $messagesKey = self::getRedisKey('messages', $mqName);
            $lockKey = self::getRedisKey('lock', $mqName);
            $delayKey = self::getRedisKey('delay', $mqName);
            
            // 重试次数 + 1
            $syncCount = ($message['_syncCount'] ?? 0) + 1;
            $syncLevel = $syncCount;
            
            // 计算下次可执行时间
            if ($delaySeconds > 0) {
                // 自定义延迟（回调返回正整数秒数）
                $executeTime = $now + $delaySeconds;
            } else {
                // 指数退避：2^n 分钟（1->2, 2->4, 3->8...）
                $delayMinutes = pow(2, $syncCount);
                $executeTime = $now + $delayMinutes * 60;
            }
            
            // 更新 messages Hash 中的消息数据
            $msgData = $redis->hGet($messagesKey, $unqid);
            if ($msgData) {
                $row = json_decode($msgData, true);
                $row['syncCount'] = $syncCount;
                $row['syncLevel'] = $syncLevel;
                $row['lockTime'] = date('Y-m-d H:i:s', $executeTime);
                $row['lockMark'] = '';  // 清空锁标识
                $row['updateTime'] = $nowStr;
                $redis->hSet($messagesKey, $unqid, json_encode($row, JSON_UNESCAPED_UNICODE));
            }
            
            // 从 lock Hash 移除锁记录
            $redis->hDel($lockKey, $unqid);
            
            // 加入延迟队列（Sorted Set），score = 下次执行时间戳
            $redis->zAdd($delayKey, $executeTime, $unqid);
            
            return true;
        } catch (\Exception $e) {
            error_log("[MqManager::nackRedis] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function unshiftRedis($mqName, $message)
    {
        try {
            $redis = self::getRedisConnection();
            $msgId = $message['id'] ?? self::generateMsgId();
            $unqid = self::generateUnqid($mqName, $msgId);
            $now = time();
            $nowStr = date('Y-m-d H:i:s', $now);
            
            $messagesKey = self::getRedisKey('messages', $mqName);
            $queueKey = self::getRedisKey('queue', $mqName);
            $allKey = self::getRedisKey('all');
            
            $existingData = $redis->hGet($messagesKey, $unqid);
            
            if ($existingData) {
                // 更新已存在的消息
                $msgData = json_decode($existingData, true);
                $msgData['data'] = json_encode($message, JSON_UNESCAPED_UNICODE);
                $msgData['lockTime'] = '2000-01-01 00:00:00';
                $msgData['lockMark'] = '';
                $msgData['syncLevel'] = 0;
                $msgData['updateTime'] = $nowStr;
            } else {
                // 新消息
                $msgData = [
                    'unqid' => $unqid,
                    'vHost' => self::$vHost,
                    'group' => self::$group,
                    'name' => $mqName,
                    'msgId' => $msgId,
                    'data' => json_encode($message, JSON_UNESCAPED_UNICODE),
                    'syncCount' => 0,
                    'syncLevel' => 0,
                    'lockTime' => '2000-01-01 00:00:00',
                    'lockMark' => '',
                    'createTime' => $nowStr,
                    'updateTime' => $nowStr
                ];
                
                // 添加到全局索引
                $redis->zAdd($allKey, $now, "{$mqName}:{$unqid}");
            }
            
            $redis->hSet($messagesKey, $unqid, json_encode($msgData, JSON_UNESCAPED_UNICODE));
            
            // 添加到队列头部（高优先级）
            $redis->lPush($queueKey, $unqid);
            
            return true;
        } catch (\Exception $e) {
            error_log("[MqManager::unshiftRedis] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function getByMsgIdRedis($msgId)
    {
        try {
            $redis = self::getRedisConnection();
            $namesKey = self::getRedisKey('names');
            $names = $redis->sMembers($namesKey);
            
            foreach ($names as $mqName) {
                $messagesKey = self::getRedisKey('messages', $mqName);
                $allMessages = $redis->hGetAll($messagesKey);
                
                foreach ($allMessages as $unqid => $data) {
                    $row = json_decode($data, true);
                    if (($row['msgId'] ?? '') === $msgId) {
                        return json_decode($row['data'], true);
                    }
                }
            }
            return null;
        } catch (\Exception $e) {
            error_log("[MqManager::getByMsgIdRedis] Error: " . $e->getMessage());
            return null;
        }
    }

    protected static function deleteRedis($msgId)
    {
        try {
            $redis = self::getRedisConnection();
            $namesKey = self::getRedisKey('names');
            $names = $redis->sMembers($namesKey);
            
            foreach ($names as $mqName) {
                $messagesKey = self::getRedisKey('messages', $mqName);
                $allMessages = $redis->hGetAll($messagesKey);
                
                foreach ($allMessages as $unqid => $data) {
                    $row = json_decode($data, true);
                    if (($row['msgId'] ?? '') === $msgId) {
                        // 找到消息，删除
                        $redis->hDel($messagesKey, $unqid);
                        
                        // 从其他相关结构中移除
                        $queueKey = self::getRedisKey('queue', $mqName);
                        $delayKey = self::getRedisKey('delay', $mqName);
                        $lockKey = self::getRedisKey('lock', $mqName);
                        $allKey = self::getRedisKey('all');
                        
                        $redis->lRem($queueKey, 0, $unqid);
                        $redis->zRem($delayKey, $unqid);
                        $redis->hDel($lockKey, $unqid);
                        $redis->zRem($allKey, "{$mqName}:{$unqid}");
                        
                        return true;
                    }
                }
            }
            return false;
        } catch (\Exception $e) {
            error_log("[MqManager::deleteRedis] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function statsRedis()
    {
        try {
            $redis = self::getRedisConnection();
            $namesKey = self::getRedisKey('names');
            $names = $redis->sMembers($namesKey);
            
            $stats = [];
            foreach ($names as $mqName) {
                $queueKey = self::getRedisKey('queue', $mqName);
                $delayKey = self::getRedisKey('delay', $mqName);
                $lockKey = self::getRedisKey('lock', $mqName);
                
                $queueLen = $redis->lLen($queueKey) ?: 0;
                $delayLen = $redis->zCard($delayKey) ?: 0;
                $lockLen = $redis->hLen($lockKey) ?: 0;
                
                $stats[$mqName] = [
                    'total' => $queueLen + $delayLen + $lockLen,
                    'locked' => $lockLen,
                    'pending' => $queueLen + $delayLen
                ];
            }
            return $stats;
        } catch (\Exception $e) {
            error_log("[MqManager::statsRedis] Error: " . $e->getMessage());
            return [];
        }
    }

    protected static function releaseExpiredLocksRedis()
    {
        try {
            $redis = self::getRedisConnection();
            $now = time();
            $expireTime = $now - self::$lockTimeout;
            $namesKey = self::getRedisKey('names');
            $names = $redis->sMembers($namesKey);
            
            $released = 0;
            foreach ($names as $mqName) {
                $lockKey = self::getRedisKey('lock', $mqName);
                $messagesKey = self::getRedisKey('messages', $mqName);
                $queueKey = self::getRedisKey('queue', $mqName);
                
                $locks = $redis->hGetAll($lockKey);
                foreach ($locks as $unqid => $lockData) {
                    $lock = json_decode($lockData, true);
                    if (($lock['lockTime'] ?? 0) < $expireTime) {
                        // 锁已过期，释放
                        $redis->hDel($lockKey, $unqid);
                        
                        // 更新消息状态
                        $msgData = $redis->hGet($messagesKey, $unqid);
                        if ($msgData) {
                            $row = json_decode($msgData, true);
                            $row['lockMark'] = '';
                            $row['lockTime'] = '2000-01-01 00:00:00';
                            $redis->hSet($messagesKey, $unqid, json_encode($row, JSON_UNESCAPED_UNICODE));
                        }
                        
                        // 放回队列
                        $redis->rPush($queueKey, $unqid);
                        $released++;
                    }
                }
            }
            return $released;
        } catch (\Exception $e) {
            error_log("[MqManager::releaseExpiredLocksRedis] Error: " . $e->getMessage());
            return 0;
        }
    }

    protected static function getListRedis($filter = [], $page = 1, $pageSize = 20)
    {
        try {
            $redis = self::getRedisConnection();
            $namesKey = self::getRedisKey('names');
            $allKey = self::getRedisKey('all');
            
            // 获取所有消息
            $allMessages = [];
            $names = $redis->sMembers($namesKey);
            
            foreach ($names as $mqName) {
                // 筛选队列名称
                if (!empty($filter['name']) && strpos($mqName, $filter['name']) === false) {
                    continue;
                }
                
                $messagesKey = self::getRedisKey('messages', $mqName);
                $messages = $redis->hGetAll($messagesKey);
                
                foreach ($messages as $unqid => $data) {
                    $row = json_decode($data, true);
                    
                    // 应用筛选条件
                    if (!empty($filter['group']) && ($row['group'] ?? '') !== $filter['group']) {
                        continue;
                    }
                    if (!empty($filter['msgId']) && strpos($row['msgId'] ?? '', $filter['msgId']) === false) {
                        continue;
                    }
                    if (!empty($filter['data']) && strpos($row['data'] ?? '', $filter['data']) === false) {
                        continue;
                    }
                    if (isset($filter['locked'])) {
                        $isLocked = !empty($row['lockMark']);
                        if ($filter['locked'] && !$isLocked) continue;
                        if (!$filter['locked'] && $isLocked) continue;
                    }
                    if (isset($filter['syncLevel']) && $filter['syncLevel'] !== '' && 
                        (int)($row['syncLevel'] ?? 0) !== (int)$filter['syncLevel']) {
                        continue;
                    }
                    
                    // 移除data字段以提升性能（与MySQL行为一致）
                    unset($row['data']);
                    $allMessages[] = $row;
                }
            }
            
            // 排序（按syncLevel升序，createTime降序）
            usort($allMessages, function ($a, $b) {
                if ($a['syncLevel'] !== $b['syncLevel']) {
                    return $a['syncLevel'] - $b['syncLevel'];
                }
                return strcmp($b['createTime'], $a['createTime']);
            });
            
            // 分页
            $total = count($allMessages);
            $offset = ($page - 1) * $pageSize;
            $list = array_slice($allMessages, $offset, $pageSize);
            
            return [
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
                'totalPages' => ceil($total / $pageSize)
            ];
        } catch (\Exception $e) {
            error_log("[MqManager::getListRedis] Error: " . $e->getMessage());
            return ['list' => [], 'total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'totalPages' => 0];
        }
    }

    protected static function getNameListRedis()
    {
        try {
            $redis = self::getRedisConnection();
            $namesKey = self::getRedisKey('names');
            $names = $redis->sMembers($namesKey);
            sort($names);
            return $names ?: [];
        } catch (\Exception $e) {
            error_log("[MqManager::getNameListRedis] Error: " . $e->getMessage());
            return [];
        }
    }

    protected static function getGroupListRedis()
    {
        try {
            $redis = self::getRedisConnection();
            $groupsKey = self::getRedisKey('groups');
            $groups = $redis->sMembers($groupsKey);
            sort($groups);
            return $groups ?: [];
        } catch (\Exception $e) {
            error_log("[MqManager::getGroupListRedis] Error: " . $e->getMessage());
            return [];
        }
    }

    protected static function getByUnqidRedis($name, $unqid)
    {
        try {
            $redis = self::getRedisConnection();
            $messagesKey = self::getRedisKey('messages', $name);
            
            $msgData = $redis->hGet($messagesKey, $unqid);
            if (!$msgData) {
                return null;
            }
            
            $row = json_decode($msgData, true);
            $row['data'] = json_decode($row['data'], true);
            return $row;
        } catch (\Exception $e) {
            error_log("[MqManager::getByUnqidRedis] Error: " . $e->getMessage());
            return null;
        }
    }

    protected static function deleteByUnqidRedis($name, $unqid)
    {
        try {
            $redis = self::getRedisConnection();
            
            $messagesKey = self::getRedisKey('messages', $name);
            $queueKey = self::getRedisKey('queue', $name);
            $delayKey = self::getRedisKey('delay', $name);
            $lockKey = self::getRedisKey('lock', $name);
            $allKey = self::getRedisKey('all');
            
            $deleted = $redis->hDel($messagesKey, $unqid);
            $redis->lRem($queueKey, 0, $unqid);
            $redis->zRem($delayKey, $unqid);
            $redis->hDel($lockKey, $unqid);
            $redis->zRem($allKey, "{$name}:{$unqid}");
            
            return $deleted > 0;
        } catch (\Exception $e) {
            error_log("[MqManager::deleteByUnqidRedis] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function resetByUnqidRedis($name, $unqid)
    {
        try {
            $redis = self::getRedisConnection();
            $now = time();
            $nowStr = date('Y-m-d H:i:s', $now);
            
            $messagesKey = self::getRedisKey('messages', $name);
            $queueKey = self::getRedisKey('queue', $name);
            $delayKey = self::getRedisKey('delay', $name);
            
            $msgData = $redis->hGet($messagesKey, $unqid);
            if (!$msgData) {
                return false;
            }
            
            $row = json_decode($msgData, true);
            
            // 检查是否被锁定
            if (!empty($row['lockMark'])) {
                return false;
            }
            
            // 重置
            $row['syncCount'] = 0;
            $row['syncLevel'] = 0;
            $row['lockTime'] = $nowStr;
            $row['updateTime'] = $nowStr;
            
            $redis->hSet($messagesKey, $unqid, json_encode($row, JSON_UNESCAPED_UNICODE));
            
            // 从延迟队列移除并加入待处理队列
            $redis->zRem($delayKey, $unqid);
            
            // 避免重复添加到队列
            $redis->lRem($queueKey, 0, $unqid);
            $redis->rPush($queueKey, $unqid);
            
            return true;
        } catch (\Exception $e) {
            error_log("[MqManager::resetByUnqidRedis] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function lockByUnqidRedis($name, $unqid)
    {
        try {
            $redis = self::getRedisConnection();
            $lockMark = self::generateLockMark();
            $now = time();
            $nowStr = date('Y-m-d H:i:s', $now);
            
            $messagesKey = self::getRedisKey('messages', $name);
            $lockKey = self::getRedisKey('lock', $name);
            $queueKey = self::getRedisKey('queue', $name);
            $delayKey = self::getRedisKey('delay', $name);
            
            $msgData = $redis->hGet($messagesKey, $unqid);
            if (!$msgData) {
                return false;
            }
            
            $row = json_decode($msgData, true);
            
            // 检查是否已被锁定
            if (!empty($row['lockMark'])) {
                return false;
            }
            
            // 锁定消息
            $row['lockMark'] = $lockMark;
            $row['lockTime'] = $nowStr;
            $redis->hSet($messagesKey, $unqid, json_encode($row, JSON_UNESCAPED_UNICODE));
            
            // 添加到锁定集合
            $redis->hSet($lockKey, $unqid, json_encode([
                'lockMark' => $lockMark,
                'lockTime' => $now
            ]));
            
            // 从待处理队列和延迟队列移除
            $redis->lRem($queueKey, 0, $unqid);
            $redis->zRem($delayKey, $unqid);
            
            return $lockMark;
        } catch (\Exception $e) {
            error_log("[MqManager::lockByUnqidRedis] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function unlockByUnqidRedis($name, $unqid, $lockMark, $incrementRetry = true, $delaySeconds = 0)
    {
        try {
            $redis = self::getRedisConnection();
            $now = time();
            $nowStr = date('Y-m-d H:i:s', $now);
            
            $messagesKey = self::getRedisKey('messages', $name);
            $lockKey = self::getRedisKey('lock', $name);
            $queueKey = self::getRedisKey('queue', $name);
            $delayKey = self::getRedisKey('delay', $name);
            
            $msgData = $redis->hGet($messagesKey, $unqid);
            if (!$msgData) {
                return false;
            }
            
            $row = json_decode($msgData, true);
            
            // 验证锁标识
            if (($row['lockMark'] ?? '') !== $lockMark) {
                return false;
            }
            
            if ($incrementRetry) {
                $row['syncCount'] = ($row['syncCount'] ?? 0) + 1;
                $row['syncLevel'] = ($row['syncLevel'] ?? 0) + 1;
                
                if ($delaySeconds > 0) {
                    $executeTime = $now + $delaySeconds;
                } else {
                    // 默认延迟（syncLevel * 5分钟）
                    $executeTime = $now + $row['syncLevel'] * 5 * 60;
                }
                $row['lockTime'] = date('Y-m-d H:i:s', $executeTime);
            } else {
                $row['lockTime'] = '2000-01-01 00:00:00';
            }
            
            $row['lockMark'] = '';
            $row['updateTime'] = $nowStr;
            
            $redis->hSet($messagesKey, $unqid, json_encode($row, JSON_UNESCAPED_UNICODE));
            
            // 从锁定集合移除
            $redis->hDel($lockKey, $unqid);
            
            // 根据是否有延迟决定放入哪个队列
            if ($incrementRetry && isset($executeTime) && $executeTime > $now) {
                $redis->zAdd($delayKey, $executeTime, $unqid);
            } else {
                $redis->rPush($queueKey, $unqid);
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("[MqManager::unlockByUnqidRedis] Error: " . $e->getMessage());
            return false;
        }
    }

    protected static function ackByUnqidRedis($name, $unqid, $lockMark)
    {
        try {
            $redis = self::getRedisConnection();
            
            $messagesKey = self::getRedisKey('messages', $name);
            $lockKey = self::getRedisKey('lock', $name);
            $allKey = self::getRedisKey('all');
            
            // 验证锁标识
            $msgData = $redis->hGet($messagesKey, $unqid);
            if ($msgData) {
                $row = json_decode($msgData, true);
                if (($row['lockMark'] ?? '') !== $lockMark) {
                    return false;
                }
            }
            
            // 删除消息
            $redis->hDel($messagesKey, $unqid);
            $redis->hDel($lockKey, $unqid);
            $redis->zRem($allKey, "{$name}:{$unqid}");
            
            return true;
        } catch (\Exception $e) {
            error_log("[MqManager::ackByUnqidRedis] Error: " . $e->getMessage());
            return false;
        }
    }
}
