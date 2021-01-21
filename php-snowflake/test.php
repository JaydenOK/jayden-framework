<?php
/**
 * 雪花算法的 PHP 实现 , 分布式ID生成
 * Snowflake 是 Twitter 内部的一个 ID 生算法，可以通过一些简单的规则保证在大规模分布式情况下生成唯一的 ID 号码。其组成为：
 *
 * 第一个 bit 为未使用的符号位。
 * 第二部分由 41 位的时间戳（毫秒）构成，他的取值是当前时间相对于某一时间的偏移量。
 * 第三部分和第四部分的 5 个 bit 位表示数据中心和机器ID，其能表示的最大值为 2^5 -1 = 31。
 * 最后部分由 12 个 bit 组成，其表示每个工作节点每毫秒生成的序列号 ID，同一毫秒内最多可生成 2^12 -1 即 4095 个 ID。
 * 需要注意的是：
 *
 * 在分布式环境中，5 个 bit 位的 datacenter 和 worker 表示最多能部署 31 个数据中心，每个数据中心最多可部署 31 台节点
 * 41 位的二进制长度最多能表示 2^41 -1 毫秒即 69 年，所以雪花算法最多能正常使用 69 年，为了能最大限度的使用该算法，你应该为其指定一个开始时间。
 * 由上可知，雪花算法生成的 ID 并不能保证唯一，如当两个不同请求同一时刻进入相同的数据中心的相同节点时，而此时该节点生成的 sequence 又是相同时，就会导致生成的 ID 重复。
 *
 * 所以要想使用雪花算法生成唯一的 ID，就需要保证同一节点同一毫秒内生成的序列号是唯一的。基于此，我们在 SDK 中集成了多种序列号提供者：
 *
 * RandomSequenceResolver（随机生成）
 * RedisSequenceResolver （基于 redis psetex 和 incrby 生成）
 * LaravelSequenceResolver（基于 redis psetex 和 incrby 生成）
 * SwooleSequenceResolver（基于 swoole_lock 锁）
 * 不同的提供者只需要保证同一毫秒生成的序列号不同，就能得到唯一的 ID。
 */

require 'bootstrap.php';

$autoloader = Autoloader::getInstance();
$autoloader->addNamespace('Godruoyi\Snowflake', MODULE_DIR . DS . 'src');
$autoloader->register();

////// 简单使用.
//$snowflake = new \Godruoyi\Snowflake\Snowflake;
//$id = $snowflake->id();
//echo $id;


////// 指定数据中心ID及机器ID.
//$dataCenterId = '1010';
//$workerId = '2';  //
//$snowflake = new \Godruoyi\Snowflake\Snowflake($dataCenterId, $workerId);
//$id = $snowflake->id();
//echo $id;


////// 指定开始时间.
$snowflake = new \Godruoyi\Snowflake\Snowflake;
$startDate = '2020-01-01';
$snowflake->setStartTimeStamp(strtotime($startDate) * 1000);
$id = $snowflake->id();
echo $id;