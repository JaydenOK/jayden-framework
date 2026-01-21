<?php

namespace app\system;

/**
 * 数据库连接管理器
 *
 * 支持多虚拟主机数据库连接池管理，支持多环境配置
 *
 * 环境配置：
 * - 在项目入口文件定义常量 ENV 来选择环境
 * - 'local' 本地环境 -> db-local.php
 * - 'dev' 开发环境 -> db-dev.php
 * - 'test' 测试环境 -> db-test.php
 * - 'pro' 生产环境 -> db.php（默认）
 *
 * 使用示例：
 * ```php
 * // 入口文件设置环境
 * defined('ENV') || define('ENV', 'dev');
 *
 * // 获取默认虚拟主机连接
 * $pdo = DbManager::getConnection();
 *
 * // 获取指定虚拟主机连接
 * $pdo = DbManager::getConnection('iscs');
 *
 * // 获取当前环境
 * $env = DbManager::getEnv();  // 'local', 'dev', 'test', 'pro'
 *
 * // 关闭指定连接
 * DbManager::close('iscs');
 *
 * // 关闭所有连接
 * DbManager::closeAll();
 * ```
 */
class DbManager
{
    /** @var array 数据库连接池 [vHost => PDO] */
    protected static $connections = [];

    /** @var array|null 配置缓存 */
    protected static $configs = null;

    /** @var string 配置目录 */
    protected static $configDir = __DIR__ . '/config';

    /**
     * 获取数据库连接
     * @param string $vHost 虚拟主机名称
     * @return \PDO
     * @throws \RuntimeException
     */
    public static function getConnection($vHost = 'default')
    {
        if (!isset(self::$connections[$vHost])) {
            self::$connections[$vHost] = self::createConnection($vHost);
        }
        return self::$connections[$vHost];
    }

    /**
     * 创建数据库连接
     * @param string $vHost 虚拟主机名称
     * @return \PDO
     * @throws \RuntimeException
     */
    protected static function createConnection($vHost)
    {
        $config = self::getConfig($vHost);
        if (empty($config)) {
            throw new \RuntimeException("数据库配置不存在: {$vHost}");
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'] ?? 3306,
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // 设置字符集
        $charset = $config['charset'] ?? 'utf8mb4';
        $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$charset}";

        return new \PDO($dsn, $config['user'], $config['password'], $options);
    }

    /**
     * 获取指定虚拟主机的配置
     * @param string $vHost 虚拟主机名称
     * @return array
     */
    public static function getConfig($vHost = 'default')
    {
        $configs = self::getAllConfigs();
        return $configs[$vHost] ?? $configs['default'] ?? [];
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
     * 判断是否为本地环境
     * @return bool
     */
    public static function isLocal()
    {
        return self::getEnv() === 'local';
    }

    /**
     * 判断是否为开发环境
     * @return bool
     */
    public static function isDev()
    {
        return self::getEnv() === 'dev';
    }

    /**
     * 判断是否为测试环境
     * @return bool
     */
    public static function isTest()
    {
        return self::getEnv() === 'test';
    }

    /**
     * 判断是否为生产环境
     * @return bool
     */
    public static function isPro()
    {
        return self::getEnv() === 'pro';
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
     * 设置配置（运行时覆盖）
     * @param string $vHost 虚拟主机名称
     * @param array $config 配置数组
     */
    public static function setConfig($vHost, array $config)
    {
        if (self::$configs === null) {
            self::getAllConfigs();
        }
        self::$configs[$vHost] = array_merge(self::$configs[$vHost] ?? [], $config);
        
        // 重置该虚拟主机的连接
        self::close($vHost);
    }

    /**
     * 获取所有虚拟主机列表
     * @return array
     */
    public static function getVHostList()
    {
        return array_keys(self::getAllConfigs());
    }

    /**
     * 检查虚拟主机是否存在
     * @param string $vHost 虚拟主机名称
     * @return bool
     */
    public static function hasVHost($vHost)
    {
        return isset(self::getAllConfigs()[$vHost]);
    }

    /**
     * 关闭指定虚拟主机的连接
     * @param string $vHost 虚拟主机名称
     */
    public static function close($vHost)
    {
        if (isset(self::$connections[$vHost])) {
            self::$connections[$vHost] = null;
            unset(self::$connections[$vHost]);
        }
    }

    /**
     * 关闭所有连接
     */
    public static function closeAll()
    {
        foreach (array_keys(self::$connections) as $vHost) {
            self::$connections[$vHost] = null;
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
            $pdo = self::getConnection($vHost);
            $pdo->query('SELECT 1');
            return true;
        } catch (\Exception $e) {
            // 连接失败，移除无效连接
            self::close($vHost);
            return false;
        }
    }

    /**
     * 重连（关闭后重新连接）
     * @param string $vHost 虚拟主机名称
     * @return \PDO
     */
    public static function reconnect($vHost = 'default')
    {
        self::close($vHost);
        return self::getConnection($vHost);
    }
}
