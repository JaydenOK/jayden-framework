<?php

namespace app\api;


use app\core\lib\controller\Controller;
use app\service\simpledi\CacheManager;
use app\service\simpledi\MongoDB;
use app\service\simpledi\Redis;
use Cekta\DI\Container;
use Cekta\DI\Provider\Autowiring;
use Cekta\DI\Provider\AutowiringSimpleCache;
use stdClass;

class SimpleDiController extends Controller
{
    public function test()
    {
//        $manager = new CacheManager();
        $redis = new Redis();
        $mongoDB = new MongoDB();
        $container = new Container($redis, $mongoDB);
        $cacheManager = $container->get('redis');
//        $cacheManager = $container->get('CacheManager');
//        echo $cacheManager->get('liming');
    }

    public function auto()
    {
        $cache = new ArrayCachePool();
        $providers[] = new AutowiringSimpleCache($cache, new Autowiring());
        $container = new Container(... $providers);

        $start = microtime(true);
        $container->get(stdClass::class);
        $result = number_format(microtime(true) - $start, 17);
        echo "$result используя Reflection и помещает в кэш" . PHP_EOL;

        $start = microtime(true);
        $container->get(stdClass::class);
        $result = number_format(microtime(true) - $start, 17);
        echo "$result последующие вызовы идут минуя Provider и Reflection" . PHP_EOL;

        $container = new Container(...$providers);

        $start = microtime(true);
        $container->get(stdClass::class);
        $result = number_format(microtime(true) - $start, 17);
        echo "$result минуя Reflection используя Cache" . PHP_EOL;

        $start = microtime(true);
        $container->get(stdClass::class);
        $result = number_format(microtime(true) - $start, 17);
        echo "$result последующие вызовы идут минуя Provider и Reflection" . PHP_EOL;
    }
}