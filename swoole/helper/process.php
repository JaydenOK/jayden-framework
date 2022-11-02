<?php
/**
 * User:    Yejia
 * Email:   ye91@foxmail.com
 */

//初始化变量
$requestHost = 'huaban.com';
$requestUrl = '/partner/uc/aimeinv/pins?limit=10&wfl=1';
$imageHost = 'hbimg.b0.upaiyun.com';
$dir = __DIR__ . DIRECTORY_SEPARATOR . 'images/';


//初始化管道
$requestChan = new Chan(500);
$parserChan = new Chan(500);
$downloadChan = new Chan(500);

//入口开始
go(function () use ($requestChan, $requestHost, $requestUrl) {
    $requestChan->push([
        'host' => $requestHost,
        'url'  => $requestUrl
    ]);
});


for ($p = 1; $p <= swoole_cpu_num() * 2; $p++) {

    $pro = new Swoole\Process(function (\Swoole\Process $worker) use ($requestChan, $parserChan, $downloadChan, $requestHost, $requestUrl, $dir, $imageHost) {

        for ($w = 1; $w <= 100; $w++) {

            go(function () use ($requestChan, $parserChan) {
                while (true) {
                    $data = $requestChan->pop();
                    get_html($data['host'], $data['url'], $parserChan);
                }
            });

            go(function () use ($parserChan, $requestChan, $downloadChan, $requestHost, $requestUrl) {
                while (true) {
                    $html = $parserChan->pop();
                    parse($html, $downloadChan, $requestChan, $requestHost, $requestUrl);
                }
            });

            go(function () use ($downloadChan, $dir, $imageHost, $worker) {
                while (true) {
                    download($downloadChan->pop(), $dir, $imageHost, $worker->pid);
                }
            });
        }
    });

    $pro->start();
}

\Swoole\Process::wait();

/**
 * 获取内容
 *
 * @param string $url  Url地址
 * @param Chan   $chan 解析管道
 */
function get_html(string $host, string $url, Chan $chan)
{
    $client = new  \Swoole\Coroutine\Http\Client($host, 443, true);
    $client->setHeaders([
        'Host'            => $host,
        "User-Agent"      => getUserAgent(),
        'Accept'          => 'text/html,application/xhtml+xml,application/xml',
        'Accept-Encoding' => 'gzip',
    ]);
    $client->get($url);
    $chan->push($client->body);
}

/**
 * 下载文件
 *
 * @param string $url       文件Url
 * @param string $dir       存储目录
 * @param string $imageHost 文件基础Url
 * @param int    $pid       PID
 */
function download(string $url, string $dir, string $imageHost, int $pid)
{
    if (!is_dir($dir)) {
        mkdir($dir);
    }
    if (!strpos($url, '-')) {
        return;
    }
    $client = new  \Swoole\Coroutine\Http\Client($imageHost, 80, false);
    $client->setHeaders([
        'Host'       => $imageHost,
        "User-Agent" => getUserAgent(),
    ]);
    $client->download(DIRECTORY_SEPARATOR . $url, $dir . $url . '.png');
    echo sprintf("PID: %d, %s\n", $pid, $imageHost . DIRECTORY_SEPARATOR . $url);
}

/**
 * 解析数据
 *
 * @param string $html         html内容
 * @param Chan   $downloadChan 下载管道
 * @param Chan   $requestChan  请求管道
 * @param string $requestHost  请求Url
 * @param string $requestUrl   请求Url
 */
function parse(string $html, Chan $downloadChan, Chan $requestChan, string $requestHost, string $requestUrl)
{
    //解析图片
    preg_match_all('/"key":"(.*?)"/', $html, $images);
    if (!empty($images) && !empty($images[1])) {
        foreach ($images[1] as $image) {
            $downloadChan->push($image);
        }
    }
    //解析下一页
    preg_match_all('/"pin_id":(\d+),/', $html, $next);
    if (!empty($next) && !empty($next[1])) {
        $requestChan->push([
            'host' => $requestHost,
            'url'  => $requestUrl . "&max=" . end($next[1])
        ]);
    }
}

/**
 * @return mixed
 */
function getUserAgent()
{
    if (!empty($_SERVER['HTTP_USER_AGENT'])) return $_SERVER['HTTP_USER_AGENT'];
    $userAgents = [
        "Mozilla/5.0 (Windows NT 6.1; rv:2.0.1) Gecko/20100101 Firefox/4.0.1",
        "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/536.11 (KHTML, like Gecko) Chrome/20.0.1132.57 Safari/536.11",
        "Mozilla/5.0 (Windows NT 10.0; WOW64; rv:38.0) Gecko/20100101 Firefox/38.0",
        "Mozilla/5.0 (Windows NT 10.0; WOW64; Trident/7.0; .NET4.0C; .NET4.0E; .NET CLR 2.0.50727; .NET CLR 3.0.30729; .NET CLR 3.5.30729; InfoPath.3; rv:11.0) like Gecko",
        "Mozilla/5.0 (Windows; U; Windows NT 6.1; en-us) AppleWebKit/534.50 (KHTML, like Gecko) Version/5.1 Safari/534.50",
        "Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.0; Trident/4.0)",
        "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.6; rv:2.0.1) Gecko/20100101 Firefox/4.0.1",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_7_0) AppleWebKit/535.11 (KHTML, like Gecko) Chrome/17.0.963.56 Safari/535.11",
    ];
    return $userAgents[array_rand($userAgents, 1)];
}