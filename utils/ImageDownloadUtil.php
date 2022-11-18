<?php
/**
 * 图片文件批量压缩下载
 * $imageList = ['/tmp/a.jpg', '/tmp/b.jpg'];
 * $zipName = date('Ymd_His_') . mt_rand(10, 99) . '.zip';
 * ImageDownloadUtil::download($zipName, $imageList);
 */

namespace app\utils;

class ImageDownloadUtil
{
    var $dataSec = [];
    var $ctrlDir = [];
    var $eofCtrlDir = "\x50\x4b\x05\x06\x00\x00\x00\x00";
    var $oldOffset = 0;

    /**
     *
     * @param $filename
     * @param $imageList
     */
    public static function download($filename, $imageList)
    {
        $util = new self();
        $path = PHP_OS == "Linux" ? '/tmp' : 'D:/imageDown';
        $tmpFile = tempnam($path, 'tmp');
        foreach ($imageList as $imagePath) {
            $util->addFile(file_get_contents($imagePath), basename($imagePath));
        }
        $util->output($tmpFile);
        ob_clean();
        header('Pragma: public');
        header('Last-Modified:' . gmdate('D, d M Y H:i:s') . 'GMT');
        header('Cache-Control:no-store, no-cache, must-revalidate');
        header('Cache-Control:pre-check=0, post-check=0, max-age=0');
        header('Content-Transfer-Encoding:binary');
        header('Content-Encoding:none');
        header('Content-type:multipart/form-data');
        header('Content-Disposition:attachment; filename="' . $filename . '"');
        header('Content-length:' . filesize($tmpFile));
        $fp = fopen($tmpFile, 'r');
        while (connection_status() == 0 && $buf = @fread($fp, 8192)) {
            echo $buf;
        }
        fclose($fp);
        @unlink($tmpFile);
        @flush();
        @ob_flush();
        exit(0);
    }

    public function unixToDosTime($unixTime = 0)
    {
        $timeArray = ($unixTime == 0) ? getdate() : getdate($unixTime);
        if ($timeArray ['year'] < 1980) {
            $timeArray ['year'] = 1980;
            $timeArray ['mon'] = 1;
            $timeArray ['mday'] = 1;
            $timeArray ['hours'] = 0;
            $timeArray ['minutes'] = 0;
            $timeArray ['seconds'] = 0;
        }
        return (($timeArray ['year'] - 1980) << 25) | ($timeArray ['mon'] << 21) | ($timeArray ['mday'] << 16) | ($timeArray ['hours'] << 11) | ($timeArray ['minutes'] << 5) | ($timeArray ['seconds'] >> 1);
    }

    /**
     * 添加压缩文件
     * @param $data
     * @param $showName
     * @param int $time
     */
    public function addFile($data, $showName, $time = 0)
    {
        $showName = str_replace('\\', '/', $showName);
        $dTime = dechex($this->unixToDosTime($time));
        $hexDTime = '\x' . $dTime [6] . $dTime [7] .
            '\x' . $dTime [4] . $dTime [5] .
            '\x' . $dTime [2] . $dTime [3] .
            '\x' . $dTime [0] . $dTime [1];
        eval('$hexdtime = "' . $hexDTime . '";');
        $fr = "\x50\x4b\x03\x04";
        $fr .= "\x14\x00";
        $fr .= "\x00\x00";
        $fr .= "\x08\x00";
        $fr .= $hexDTime;
        $unc_len = strlen($data);
        $crc = crc32($data);
        $zData = gzcompress($data);
        $zData = substr(substr($zData, 0, strlen($zData) - 4), 2);
        $cLen = strlen($zData);
        $fr .= pack('V', $crc);
        $fr .= pack('V', $cLen);
        $fr .= pack('V', $unc_len);
        $fr .= pack('v', strlen($showName));
        $fr .= pack('v', 0);
        $fr .= $showName;
        $fr .= $zData;
        $fr .= pack('V', $crc);
        $fr .= pack('V', $cLen);
        $fr .= pack('V', $unc_len);
        $this->dataSec[] = $fr;
        $cdRec = "\x50\x4b\x01\x02";
        $cdRec .= "\x00\x00";
        $cdRec .= "\x14\x00";
        $cdRec .= "\x00\x00";
        $cdRec .= "\x08\x00";
        $cdRec .= $hexDTime;
        $cdRec .= pack('V', $crc);
        $cdRec .= pack('V', $cLen);
        $cdRec .= pack('V', $unc_len);
        $cdRec .= pack('v', strlen($showName));
        $cdRec .= pack('v', 0);
        $cdRec .= pack('v', 0);
        $cdRec .= pack('v', 0);
        $cdRec .= pack('v', 0);
        $cdRec .= pack('V', 32);
        $cdRec .= pack('V', $this->oldOffset);
        $this->oldOffset += strlen($fr);
        $cdRec .= $showName;
        $this->ctrlDir[] = $cdRec;
    }

    /**
     * 递归压缩整个目录
     * @param $path
     * @param int $l
     */
    public function addPath($path, $l = 0)
    {
        $d = @opendir($path);
        $l = $l > 0 ? $l : strlen($path) + 1;
        while ($v = @readdir($d)) {
            if ($v == '.' || $v == '..') {
                continue;
            }
            $v = $path . '/' . $v;
            if (is_dir($v)) {
                $this->addPath($v, $l);
            } else {
                $this->addFile(file_get_contents($v), substr($v, $l));
            }
        }
    }

    public function addFiles($files)
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                $data = implode("", file($file));
                $this->addFile($data, $file);
            }
        }
    }

    public function file()
    {
        $data = implode('', $this->dataSec);
        $ctrlDir = implode('', $this->ctrlDir);
        return $data . $ctrlDir . $this->eofCtrlDir
            . pack('v', sizeof($this->ctrlDir))
            . pack('v', sizeof($this->ctrlDir))
            . pack('V', strlen($ctrlDir))
            . pack('V', strlen($data)) . "\x00\x00";
    }

    public function output($file)
    {
        $fp = fopen($file, "w");
        fwrite($fp, $this->file());
        fclose($fp);
    }

}