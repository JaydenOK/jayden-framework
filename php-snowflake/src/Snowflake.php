<?php

/*
 * This file is part of the godruoyi/php-snowflake.
 *
 * (c) Godruoyi <g@godruoyi.com>
 *
 * This source file is subject to the MIT license that is bundled.
 */

/**
 * 雪花算法的 PHP 实现
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

namespace Godruoyi\Snowflake;

class Snowflake
{
    const MAX_TIMESTAMP_LENGTH = 41;

    const MAX_DATACENTER_LENGTH = 5;

    const MAX_WORKID_LENGTH = 5;

    const MAX_SEQUENCE_LENGTH = 12;

    const MAX_FIRST_LENGTH = 1;

    /**
     * The data center id.
     *
     * @var int
     */
    protected $datacenter;

    /**
     * The worker id.
     *
     * @var int
     */
    protected $workerid;

    /**
     * The Sequence Resolver instance.
     *
     * @var \Godruoyi\Snowflake\SequenceResolver|null
     */
    protected $sequence;

    /**
     * The start timestamp.
     *
     * @var int
     */
    protected $startTime;

    /**
     * Default sequence resolver.
     *
     * @var \Godruoyi\Snowflake\SequenceResolver|null
     */
    protected $defaultSequenceResolver;

    /**
     * Build Snowflake Instance.
     *
     * @param int $datacenter
     * @param int $workerid
     */
    public function __construct(int $datacenter = null, int $workerid = null)
    {
        $maxDataCenter = -1 ^ (-1 << self::MAX_DATACENTER_LENGTH);
        $maxWorkId = -1 ^ (-1 << self::MAX_WORKID_LENGTH);

        // If not set datacenter or workid, we will set a default value to use.
        $this->datacenter = $datacenter > $maxDataCenter || $datacenter < 0 ? mt_rand(0, 31) : $datacenter;
        $this->workerid = $workerid > $maxWorkId || $workerid < 0 ? mt_rand(0, 31) : $workerid;
    }

    /**
     * Get snowflake id.
     *
     * @return string
     */
    public function id()
    {
        $currentTime = $this->getCurrentMicrotime();
        while (($sequence = $this->callResolver($currentTime)) > (-1 ^ (-1 << self::MAX_SEQUENCE_LENGTH))) {
            usleep(1);
            $currentTime = $this->getCurrentMicrotime();
        }

        $workerLeftMoveLength = self::MAX_SEQUENCE_LENGTH;
        $datacenterLeftMoveLength = self::MAX_WORKID_LENGTH + $workerLeftMoveLength;
        $timestampLeftMoveLength = self::MAX_DATACENTER_LENGTH + $datacenterLeftMoveLength;

        return (string)((($currentTime - $this->getStartTimeStamp()) << $timestampLeftMoveLength)
            | ($this->datacenter << $datacenterLeftMoveLength)
            | ($this->workerid << $workerLeftMoveLength)
            | ($sequence));
    }

    /**
     * Parse snowflake id.
     */
    public function parseId(string $id, $transform = false): array
    {
        $id = decbin($id);

        $data = [
            'timestamp' => substr($id, 0, -22),
            'sequence' => substr($id, -12),
            'workerid' => substr($id, -17, 5),
            'datacenter' => substr($id, -22, 5),
        ];

        return $transform ? array_map(function ($value) {
            return bindec($value);
        }, $data) : $data;
    }

    /**
     * Get current microtime timestamp.
     *
     * @return int
     */
    public function getCurrentMicrotime()
    {
        return floor(microtime(true) * 1000) | 0;
    }

    /**
     * Set start time (millisecond).
     */
    public function setStartTimeStamp(int $startTime)
    {
        $missTime = $this->getCurrentMicrotime() - $startTime;

        if ($missTime < 0) {
            throw new \Exception('The start time cannot be greater than the current time');
        } elseif ($missTime > ($maxTimeDiff = ((1 << self::MAX_TIMESTAMP_LENGTH) - 1))) {
            throw new \Exception(sprintf('The maximum time length is 2^%d, You can reset the start time to fix this', self::MAX_TIMESTAMP_LENGTH));
        }

        $this->startTime = $startTime;

        return $this;
    }

    /**
     * Get start timestamp (millisecond), If not set default to 2019-08-08 08:08:08.
     *
     * @return int
     */
    public function getStartTimeStamp()
    {
        if ($this->startTime > 0) {
            return $this->startTime;
        }

        // We set a default start time if you not set.
        $defaultTime = '2019-08-08 08:08:08';

        return strtotime($defaultTime) * 1000;
    }

    /**
     * Set Sequence Resolver.
     *
     * @param SequenceResolver|callable $sequence
     */
    public function setSequenceResolver($sequence)
    {
        $this->sequence = $sequence;

        return $this;
    }

    /**
     * Get Sequence Resolver.
     *
     * @return \Godruoyi\Snowflake\SequenceResolver|callable|null
     */
    public function getSequenceResolver()
    {
        return $this->sequence;
    }

    /**
     * Get Default Sequence Resolver.
     *
     * @return \Godruoyi\Snowflake\SequenceResolver
     */
    public function getDefaultSequenceResolver(): SequenceResolver
    {
        return $this->defaultSequenceResolver ?: $this->defaultSequenceResolver = new RandomSequenceResolver();
    }

    /**
     * Call resolver.
     *
     * @param callable|\Godruoyi\Snowflake\SequenceResolver $resolver
     * @param int $maxSequence
     *
     * @return int
     */
    protected function callResolver($currentTime)
    {
        $resolver = $this->getSequenceResolver();

        if (is_callable($resolver)) {
            return $resolver($currentTime);
        }

        return is_null($resolver) || !($resolver instanceof SequenceResolver)
            ? $this->getDefaultSequenceResolver()->sequence($currentTime)
            : $resolver->sequence($currentTime);
    }
}
