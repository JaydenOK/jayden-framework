<?php

/**
 * RabbitMq PHP原生实现方式工具 (AMQP协议)
 * Class RabbitMq
 */
class RabbitMq
{
    protected static $instance;
    protected static $connection;
    private $channel;
    /**
     * @var AMQPExchange
     */
    private $exchange;
    /**
     * @var string 默认交换机
     */
    private $exchangeName = 'default_exchange';
    private $exchangeType = AMQP_EX_TYPE_DIRECT;
    private $flags = AMQP_DURABLE;
    private $routeKey = 'default_route';
    /**
     * @var AMQPQueue
     */
    private $queue;
    private $queueName;
    /**
     * 消费队列，可绑定多个Key
     * @var string
     */
    private $bindKey = 'default_key';

    /**
     * @return RabbitMq
     */
    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self(['host' => MQ_HOST, 'port' => MQ_PORT, 'login' => MQ_LOGIN, 'password' => MQ_PASSWORD]);
        }
        return self::$instance;
    }

    /**
     * RabbitMq constructor.
     * @param $config array
     */
    private function __construct($config)
    {
        //创建连接和channel
        self::$connection = new AMQPConnection($config);
        try {
            self::$connection->connect();
        } catch (AMQPConnectionException $e) {
            echo 'rabbit连接失败:' . $e->getMessage();
            exit;
        }
        try {
            $this->channel = new AMQPChannel(self::$connection);
        } catch (AMQPConnectionException $e) {
            echo 'channel连接失败:' . $e->getMessage();
            exit;
        }
    }

    /**
     * @param $exchangeName
     * @return $this
     */
    public function setExchangeName($exchangeName)
    {
        $this->exchangeName = $exchangeName;
        return $this;
    }

    /**
     * @param $exchangeType
     * @return $this
     */
    public function setExchangeType($exchangeType)
    {
        $this->exchangeType = $exchangeType;
        return $this;
    }

    /**
     * @param $queueName
     * @return $this
     */
    public function setQueueName($queueName)
    {
        $this->queueName = $queueName;
        return $this;
    }

    /**
     * @param $bindKey
     * @return $this
     */
    public function setBindKey($bindKey)
    {
        $this->bindKey = $bindKey;
        return $this;
    }

    /**
     * @param $routeKey
     * @return $this
     */
    public function setRouteKey($routeKey)
    {
        $this->routeKey = $routeKey;
        return $this;
    }

    /**
     * @return bool
     */
    private function init()
    {
        try {
            if (!self::$connection->isConnected()) {
                throw new AMQPConnectionException('RabbitMQ connect fail !');
            }
            $this->exchange = new AMQPExchange($this->channel);
            $this->exchange->setName($this->exchangeName);
            $this->exchange->setType($this->exchangeType);
            $this->exchange->setFlags($this->flags);
            $this->exchange->declareExchange();
        } catch (\Exception $e) {
            die(__CLASS__ . '->' . __METHOD__ . ':' . $e->getMessage());
        }
        return true;
    }

    /**
     * @param $queueName
     * @return $this
     */
    protected function setQueue($queueName)
    {
        try {
            $this->queue = new AMQPQueue($this->channel);
            $this->queue->setName($queueName);
            $this->queue->setFlags($this->flags);
            $this->queue->declareQueue();
        } catch (\Exception $e) {
            die(__CLASS__ . '->' . __METHOD__ . ':' . $e->getMessage());
        }
        return $this;
    }

    /**
     * @param $bindKey
     * @return $this
     */
    public function bind($bindKey)
    {
        try {
            $this->queue->bind($this->exchangeName, $bindKey);
        } catch (\Exception $e) {
            die(__CLASS__ . '->' . __METHOD__ . ':' . $e->getMessage());
        }
        return $this;
    }

    /**
     * @param $bindKey
     * @return $this
     */
    public function unbind($bindKey)
    {
        try {
            $this->queue->unbind($this->exchangeName, $bindKey);
        } catch (\Exception $e) {
            die(__CLASS__ . '->' . __METHOD__ . ':' . $e->getMessage());
        }
        return $this;
    }

    /**
     * @param $message
     * @param string $routeKey
     * @return bool
     */
    public function publish($message, $routeKey = '')
    {
        try {
            !empty($routeKey) && $this->setRouteKey($routeKey);
            $this->init();
            $message = is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE);
            $result = $this->exchange->publish($message, $this->routeKey);
            if (!$result) {
                //发送失败，重试
                sleep(1);
                $result = $this->exchange->publish($message, $this->routeKey);
            }
            return $result;
        } catch (Exception $e) {
            die('发送消息失败:[1]' . $e->getMessage());
        }
    }

    /**
     * @param callable $callback
     * @param string $queueName
     * @param string $bindKey
     */
    public function consume(callable $callback, $queueName = '', $bindKey = '')
    {
        try {
            !empty($queueName) && $this->setQueueName($queueName);
            !empty($bindKey) && $this->setBindKey($bindKey);
            $this->init();
            $this->setQueue($this->queueName)->bind($this->bindKey);
            while (true) {
                $this->queue->consume(function (AMQPEnvelope $envelope, AMQPQueue $queue) use ($callback) {
                    $result = call_user_func($callback, $envelope->getBody());
                    if ($result === true) {
                        $queue->ack($envelope->getDeliveryTag());
                    } else {
                        $queue->nack($envelope->getDeliveryTag());
                    }
                    usleep(300);
                });
                sleep(1);
            }
        } catch (Exception $e) {
            die('接收消息异常:[1]' . $e->getMessage());
        }
    }

    private function close()
    {
        if (self::$connection) {
            self::$connection->disconnect();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}