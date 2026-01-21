<?php

namespace app\system;

/**
 * Redis连接管理器
 *
 * 支持多虚拟主机Redis连接池管理，支持多环境配置
 *
 * 使用示例：
 * ```php
 * // 获取默认虚拟主机Redis连接
 * $redis = RedisManager::getConnection();
 *
 * // 获取指定虚拟主机Redis连接
 * $redis = RedisManager::getConnection('oms');
 *
 * // 关闭指定连接
 * RedisManager::close('oms');
 *
 * // 关闭所有连接
 * RedisManager::closeAll();
 * ```
 */
class RedisManager
{
    /** @var array Redis连接池 [vHost => Redis] */
    protected static $connections = [];

    /** @var array|null 配置缓存 */
    protected static $configs = null;

    /** @var string 配置目录 */
    protected static $configDir = __DIR__ . '/config';

    /**
     * 获取Redis连接
     * @param string $vHost 虚拟主机名称
     * @return \Redis
     * @throws \RuntimeException
     */
    public static function getConnection($vHost = 'default')
    {
        if (!isset(self::$connections[$vHost])) {
            self::$connections[$vHost] = self::createConnection($vHost);
        }
        
        // 检查连接是否有效
        try {
            self::$connections[$vHost]->ping();
        } catch (\Exception $e) {
            // 连接断开，重新连接
            self::$connections[$vHost] = self::createConnection($vHost);
        }
        
        return self::$connections[$vHost];
    }

    /**
     * 创建Redis连接
     * @param string $vHost 虚拟主机名称
     * @return \Redis
     * @throws \RuntimeException
     */
    protected static function createConnection($vHost)
    {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException("Redis扩展未安装");
        }

        $config = self::getConfig($vHost);
        if (empty($config)) {
            throw new \RuntimeException("Redis配置不存在: {$vHost}");
        }

        // 检查适配器类型
        $adapter = $config['adapter'] ?? 'mysql';
        if ($adapter !== 'redis') {
            throw new \RuntimeException("虚拟主机 {$vHost} 不是Redis类型");
        }

        $redis = new \Redis();
        
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;
        $timeout = $config['timeout'] ?? 2.0;
        
        // 连接Redis
        if (!$redis->connect($host, $port, $timeout)) {
            throw new \RuntimeException("Redis连接失败: {$host}:{$port}");
        }

        // 认证
        if (!empty($config['auth'])) {
            if (!$redis->auth($config['auth'])) {
                throw new \RuntimeException("Redis认证失败: {$vHost}");
            }
        }

        // 选择数据库
        $db = $config['db'] ?? 0;
        if ($db > 0) {
            $redis->select($db);
        }

        // 设置前缀（可选）
        if (!empty($config['prefix'])) {
            $redis->setOption(\Redis::OPT_PREFIX, $config['prefix']);
        }

