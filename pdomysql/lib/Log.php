<?php

namespace module\lib;

use DateTime;

class Log
{
    private $path = '/logs/';
    public function __construct()
    {
        date_default_timezone_set('PRC');
        $this->path = dirname(__FILE__) . $this->path;
    }

    public function write($message)
    {
        $date = new DateTime();
        $log = $this->path . $date->format('Y-m-d') . ".txt";
        if (is_dir($this->path)) {
            if (!file_exists($log)) {
                $fh = fopen($log, 'a+') or die("Fatal Error !");
                $logContent = "Time : " . $date->format('H:i:s') . PHP_EOL . $message . PHP_EOL;
                fwrite($fh, $logContent);
                fclose($fh);
            } else {
                $this->edit($log, $date, $message);
            }
        } else {
            if (mkdir($this->path, 0777) === true) {
                $this->write($message);
            }
        }
    }

    private function edit($log, DateTime $date, $message)
    {
        $logContent = "Time : " . $date->format('H:i:s') . PHP_EOL . $message . PHP_EOL . PHP_EOL;
        $logContent = $logContent . file_get_contents($log);
        file_put_contents($log, $logContent);
    }
}
