<?php

/**
 * RabbitMQClient PHP原生客户端实现方式工具 (AMQP协议)
 *
 * ####################################### 使用示例 ##############################################
 * $result = [];
 * //生产者
 * $message = ['id' => 11111, 'status' => 1, 'data' => ['user_code' => 'W12334', 'user_name' => '张三']];
 * $rabbitMQClient = new RabbitMQClient();
 * $exchangeName = 'eee';
 * $routeKey = 'rrr';
 * $queueName = 'qqq';
 * //        $result = $rabbitMQClient->sendMessage($message, $exchangeName, $queueName, $routeKey);
 * //        $result = $rabbitMQClient->sendRouteMessage($message, $exchangeName, $routeKey);
 * //        $result = $rabbitMQClient->sendFanOutMessage($message, $exchangeName, $queueName);
 * //        $result = $rabbitMQClient->getOneMessage($exchangeName, $queueName);
 * //        if ($result === false) {
 * //            return $rabbitMQClient->getErrorMessage();
 * //        }
 * //消费者
 * $callback = function ($body) {
 * echo date('[Y-m-d H:i:s]') . print_r($body, true) . PHP_EOL;
 * sleep(1);
 * return true;
 * };
 * try {
 * $rabbitMQClient->consume($callback, $queueName);
 * } catch (Exception $e) {
 *
 * }
 * return $result;
 * ##########################################################################################
 * Class RabbitMQClient
 */
class RabbitMQClient
{
    protected $mqConfig = [];
    /**
     * @var AMQPConnection
     */
    protected $connection;
    /**
     * @var AMQPChannel
     */
    protected $channel;
    /**
     * @var AMQPExchange
     */
    protected $exchange;
    /**
     * @var string 默认交换机
     */
    protected $exchangeName;
    protected $exchangeType = AMQP_EX_TYPE_DIRECT;
    protected $routeKey;
    /**
     * @var AMQPQueue
     */
    protected $queue;
    protected $queueName;
    /**
     * 消费队列，可绑定多个Key
     * @var string
     */
    protected $bindKey;
    /**
     * @var string
     */
    protected $errorMessage;
    /**
     * @var int
     */
    private $messageCount;
    /**
     * @var callable
     */
    private $callback;

    /**
     * RabbitMQClient constructor.
     * @param array $config
     * @throws Exception
     */
    public function __construct($config = [])
    {
        $this->setMqConfig($config);
        $this->connect();
    }

    /**
     * 设置RabbitMQ连接信息，或直接配置
     * @param array $mqConfig
     * @return $this
     */
    public function setMqConfig($mqConfig = [])
    {
        $this->mqConfig = [
            'host' => $mqConfig['host'] ?? '192.168.71.91',
            'vhost' => $mqConfig['vhost'] ?? '/',
            'port' => $mqConfig['port'] ?? '5672',
            'login' => $mqConfig['login'] ?? 'admin',
            'password' => $mqConfig['password'] ?? 'admin123.',
        ];
        return $this;
    }

