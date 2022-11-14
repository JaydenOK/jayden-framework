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
    # 声明路由键须和消费端保持一致
    $routingKey = 'routing_key_1';
    # 声明交换机名称和消费端保持一致
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
    # 创建10条消息
    for ($i = 0; $i < 10; $i++) {
        $msgContent = [
            'data' => "message_" . $i
        ];
        # 发送消息到交换机，并返回发送结果
        echo "Send message:" . $exchange->publish(json_encode($msgContent), $routingKey, AMQP_NOPARAM, ["delivery_mode" => 2]) . "\n";
    }
    # 代码执行完毕程序会自动退出
} catch (\Exception $e) {
    echo $e->getMessage();
    exit();
}