<?php

class t
{
    private static $attrs;
    private static $cacheCode;
    private $context;
    private $code;
    private $stat;
    private $seek = 0;
    private $i = 0;

    public static function start()
    {
        //注入协议封装
        stream_wrapper_register('of.incl', __CLASS__);
        define('OF_DIR', __DIR__);

        //echo 'end--start';
    }

    public static function test()
    {
        //加载index.php脚本
        //include 'of.incl://' . Co::getCid() . '://1://' . $_FUNC_mapVar->serial = $file;
        // ://是否全局://路径://代码
        //include 'of.incl://' . '{cid}' . '://1://' . 'index.php';
        include 'of.incl://' . '{cid}' . '://1://' . 't2.php';
        exit('end test');
        //Array
        //(
        //    [0] => of.incl
        //    [1] => {cid}
        //    [2] => 1
        //    [3] => index.php
        //)
    }

    public function stream_cast(int $cast_as)       //: resource
    {
        echo __METHOD__ . ':' . $this->i++ . PHP_EOL;
        return null;
    }

    public function stream_flush(): bool
    {
        echo __METHOD__ . ':' . $this->i++ . PHP_EOL;
        return true;
    }

    public function stream_lock(int $operation): bool
    {
        echo __METHOD__ . ':' . $this->i++ . PHP_EOL;
        return true;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        echo __METHOD__ . ':' . $this->i++ . PHP_EOL;
        return true;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        echo __METHOD__ . ':' . $this->i++ . PHP_EOL;
        $path = explode('://', $path, 5);
        $file = realpath($path[3]);
        //文件加载失败
        $code = file_get_contents($file);
        //改写代码
        $code = $code . PHP_EOL . PHP_EOL . '//testEndCode';
        //统计代码长度
        $stat['size'] = strlen($code);
        //改后信息
        $this->stat = $stat;
        //改后代码
        $this->code = $code;
        //返回结果
        return true;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        echo __METHOD__ . ':' . $this->i++ . PHP_EOL;
        return true;
    }

    public function stream_read(int $count): string|false
    {
        echo __METHOD__ . ':' . $this->i++ . PHP_EOL;
        //$count = 64;     //测试
        //echo 'count:' . $count . PHP_EOL;
        $code = substr($this->code, $this->seek, $count);
        $this->seek += strlen($code);
        return $code;
    }

    //判断流结束，stream_read后stream_eof判断，返回false继续stream_read，否则stream_close
    public function stream_eof(): bool
    {
        echo __METHOD__ . ':' . $this->i++ . PHP_EOL;
        return $this->seek >= $this->stat['size'];
    }

    public function stream_close(): void
    {
        echo __METHOD__ . ':' . $this->i++ . PHP_EOL;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        echo __METHOD__ . ':' . $this->i++ . PHP_EOL;
        return true;
    }

    //读取流信息，长度[size=>0]
    public function stream_stat(): array|false
    {
        return $this->stat;
    }

    public function stream_tell(): int
    {
        return 0;
    }

    public function stream_truncate(int $new_size): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }
}

t::start();
t::test();

//测试
//t: :stream_open: 0
//t: :stream_set_option: 1
//t: :stream_read: 2
//t: :stream_eof: 3
//t: :stream_read: 4
//t: :stream_eof: 5
//t: :stream_read: 6
//t: :stream_eof: 7
//t: :stream_read: 8
//t: :stream_eof: 9
//t: :stream_close: 10
//t2: :show
//end test