    /**
     * @throws Exception
     */
    protected function connect()
    {
        try {
            $this->connection = new AMQPConnection($this->mqConfig);
            $this->connection->connect();
            $this->channel = new AMQPChannel($this->connection);
        } catch (AMQPConnectionException $e) {
            throw new Exception('rabbitmq连接失败:' . $e->getMessage());
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
     * 声明交换机
     * @param bool $autoCreate 不存在是否自动创建（持久化）
     * @throws AMQPChannelException
     * @throws AMQPConnectionException
     * @throws AMQPExchangeException
     */
    private function handleExchange($autoCreate = false)
    {
        if (!$this->connection->isConnected()) {
            throw new AMQPConnectionException('RabbitMQ is not connected.');
        }
        $this->exchange = new AMQPExchange($this->channel);
        $this->exchange->setName($this->exchangeName);
        $this->exchange->setType($this->exchangeType);
        $flags = $autoCreate ? AMQP_DURABLE : AMQP_PASSIVE;
        $this->exchange->setFlags($flags);
        $this->exchange->declareExchange();
    }

    /**
     * 声明队列
     * @param bool $autoCreate 不存在是否自动创建（持久化）
     * @return $this
     * @throws AMQPChannelException
     * @throws AMQPConnectionException
     * @throws AMQPQueueException
     */
    private function handleQueue($autoCreate = false)
    {
        if (empty($this->queueName)) {
            return $this;
        }
        $this->queue = new AMQPQueue($this->channel);
        $this->queue->setName($this->queueName);
        $flags = $autoCreate ? AMQP_DURABLE : AMQP_PASSIVE;
        $this->queue->setFlags($flags);
        $this->queue->declareQueue();
        return $this;
    }

    private function queueBind()
    {
        if ($this->queue === null) {
            return $this;
        }
        $this->queue->bind($this->exchangeName, $this->routeKey);
        return $this;
    }

    //生产者 点对点发送指定队列消息
    //生产者发送消息
    public function sendMessage($message, $exchangeName, $queueName = null, $routeKey = null)
    {
        try {
            $this->setExchangeName($exchangeName)
                ->setExchangeType(AMQP_EX_TYPE_DIRECT)
                ->setQueueName($queueName)
                ->setRouteKey($routeKey);
            $this->handleExchange(true);
            $this->handleQueue(true);
            $this->queueBind();
            $message = (is_array($message) || is_object($message)) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message;
            $result = $this->exchange->publish($message, $this->routeKey);
            return $result;
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
            return false;
        }
    }

    //发送消息 direct-route 模式
    public function sendRouteMessage($message, $exchangeName, $routeKey = null)
    {
        return $this->sendMessage($message, $exchangeName, null, $routeKey);
    }

    /**
     * 发送广播消息。忽略路由，绑定此交换机的队列都能接收到此消息
     * 一个交换机只能有一种类型
     * @param $message
     * @param null $exchangeName
     * @param null $queueName 有传队列，则进行持久化，发送可不传
     * @return bool
     */
    public function sendFanOutMessage($message, $exchangeName, $queueName = null)
    {
        try {
            $this->setExchangeName($exchangeName)
                ->setExchangeType(AMQP_EX_TYPE_FANOUT)
                ->setQueueName($queueName);
            $this->handleExchange(true);
            $this->handleQueue(true);
            $this->queueBind();
            $message = (is_array($message) || is_object($message)) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message;
            $result = $this->exchange->publish($message, '', AMQP_MANDATORY, ['delivery_mode' => 2]);
            return $result;
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
            return false;
        }
    }

    /**
     * 消费者消费消息（阻塞模式）
     * @param callable $callback
     * @param $queueName
     * @throws AMQPChannelException
     * @throws AMQPConnectionException
     * @throws AMQPEnvelopeException
     * @throws AMQPQueueException
     */
    public function consume(callable $callback, $queueName)
    {
        $this->setCallback($callback)->setQueueName($queueName);
        $this->handleQueue(true);
        //阻塞消费
        $this->queue->consume(function (AMQPEnvelope $AMQPEnvelope, AMQPQueue $AMQPQueue) {
            if (!is_callable($this->callback)) {
                throw new Exception('not a callback function.');
            }
            $result = call_user_func($this->callback, $AMQPEnvelope->getBody());
            if ($result === true) {
                $AMQPQueue->ack($AMQPEnvelope->getDeliveryTag());
            } else {
                $AMQPQueue->nack($AMQPEnvelope->getDeliveryTag());
            }
        });
    }

    /**
     * 消费者获取消息（非阻塞）
     * @param $exchangeName
     * @param $queueName
     * @param bool $autoAck
     * @return string
     * @throws AMQPChannelException
     * @throws AMQPConnectionException
     * @throws AMQPExchangeException
     * @throws AMQPQueueException
     */
    public function getOneMessage($exchangeName, $queueName, $autoAck = true)
    {
        $this->setExchangeName($exchangeName)->setQueueName($queueName);
        $this->handleExchange(false);
        $this->handleQueue(true);
        $this->queueBind();
        if ($autoAck) {
            $envelope = $this->queue->get(AMQP_AUTOACK);
        } else {
            $envelope = $this->queue->get();
        }
        return $envelope instanceof AMQPEnvelope ? null : $envelope->getBody();
    }

    /**
     * 获取队列消息数量
     * AMQP_DURABLE [队列持久]
     * AMQP_PASSIVE [返回消息计数]
     * AMQP_EXCLUSIVE [只被一个连接（connection）使用，而且当连接关闭后队列即被删除]
     * AMQP_AUTODELETE [当最后一个消费者退订后即被删除]
     * @param $queueName
     * @return int
     */
    public function getMessageCount($queueName)
    {
        try {
            $this->queue = new AMQPQueue($this->channel);
            $this->queue->setName($queueName);
            $this->queue->setFlags(AMQP_PASSIVE);
            $this->messageCount = $this->queue->declareQueue();
        } catch (Exception $e) {
            $this->messageCount = 0;
        }
        return $this->messageCount;
    }

    public function setCallback(callable $callback)
    {
        $this->callback = $callback;
        return $this;
    }

    /**
     * @return string
     */
    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    private function close()
    {
        if ($this->connection) {
            $this->connection->disconnect();
        }
    }

    public function __destruct()
    {
        $this->close();
    }


}
