<?php
class SwooleServerManager
{
    const SERVER_RUNNING = 'running';
    
    const SERVER_STOP = 'stop';
    
    const SERVER_UNKOWN = 'unknow';
    
    public static function createSwooleServer($protocol = '', $configs = [])
    {
        //启动服务
        switch ($protocol)
        {
            case 'websocket' :
                return new SwooleWebSocket($configs);
            default:
                return new SwooleServer($configs);
        }
    }
    
    public static function closeServer($pid = null)
    {
        //关闭服务
        return swoole_process::kill($pid, SIGTERM);
    }
    
    public static function serverIsRunning($pid = null)
    {
        //检查服务器是否运行中
        return swoole_process::kill($pid, 0);
    }
    
    public static function connectionServer($host = '127.0.0.1', $port = '')
    {
        //连接服务
        $client = new swoole_client(SWOOLE_SOCK_TCP);
        $client->connect($host, $port);
        return $client;
    }
    
    public static function launchServer(SwooleServer $server)
    {
        return $server->startServer();
    }
}