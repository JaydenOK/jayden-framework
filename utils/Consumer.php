<?php

$config = [
    "host" => "127.0.0.1",
    'vhost' => '/',
    'port' => '5672',
    'login' => 'admin',
    'password' => '123456'
];

try {
    $connection = new AMQPConnection($config);
    # 创建一个broker
    if (!$connection->connect()) {
        throw new Exception("RabbitMQ connect is fail");
    }
    # 在连接内创建一个通道
    $channel = new AMQPChannel($connection);
    # 创建一个交换机
    $exchange = new AMQPExchange($channel);
    # 声明路由键
    $routingKey = 'routing_key_1';
    # 声明交换机名称
    $exchangeName = 'exchange_1';
    # 设置交换机名称
    $exchange->setName($exchangeName);
    # 设置交换机类型:
    #  AMQP_EX_TYPE_DIRECT:直连交换机
    #  AMQP_EX_TYPE_FANOUT:扇形交换机
    #  AMQP_EX_TYPE_HEADERS:头交换机
    #  AMQP_EX_TYPE_TOPIC:主题交换机
    $exchange->setType(AMQP_EX_TYPE_DIRECT);
    # 设置交换池
    $exchange->setFlags(AMQP_DURABLE);
    # 声明交换机
    $exchange->declareExchange();
    # 创建一个消息队列
    $queue = new AMQPQueue($channel);
    # 设置队列名称
    $queue->setName('queue_1');
    # 设置队列持久
    $queue->setFlags(AMQP_DURABLE);
    # 声明消息队列
    $queue->declareQueue();
    # 绑定交换机和队列：通过routingkey绑定
    $queue->bind($exchange->getName(), $routingKey);
    # 接受消息并通过回调处理
    function receive($envelop, $queues)
    {
        # 休眠2秒
        sleep(2);
        echo $envelop->getBody() . "\n";
        # 显示确认，队列收到消费者显式确认后，会删除该消息
        $queues->ack($envelop->getDeliveryTag());
    }

    # 设置消息队列消费者回调方法
    $queue->consume("receive");
} catch (Exception $e) {
    echo $e->getMessage();
    exit();
}