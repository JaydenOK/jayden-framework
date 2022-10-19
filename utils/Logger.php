<?php

namespace app\utils;

/**
 * Class LoggerUtil
 * @package app\utils
 */
class LoggerUtil
{
    //日志等级，默认info
    const LEVEL_TRACE = 'trace';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_INFO = 'info';
    const LEVEL_PROFILE = 'profile';
    //可选择最大日志记录数时，再刷新缓存写入文件
    const MAX_LOGS = 1000;
    public $maxLogFiles = 5;
    //限制日志文件大小，超过自动备份，单位M
    public $maxFileSize = 200;
    //是否先备份再清空原始文件，
    public $rotateByCopy = true;

    private $logPath = '';
    private $logFileName = '';
    //单个类型log
    private $logs = [];
    private $logCount = 0;
    private $dirMode = 0755;
    //日志日期格式
    private $dateFormat = 'Y-m-d H:i:s';

    private static $instance = null;

    /**
     * Logger constructor.
     * @param null $logPath 指定目录为空，使用当前上上个目录下logs目录
     * @param null $logFileName
     * @param int $dirMode
     */
    public function __construct($logPath = null, $logFileName = null, $dirMode = 0755)
    {
        if (empty($logPath)) {
            $logPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
        }
        $this->dirMode = $dirMode ?? $this->dirMode;
        $this->logPath = rtrim($logPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, $this->dirMode, true);
        }
        $this->logFileName = $logFileName;
    }

    /**
     * 获取日志实例.
     * @param null $logPath
     * @param null $logFileName
     * @param null $dirMode
     * @return Logger|null
     */
    public static function getLogger($logPath = null, $logFileName = null, $dirMode = null)
    {
        if (isset(self::$instance) && null !== self::$instance) {
            return self::$instance;
        }
        self::$instance = new self($logPath, $logFileName, $dirMode);

        return self::$instance;
    }

    /**
     * 格式化日志信息.
     * @param $message
     * @param $level
     * @param $category
     * @param $time
     * @return string
     */
    public function formatLogMessage($message, $level, $category, $time)
    {
        return @date("[{$this->dateFormat}]", $time) . " [$level] [$category] $message\n";
    }

    /**
     * 日志分类处理
     * @param $message
     * @param string $level
     * @param null $category
     * @param bool $flush
     * @throws \Exception
     */
    public function log($message, $level = self::LEVEL_INFO, $category = null, $flush = true)
    {
        if (empty($category)) {
            $category = $this->logFileName;
        }
        $this->logs[$category][] = [$message, $level, $category, microtime(true)];
        $this->logCount++;
        if ($this->logCount >= self::MAX_LOGS || true == $flush) {
            $this->flush();
        }
    }

    /**
     * 日志分类处理.
     * @return array
     */
    public function processLogs()
    {
        $logsAll = [];
        foreach ((array)$this->logs as $key => $logs) {
            $logsAll[$key] = '';
            foreach ((array)$logs as $log) {
                $logsAll[$key] .= $this->formatLogMessage($log[0], $log[1], $log[2], $log[3]);
            }
        }

        return $logsAll;
    }

    /**
     * 写日志到文件
     * @return bool
     * @throws \Exception
     */
    public function flush()
    {
        if ($this->logCount <= 0) {
            return false;
        }
        $logsAll = $this->processLogs();
        $this->write($logsAll);
        $this->logs = [];
        $this->logCount = 0;
    }

    /**
     * 根据日志类型写到不同的日志文件
     * @param $logsAll
     * @throws \Exception
     */
    public function write($logsAll)
    {
        if (empty($logsAll)) {
            return;
        }
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, $this->dirMode, true);
        }
        foreach ($logsAll as $key => $value) {
            if (empty($key)) {
                continue;
            }
            $fileName = $this->logPath . '/' . $key;

            if (false === ($fp = @fopen($fileName, 'a'))) {
                throw new \Exception("Unable to append to log file: {$fileName}");
            }
            @flock($fp, LOCK_EX);
            if (@filesize($fileName) > $this->maxFileSize * 1024 * 1024) {
                $this->rotateFiles($fileName);
            }
            @fwrite($fp, $value);
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /**
     * 旋转日志文件
     * @param $file
     */
    protected function rotateFiles($file)
    {
        for ($i = $this->maxLogFiles; $i >= 0; --$i) {
            // $i == 0 is the original log file
            $rotateFile = $file . (0 === $i ? '' : '.' . $i);
            if (is_file($rotateFile)) {
                // suppress errors because it's possible multiple processes enter into this section
                if ($i === $this->maxLogFiles) {
                    @unlink($rotateFile);
                } else {
                    if ($this->rotateByCopy) {
                        //先备份再清空原始文件
                        @copy($rotateFile, $file . '.' . ($i + 1));
                        if ($fp = @fopen($rotateFile, 'a')) {
                            @ftruncate($fp, 0);
                            @fclose($fp);
                        }
                    } else {
                        //直接移动旧文件（速度快）
                        @rename($rotateFile, $file . '.' . ($i + 1));
                    }
                }
            }
        }
    }
}