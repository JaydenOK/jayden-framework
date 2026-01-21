<?php

namespace app\system;

use app\system\DbManager;

/**
 * 消息队列管理器（静态类）- MySQL存储版本
 *
 * 队列存储方式：使用MySQL数据库存储（通过DbManager管理连接）
 *
 * 表结构：
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
 *   `lockTime` timestamp NOT NULL DEFAULT '2000-01-01 00:00:00' COMMENT '锁定时间, 每 syncLevel * 5 分钟重试',
 *   `lockMark` char(32) NOT NULL COMMENT '锁定时生成的唯一ID',
 *   PRIMARY KEY (`name`,`unqid`) USING BTREE,
 *   KEY `idx_msgId` (`msgId`) USING BTREE,
 *   KEY `idx_consumer` (`lockTime`,`name`,`group`) USING BTREE
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息队列表'
 *
 * 使用示例：
 * ```php
 * // 发送消息到队列
 * MqManager::set('testMq1', ['key' => 'value']);
 *
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
     * 获取数据库连接
     * @param string|null $vHost 虚拟主机名称
     * @return \PDO
     */
    protected static function getConnection($vHost = null)
    {
        return DbManager::getConnection($vHost ?? self::$vHost);
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

    /**
     * 发送消息到队列
     * 
     * @param string $mqName 队列名称
     * @param mixed $data 消息数据
     * @param string|null $vhost 虚拟主机（可选，默认default）
     * @param string|null $group 队列组（可选，默认default）
     * @return string|false 成功返回消息ID
     */
    public static function set($mqName, $data, $vhost = null, $group = null)
    {
        try {
            $msgVHost = $vhost ?? self::$vHost;
            $msgGroup = $group ?? self::$group;
            $pdo = self::getConnection($msgVHost);
            $msgId = self::generateMsgId();
            $unqid = self::generateUnqidWithVHost($mqName, $msgId, $msgVHost, $msgGroup);
            $now = date('Y-m-d H:i:s');
            
            $message = [
                'id' => $msgId,
                'data' => $data,
                'time' => time()
            ];
            
            $sql = "INSERT INTO `mq` (`unqid`, `vHost`, `group`, `name`, `msgId`, `data`, `syncCount`, `updateTime`, `createTime`, `syncLevel`, `lockTime`, `lockMark`) 
                    VALUES (:unqid, :vHost, :group, :name, :msgId, :data, 0, :updateTime, :createTime, 0, '2000-01-01 00:00:00', '')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':unqid'      => $unqid,
                ':vHost'      => $msgVHost,
                ':group'      => $msgGroup,
                ':name'       => $mqName,
                ':msgId'      => $msgId,
                ':data'       => json_encode($message, JSON_UNESCAPED_UNICODE),
                ':updateTime' => $now,
                ':createTime' => $now
            ]);
            
            return $msgId;
        } catch (\PDOException $e) {
            error_log("[MqManager::set] Error: " . $e->getMessage());
            return false;
        }
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
        try {
            $pdo = self::getConnection();
            $msgId = $message['id'] ?? self::generateMsgId();
            $unqid = self::generateUnqid($mqName, $msgId);
            $now = date('Y-m-d H:i:s');
            
            // 获取重试次数：优先使用 _syncCount（来自数据库），否则用 syncCount
            $syncCount = $message['_syncCount'] ?? $message['syncCount'] ?? 0;
            $syncLevel = $syncCount;
            
            // 存储前移除内部字段
            $storeMessage = $message;
            unset($storeMessage['_syncCount'], $storeMessage['_syncLevel']);
            
            // 计算锁定时间
            if ($delaySeconds > 0) {
                // 使用自定义延迟秒数
                $lockTime = date('Y-m-d H:i:s', time() + $delaySeconds);
            } else {
                // 根据同步等级设置延迟（每级延迟5分钟）
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
            error_log("[MqManager::push] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取队列长度
     * @param string $mqName 队列名称
     * @return int
     */
    public static function length($mqName)
    {
        try {
            $pdo = self::getConnection();
            $sql = "SELECT COUNT(*) as cnt FROM `mq` WHERE `name` = :name AND `vHost` = :vHost";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':name' => $mqName, ':vHost' => self::$vHost]);
            $row = $stmt->fetch();
            return (int)($row['cnt'] ?? 0);
        } catch (\PDOException $e) {
            error_log("[MqManager::length] Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 清空队列
     * @param string $mqName 队列名称
     * @return bool
     */
    public static function clear($mqName)
    {
        try {
            $pdo = self::getConnection();
            $sql = "DELETE FROM `mq` WHERE `name` = :name AND `vHost` = :vHost";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':name' => $mqName, ':vHost' => self::$vHost]);
            return true;
        } catch (\PDOException $e) {
            error_log("[MqManager::clear] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 预览队列消息（不消费）
     * @param string $mqName 队列名称
     * @param int $limit 限制数量
     * @return array
     */
    public static function peek($mqName, $limit = 10)
    {
        try {
            $pdo = self::getConnection();
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
            error_log("[MqManager::peek] Error: " . $e->getMessage());
            return [];
        }
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
        try {
            $pdo = self::getConnection();
            $now = date('Y-m-d H:i:s');
            $lockMark = self::generateLockMark();
            
            // 尝试锁定一条消息
            // 条件：未锁定（lockMark为空）或锁定已超时
            // 并且 lockTime 已过（用于延迟重试的消息）
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
                return null; // 没有可用消息
            }
            
            // 获取被锁定的消息
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
            error_log("[MqManager::pop] Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 确认消息消费成功，删除消息
     * @param string $mqName 队列名称
     * @param array $message pop返回的消息（需含 _unqid, _lockMark）
     * @return bool
     */
    public static function ack($mqName, $message)
    {
        if (empty($message['_unqid']) || empty($message['_lockMark'])) {
            return false;
        }
        
        try {
            $pdo = self::getConnection();
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
            error_log("[MqManager::ack] Error: " . $e->getMessage());
            return false;
        }
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
        if (empty($message['_unqid']) || empty($message['_lockMark'])) {
            return false;
        }
        
        try {
            $pdo = self::getConnection();
            $now = date('Y-m-d H:i:s');
            
            // 计算新的重试次数和等级
            $syncCount = ($message['_syncCount'] ?? 0) + 1;
            $syncLevel = $syncCount;
            
            // 计算锁定时间（下次可执行时间）
            if ($delaySeconds > 0) {
                $lockTime = date('Y-m-d H:i:s', time() + $delaySeconds);
            } else {
                // 指数增长延迟：2^n 分钟
                $delayMinutes = pow(2, $syncCount);
                $lockTime = date('Y-m-d H:i:s', time() + $delayMinutes * 60);
            }
            
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
            error_log("[MqManager::nack] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 将消息重新放回队列头部（消费失败时）
     * @param string $mqName 队列名称
     * @param array $message 消息数据
     * @return bool
     */
    public static function unshift($mqName, $message)
    {
        try {
            $pdo = self::getConnection();
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
            error_log("[MqManager::unshift] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 根据消息ID获取消息
     * @param string $msgId 消息ID
     * @return array|null
     */
    public static function getByMsgId($msgId)
    {
        try {
            $pdo = self::getConnection();
            $sql = "SELECT `data` FROM `mq` WHERE `msgId` = :msgId AND `vHost` = :vHost LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':msgId' => $msgId, ':vHost' => self::$vHost]);
            $row = $stmt->fetch();
            return $row ? json_decode($row['data'], true) : null;
        } catch (\PDOException $e) {
            error_log("[MqManager::getByMsgId] Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 删除指定消息
     * @param string $msgId 消息ID
     * @return bool
     */
    public static function delete($msgId)
    {
        try {
            $pdo = self::getConnection();
            $sql = "DELETE FROM `mq` WHERE `msgId` = :msgId AND `vHost` = :vHost";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':msgId' => $msgId, ':vHost' => self::$vHost]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("[MqManager::delete] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取所有队列统计信息
     * @return array
     */
    public static function stats()
    {
        try {
            $pdo = self::getConnection();
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
            error_log("[MqManager::stats] Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 释放超时锁定的消息
     * @return int 释放的消息数量
     */
    public static function releaseExpiredLocks()
    {
        try {
            $pdo = self::getConnection();
            $expireTime = date('Y-m-d H:i:s', time() - self::$lockTimeout);
            
            $sql = "UPDATE `mq` SET `lockMark` = '', `lockTime` = '2000-01-01 00:00:00' 
                    WHERE `lockMark` != '' AND `lockTime` < :expireTime AND `vHost` = :vHost";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':expireTime' => $expireTime, ':vHost' => self::$vHost]);
            
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            error_log("[MqManager::releaseExpiredLocks] Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 关闭数据库连接
     * @param string|null $vHost 虚拟主机名称，null表示关闭所有连接
     */
    public static function close($vHost = null)
    {
        if ($vHost !== null) {
            DbManager::close($vHost);
        } else {
            DbManager::closeAll();
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
        try {
            $pdo = self::getConnection();
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
            error_log("[MqManager::getList] Error: " . $e->getMessage());
            return ['list' => [], 'total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'totalPages' => 0];
        }
    }

    /**
     * 获取所有队列名称列表
     * @return array
     */
    public static function getNameList()
    {
        try {
            $pdo = self::getConnection();
            $sql = "SELECT DISTINCT `name` FROM `mq` WHERE `vHost` = :vHost ORDER BY `name`";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':vHost' => self::$vHost]);
            
            $list = [];
            while ($row = $stmt->fetch()) {
                $list[] = $row['name'];
            }
            return $list;
        } catch (\PDOException $e) {
            error_log("[MqManager::getNameList] Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取所有队列组列表
     * @return array
     */
    public static function getGroupList()
    {
        try {
            $pdo = self::getConnection();
            $sql = "SELECT DISTINCT `group` FROM `mq` WHERE `vHost` = :vHost ORDER BY `group`";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':vHost' => self::$vHost]);
            
            $list = [];
            while ($row = $stmt->fetch()) {
                $list[] = $row['group'];
            }
            return $list;
        } catch (\PDOException $e) {
            error_log("[MqManager::getGroupList] Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 根据unqid和name获取消息详情
     * @param string $name 队列名称
     * @param string $unqid 消息唯一标识
     * @return array|null
     */
    public static function getByUnqid($name, $unqid)
    {
        try {
            $pdo = self::getConnection();
            $sql = "SELECT * FROM `mq` WHERE `name` = :name AND `unqid` = :unqid AND `vHost` = :vHost LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':name' => $name, ':unqid' => $unqid, ':vHost' => self::$vHost]);
            $row = $stmt->fetch();
            if ($row) {
                $row['data'] = json_decode($row['data'], true);
            }
            return $row ?: null;
        } catch (\PDOException $e) {
            error_log("[MqManager::getByUnqid] Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 根据unqid和name删除消息
     * @param string $name 队列名称
     * @param string $unqid 消息唯一标识
     * @return bool
     */
    public static function deleteByUnqid($name, $unqid)
    {
        try {
            $pdo = self::getConnection();
            $sql = "DELETE FROM `mq` WHERE `name` = :name AND `unqid` = :unqid AND `vHost` = :vHost";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':name' => $name, ':unqid' => $unqid, ':vHost' => self::$vHost]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("[MqManager::deleteByUnqid] Error: " . $e->getMessage());
            return false;
        }
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
        try {
            $pdo = self::getConnection();
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
            error_log("[MqManager::resetByUnqid] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 锁定消息（用于手动执行）
     * @param string $name 队列名称
     * @param string $unqid 消息唯一标识
     * @return string|false 返回锁标识，失败返回false
     */
    public static function lockByUnqid($name, $unqid)
    {
        try {
            $pdo = self::getConnection();
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
            error_log("[MqManager::lockByUnqid] Error: " . $e->getMessage());
            return false;
        }
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
        try {
            $pdo = self::getConnection();
            
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
            error_log("[MqManager::unlockByUnqid] Error: " . $e->getMessage());
            return false;
        }
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
        try {
            $pdo = self::getConnection();
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
            error_log("[MqManager::ackByUnqid] Error: " . $e->getMessage());
            return false;
        }
    }
}
