<?php
//stream_wrapper_register — 注册一个用 PHP 类实现的 URL 封装协议
//允许用户实现自定义的协议处理器和流，用于所有其它的文件系统函数中（例如 fopen()，fread() 等）。
//demo1

class VariableStream
{
    var $position;
    var $varname;

    function stream_open($path, $mode, $options, &$opened_path)
    {
        $url = parse_url($path);
        $this->varname = $url["host"];
        $this->position = 0;

        return true;
    }

    function stream_read($count)
    {
        $ret = substr($GLOBALS[$this->varname], $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    function stream_write($data)
    {
        $left = substr($GLOBALS[$this->varname], 0, $this->position);
        $right = substr($GLOBALS[$this->varname], $this->position + strlen($data));
        $GLOBALS[$this->varname] = $left . $data . $right;
        $this->position += strlen($data);
        return strlen($data);
    }

    function stream_tell()
    {
        return $this->position;
    }

    function stream_eof()
    {
        return $this->position >= strlen($GLOBALS[$this->varname]);
    }

    function stream_seek($offset, $whence)
    {
        switch ($whence) {
            case SEEK_SET:
                if ($offset < strlen($GLOBALS[$this->varname]) && $offset >= 0) {
                    $this->position = $offset;
                    return true;
                } else {
                    return false;
                }
                break;

            case SEEK_CUR:
                if ($offset >= 0) {
                    $this->position += $offset;
                    return true;
                } else {
                    return false;
                }
                break;

            case SEEK_END:
                if (strlen($GLOBALS[$this->varname]) + $offset >= 0) {
                    $this->position = strlen($GLOBALS[$this->varname]) + $offset;
                    return true;
                } else {
                    return false;
                }
                break;

            default:
                return false;
        }
    }

    function stream_metadata($path, $option, $var)
    {
        if ($option == STREAM_META_TOUCH) {
            $url = parse_url($path);
            $varname = $url["host"];
            if (!isset($GLOBALS[$varname])) {
                $GLOBALS[$varname] = '';
            }
            return true;
        }
        return false;
    }
}

stream_wrapper_register("var", "VariableStream") or die("Failed to register protocol");

$myvar = "";

$fp = fopen("var://myvar", "r+");

fwrite($fp, "line1\n");
fwrite($fp, "line2\n");
fwrite($fp, "line3\n");

rewind($fp);
while (!feof($fp)) {
    echo fgets($fp);
}
fclose($fp);
var_dump($myvar);

##############  demo 2
/*

class DBStream
{
    private $_pdo;
    private $_ps;
    private $_rowId = 0;

    function stream_open($path, $mode, $options, &$opath)
    {
        $url = parse_url($path);
        $url['path'] = substr($url['path'], 1);
        try {
            $this->_pdo = new PDO("mysql:host={$url['host']};dbname={$url['path']}", $url['user'], isset($url['pass']) ? $url['pass'] : '', array());
        } catch (PDOException $e) {
            return false;
        }
        switch ($mode) {
            case 'w' :
                $this->_ps = $this->_pdo->prepare('INSERT INTO data VALUES(null, ?, NOW())');
                break;
            case 'r' :
                $this->_ps = $this->_pdo->prepare('SELECT id, data FROM data WHERE id > ? LIMIT 1');
                break;
            default  :
                return false;
        }
        return true;
    }

    function stream_read()
    {
        $this->_ps->execute(array($this->_rowId));
        if ($this->_ps->rowCount() == 0) return false;
        $this->_ps->bindcolumn(1, $this->_rowId);
        $this->_ps->bindcolumn(2, $ret);
        $this->_ps->fetch();
        return $ret;
    }

    function stream_write($data)
    {
        $this->_ps->execute(array($data));
        return strlen($data);
    }

    function stream_tell()
    {
        return $this->_rowId;
    }

    function stream_eof()
    {
        $this->_ps->execute(array($this->_rowId));
        return (bool)$this->_ps->rowCount();
    }

    function stream_seek($offset, $step)
    {
        //No need to be implemented
    }
}

stream_register_wrapper('db', 'DBStream');

$fr = fopen('db://testuser@localhost/testdb', 'r');
$fw = fopen('db://testuser:testpassword@localhost/testdb', 'w');
//The two forms above are accepted : for the former, the default password "" will be used

$alg = hash_algos();
$al = $alg[array_rand($alg)];
$data = hash($al, rand(rand(0, 9), rand(10, 999))); // Some random data to be written
fwrite($fw, $data); // Writing the data to the wrapper
while ($a = fread($fr, 256)) { //A loop for reading from the wrapper
    echo $a . '<br />';
}

*/