        return $redis;
    }

    /**
     * 获取指定虚拟主机的配置
     * @param string $vHost 虚拟主机名称
     * @return array
     */
    public static function getConfig($vHost = 'default')
    {
        $configs = self::getAllConfigs();
        return $configs[$vHost] ?? [];
    }

    /**
     * 获取所有配置
     * @return array
     */
    public static function getAllConfigs()
    {
        if (self::$configs === null) {
            self::$configs = self::loadConfigs();
        }
        return self::$configs;
    }

    /**
     * 获取当前环境
     * @return string 'local', 'dev', 'test', 'pro'
     */
    public static function getEnv()
    {
        if (!defined('ENV')) {
            return 'pro';
        }
        $env = strtolower(constant('ENV'));
        if (in_array($env, ['local', 'dev', 'test', 'pro'])) {
            return $env;
        }
        return 'pro';
    }

    /**
     * 获取配置文件路径
     * @return string
     */
    protected static function getConfigFile()
    {
        $env = self::getEnv();
        
        // 非生产环境，尝试加载对应环境配置文件
        if ($env !== 'pro') {
            $envFile = self::$configDir . "/db-{$env}.php";
            if (is_file($envFile)) {
                return $envFile;
            }
        }
        
        // 默认加载生产环境配置
        return self::$configDir . '/db.php';
    }

    /**
     * 加载配置文件
     * @return array
     */
    protected static function loadConfigs()
    {
        $configFile = self::getConfigFile();
        if (is_file($configFile)) {
            return include $configFile;
        }
        return [];
    }

    /**
     * 重新加载配置
     */
    public static function reloadConfigs()
    {
        self::$configs = null;
    }

    /**
     * 获取所有Redis类型的虚拟主机列表
     * @return array
     */
    public static function getRedisVHostList()
    {
        $configs = self::getAllConfigs();
        $list = [];
        foreach ($configs as $vHost => $config) {
            if (($config['adapter'] ?? 'mysql') === 'redis') {
                $list[] = $vHost;
            }
        }
        return $list;
    }

    /**
     * 检查虚拟主机是否为Redis类型
     * @param string $vHost 虚拟主机名称
     * @return bool
     */
    public static function isRedisVHost($vHost)
    {
        $config = self::getConfig($vHost);
        return ($config['adapter'] ?? 'mysql') === 'redis';
    }

    /**
     * 关闭指定虚拟主机的连接
     * @param string $vHost 虚拟主机名称
     */
    public static function close($vHost)
    {
        if (isset(self::$connections[$vHost])) {
            try {
                self::$connections[$vHost]->close();
            } catch (\Exception $e) {
                // ignore
            }
            unset(self::$connections[$vHost]);
        }
    }

    /**
     * 关闭所有连接
     */
    public static function closeAll()
    {
        foreach (array_keys(self::$connections) as $vHost) {
            try {
                self::$connections[$vHost]->close();
            } catch (\Exception $e) {
                // ignore
            }
        }
        self::$connections = [];
    }

    /**
     * 获取当前活跃的连接数
     * @return int
     */
    public static function getActiveConnectionCount()
    {
        return count(self::$connections);
    }

    /**
     * 获取当前活跃的虚拟主机列表
     * @return array
     */
    public static function getActiveVHosts()
    {
        return array_keys(self::$connections);
    }

    /**
     * 测试连接是否有效
     * @param string $vHost 虚拟主机名称
     * @return bool
     */
    public static function ping($vHost = 'default')
    {
        try {
            $redis = self::getConnection($vHost);
            return $redis->ping() === true || $redis->ping() === '+PONG';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 重连（关闭后重新连接）
     * @param string $vHost 虚拟主机名称
     * @return \Redis
     */
    public static function reconnect($vHost = 'default')
    {
        self::close($vHost);
        return self::getConnection($vHost);
    }

    // ==================== 消息队列相关方法 ====================

    /**
     * 获取队列键名
     * @param string $vHost 虚拟主机
     * @param string $mqName 队列名称
     * @return string
     */
    public static function getQueueKey($vHost, $mqName)
    {
        return "mq:{$vHost}:{$mqName}:queue";
    }

    /**
     * 获取消息详情哈希表键名
     * @param string $vHost 虚拟主机
     * @param string $mqName 队列名称
     * @return string
     */
    public static function getMessageHashKey($vHost, $mqName)
    {
        return "mq:{$vHost}:{$mqName}:messages";
    }

    /**
     * 获取延迟队列键名（使用Sorted Set实现）
     * @param string $vHost 虚拟主机
     * @param string $mqName 队列名称
     * @return string
     */
    public static function getDelayKey($vHost, $mqName)
    {
        return "mq:{$vHost}:{$mqName}:delay";
    }

    /**
     * 获取锁定消息集合键名
     * @param string $vHost 虚拟主机
     * @param string $mqName 队列名称
     * @return string
     */
    public static function getLockKey($vHost, $mqName)
    {
        return "mq:{$vHost}:{$mqName}:lock";
    }

    /**
     * 获取队列索引键名（用于按条件筛选）
     * @param string $vHost 虚拟主机
     * @return string
     */
    public static function getQueueIndexKey($vHost)
    {
        return "mq:{$vHost}:index";
    }

    /**
     * 获取队列组索引键名
     * @param string $vHost 虚拟主机
     * @return string
     */
    public static function getGroupIndexKey($vHost)
    {
        return "mq:{$vHost}:groups";
    }

    /**
     * 获取队列名称索引键名
     * @param string $vHost 虚拟主机
     * @return string
     */
    public static function getNameIndexKey($vHost)
    {
        return "mq:{$vHost}:names";
    }

    /**
     * 获取所有消息索引键名（用于分页查询）
     * @param string $vHost 虚拟主机
     * @return string
     */
    public static function getAllMessagesKey($vHost)
    {
        return "mq:{$vHost}:all_messages";
    }
}
