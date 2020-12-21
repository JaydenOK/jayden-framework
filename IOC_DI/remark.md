PHP控制反转(IOC)和依赖注入(DI)
PHP进阶架构师
PHP进阶架构师
​
湖南杰越云信息科技有限公司 PHP架构师
5 人赞同了该文章
IOC（inversion of control）控制反转模式；控制反转是将组件间的依赖关系从程序内部提到外部来管理；
DI（dependency injection）依赖注入模式；依赖注入是指将组件的依赖通过外部以参数或其他形式注入；
两个说法本质上是一个意思。
例如：

class DbMysql
{
    public function query(){}
}
class Controller
{
    public $db;
    public function __construct()
    {
        $this->db = new DbMysql();
    }
    public function action()
    {
        $this->db->query();
    }
}
$c = new Controller();
$c->action();
Controller类中的action方法需要用到DbMysql类中的query方法，所以Controller类就对DbMysql类产生了依赖，Controller类和DbMysql类之间的耦合度就比较高，因为当DbMysql类的构造函数发生改变的时候，比如由现在的没有参数变成有参数了，参数数量改变了，那么Controller类中的代码都要做出相应改变。

或者说我们现在需要将DbMysql类换成另一个DbOracle类，Controller类中要做出的改变甚至更大



看下面的另一种写法：

class DbMysql
{
    public function query(){}
}
class Controller
{
    public $db;
    public function __construct($dbMysql)
    {
        $this->db = $dbMysql;
    }
    public function action()
    {
        $this->db->query();
    }
}
$db = new DbMysql();
$c = new Controller($db);
$c->action();
Controller类中不需要实例化DbMysql，而是将DbMysql类的实例作为参数传递（或者单独写一个接收实例的方法处理），这样Controller类就完全不用管DbMysql是怎么样实例的，而是仅仅调用DbMysql中的query方法就行了。这种模式就是依赖注入。

第一个例子中Controller类负责实例DbMysql，也就是说Controller类控制着实例DbMysql类的主动权，而第二个例子中将这个主动权提出到Controller类的外面，所以也叫做控制反转。

这样看起来还不错，但是如果我们需要很多类，而且需要自己写的时候弄清楚，每一个类依赖什么类，这样太烦，如果有一个类能够帮我们搞定这个动作那就太爽了。而事实上有这个类，这个类就叫做IOC容器。



下面通过实例与大家分析分析

示例一
class DbMysql
{
    public function __construct($host, $name, $pwd)
    {
        // do something
    }

    public function query()
    {
        echo __METHOD__ . PHP_EOL;
    }
}

class DbRedis
{
    public function __construct($host, $name, $pwd)
    {
        // do something
    }

    public function set()
    {
        echo __METHOD__ . PHP_EOL;
    }
}

class controller
{
    public $mysql;
    public $redis;

    public function __construct()
    {
        $this->mysql = new DbMysql('host', 'name', 'pwd');
        $this->redis = new DbRedis('host', 'name', 'pwd');
    }

    public function action()
    {
        $this->mysql->query();
        $this->redis->set();
    }
}

$c = new Controller();
$c->action();
/**
 * 输出：
 * DbMysql::query
 * DbRedis::set
 */
普通的实现方式，耦合度高。

示例二
class DbMysql
{
    public function __construct($host, $name, $pwd)
    {
        // do something
    }

    public function query()
    {
        echo __METHOD__ . PHP_EOL;
    }
}

class DbRedis
{
    public function __construct($host, $name, $pwd)
    {
        // do something
    }

    public function set()
    {
        echo __METHOD__ . PHP_EOL;
    }
}

class controller
{
    public $mysql;
    public $redis;

    public function __construct($mysql, $redis)
    {
        $this->mysql = $mysql;
        $this->redis = $redis;
    }

    public function action()
    {
        $this->mysql->query();
        $this->redis->set();
    }
}

$mysql = new DbMysql('host', 'name', 'pwd');
$redis = new DbRedis('host', 'name', 'pwd');
$c = new Controller($mysql, $redis);
$c->action();
/**
 * 输出：
 * DbMysql::query
 * DbRedis::set
 */
实现了依赖注入和控制反转，但是没有使用容器类。



示例三
class DbMysql
{
    public function __construct($host, $name, $pwd)
    {
        // do something
    }

    public function query()
    {
        echo __METHOD__ . PHP_EOL;
    }
}

class DbRedis
{
    public function __construct($host, $name, $pwd)
    {
        // do something
    }

    public function set()
    {
        echo __METHOD__ . PHP_EOL;
    }
}

class controller
{
    public $mysql;
    public $redis;

    public function __construct($mysql, $redis)
    {
        $this->mysql = $mysql;
        $this->redis = $redis;
    }

    public function action()
    {
        $this->mysql->query();
        $this->redis->set();
    }
}

class Container
{

    public $bindings = [];

    public function bind($key, Closure $value)
    {
        $this->bindings[$key] = $value;
    }

    public function make($key)
    {
        $new = $this->bindings[$key];
        return $new();
    }

}

$app = new Container();
$app->bind('mysql', function () {
    return new DbMysql('host', 'name', 'pwd');
});
$app->bind('redis', function () {
    return new DbRedis('host', 'name', 'pwd');
});
$app->bind('controller', function () use ($app) {
    return new Controller($app->make('mysql'), $app->make('redis'));
});
$controller = $app->make('controller');
$controller->action();
/**
 * 输出：
 * DbMysql::query
 * DbRedis::set
 */
实现了基本的容器类，容器类中有两个方法，bind和make，一个是绑定操作，一个是实例化操作。将每一个需要使用到的类使用关键字绑定到容器类中去，但是每一个类仍然需要手动去实例化，这里引入了闭包函数，主要作用是在调用的时候才真正去实例化，而如果仅仅是绑定了一个类，是不会实例化这个类的。



示例四
class T
{
    public $t;
}

class X
{
    public $x;

    private function __construct()
    {
    }
}

class Y
{
    public $x;

    public function __construct()
    {
    }
}

interface Is
{
}

class Sis implements Is
{

}

class S
{
    public $s;

    public function __construct(string $s, int $i, array $a, Is $object)
    {
        $this->s = $s;
    }
}

function reflectionClass($className, array $inParams = [])
{
    $reflection = new ReflectionClass($className);
    // isInstantiable() 方法判断类是否可以实例化
    $isInstantiable = $reflection->isInstantiable();
    if ($isInstantiable) {
        // getConstructor() 方法获取类的构造函数，为NULL没有构造函数
        $constructor = $reflection->getConstructor();
        if (is_null($constructor)) {
            // 没有构造函数直接实例化对象返回
            return new $className;
        } else {
            // 有构造函数
            $params = $constructor->getParameters();
            if (empty($params)) {
                // 构造函数没有参数，直接实例化对象返回
                return new $className;
            } else {
                // 构造函数有参数，将$inParams传入实例化对象返回
                return $reflection->newInstanceArgs($inParams);
            }
        }
    }
    return null;
}

$t = reflectionClass('T');
var_dump($t instanceof T);
$x = reflectionClass('X');
var_dump($x instanceof X);
$x = reflectionClass('Y');
var_dump($x instanceof Y);
$s = reflectionClass('S', ['asdf', 123, [1, 2], (new Sis)]);
var_dump($s instanceof S);
/**
 * 输出：
 * bool(true)
 * bool(false)
 * bool(true)
 * bool(true)
 */
引入反射类，他的作用是可以实例化一个类，和new操作一样。但是实例化一个类所需要的参数，他都能自动检测出来。并且能够检测出来这个参数是不是一个继承了接口的类。上面说一个类依赖另一个类，然后将另一个类作为参数注入，这个反射能够检测出一个类实例化的时候需要什么样的类，好像有点眉目了是吧。



示例五
class DbMysql
{
    public function __construct($host, $name, $pwd)
    {
        // do something
    }

    public function query()
    {
        echo __METHOD__ . PHP_EOL;
    }
}

class DbRedis
{
    public function __construct($host, $name, $pwd)
    {
        // do something
    }

    public function set()
    {
        echo __METHOD__ . PHP_EOL;
    }
}

class controller
{
    public $mysql;
    public $redis;

    public function __construct($mysql, $redis)
    {
        var_dump($mysql);var_dump($redis);
        $this->mysql = $mysql;
        $this->redis = $redis;
    }

    public function action()
    {
        is_object($this->mysql) && $this->mysql->query();
        is_object($this->redis) && $this->redis->set();
    }
}

class Container
{

    public $bindings = [];

    public function bind($key, $value)
    {
        if (!$value instanceof Closure) {
            $this->bindings[$key] = $this->getClosure($value);
        }
        else{
            $this->bindings[$key] = $value;
        }
    }

    public function getClosure($value)
    {
        return function () use ($value) {
            return $this->build($value);
        };
    }

    public function make($key)
    {
        if (isset($this->bindings[$key])) {
            return $this->build($this->bindings[$key]);
        }
        return $this->build($key);
    }

    public function build($value)
    {
        if ($value instanceof Closure) {
            return $value();
        }
        // 实例化反射类
        $reflection = new ReflectionClass($value);
        // isInstantiable() 方法判断类是否可以实例化
        $isInstantiable = $reflection->isInstantiable();
        if ($isInstantiable) {
            // getConstructor() 方法获取类的构造函数，为NULL没有构造函数
            $constructor = $reflection->getConstructor();
            if (is_null($constructor)) {
                // 没有构造函数直接实例化对象返回
                return new $value;
            } else {
                // 有构造函数
                $params = $constructor->getParameters();
                if (empty($params)) {
                    // 构造函数没有参数，直接实例化对象返回
                    return new $value;
                } else {
                    $dependencies = [];
                    // 构造函数有参数
                    foreach ($params as $param) {
                        $dependency = $param->getClass();
                        if (is_null($dependency)) {
                            // 构造函数参数不为class，返回NULL
                            $dependencies[] = NULL;
                        } else {
                            // 类存在创建类实例
                            $dependencies[] = $this->make($param->getClass()->name);
                        }
                    }
                    return $reflection->newInstanceArgs($dependencies);
                }
            }
        }
        return null;
    }

}

$app = new Container();
$app->bind('mysql', function () {
    return new DbMysql('host', 'name', 'pwd');
});
$app->bind('redis', function () {
    return new DbRedis('host', 'name', 'pwd');
});
$app->bind('controller', 'controller');
$controller = $app->make('controller');
$controller->action();
/**
 * 输出：
 * NULL
 * NULL
 */
容器类中引入反射，容器类bind方法升级，不仅仅支持闭包绑定，而且支持类名绑定。示例三中的bind方法仅仅支持绑定一个关键字为闭包，而类的实例操作，需要写到闭包函数中去。现在有了反射，可以直接使用类名，反射会根据类名自动去实例化这个类。

但是这个例子中输出两个NULL，Controller类的两个参数均为NULL，反射类并没有自动去找到Controller依赖的DbMysql和DbRedis去实例化。这是为什么呢？现在就需要引入另一个东西，针对接口编程。

这个例子中我们知道Controller类的两个参数是类，但是我们定义的构造函数中并没有声明，现在这种定义方式的两个参数，是类，是字符串，是整型，完全没区别的，反射类无法检测出这两个参数是类，我们的反射方法里面如果检测到构造函数的参数不是类直接返回NULL，所以这里输出了两个NULL。



示例六
interface SMysql
{
    public function query();
}

class DbMysql implements SMysql
{
    public function __construct($host, $name, $pwd)
    {
        // do something
    }

    public function query()
    {
        echo __METHOD__ . PHP_EOL;
    }
}

interface SRedis
{
    public function set();
}

class DbRedis implements SRedis
{
    public function __construct($host, $name, $pwd)
    {
        // do something
    }

    public function set()
    {
        echo __METHOD__ . PHP_EOL;
    }
}

class controller
{
    public $mysql;
    public $redis;

    public function __construct(SMysql $mysql, SRedis $redis)
    {
        $this->mysql = $mysql;
        $this->redis = $redis;
    }

    public function action()
    {
        is_object($this->mysql) && $this->mysql->query();
        is_object($this->redis) && $this->redis->set();
    }
}

class Container
{

    public $bindings = [];

    public function bind($key, $value)
    {
        if (!$value instanceof Closure) {
            $this->bindings[$key] = $this->getClosure($value);
        } else {
            $this->bindings[$key] = $value;
        }
    }

    public function getClosure($value)
    {
        return function () use ($value) {
            return $this->build($value);
        };
    }

    public function make($key)
    {
        if (isset($this->bindings[$key])) {
            return $this->build($this->bindings[$key]);
        }
        return $this->build($key);
    }

    public function build($value)
    {
        if ($value instanceof Closure) {
            return $value();
        }
        // 实例化反射类
        $reflection = new ReflectionClass($value);
        // isInstantiable() 方法判断类是否可以实例化
        $isInstantiable = $reflection->isInstantiable();
        if ($isInstantiable) {
            // getConstructor() 方法获取类的构造函数，为NULL没有构造函数
            $constructor = $reflection->getConstructor();
            if (is_null($constructor)) {
                // 没有构造函数直接实例化对象返回
                return new $value;
            } else {
                // 有构造函数
                $params = $constructor->getParameters();
                if (empty($params)) {
                    // 构造函数没有参数，直接实例化对象返回
                    return new $value;
                } else {
                    $dependencies = [];
                    // 构造函数有参数
                    foreach ($params as $param) {
                        $dependency = $param->getClass();
                        if (is_null($dependency)) {
                            // 构造函数参数不为class，返回NULL
                            $dependencies[] = NULL;
                        } else {
                            // 类存在创建类实例
                            $dependencies[] = $this->make($param->getClass()->name);
                        }
                    }
                    return $reflection->newInstanceArgs($dependencies);
                }
            }
        }
        return null;
    }

}

$app = new Container();
$app->bind('SMysql', function () {
    return new DbMysql('host', 'name', 'pwd');
});
$app->bind('SRedis', function () {
    return new DbRedis('host', 'name', 'pwd');
});
$app->bind('controller', 'controller');
$controller = $app->make('controller');
$controller->action();
/**
 * 输出：
 * DbMysql::query
 * DbRedis::set
 */
使用接口了以后脚本执行正常。

首先容器绑定了接口SMysql和SRedis分别对应的闭包，绑定的关键字为接口名称，也就是说在容器中多次绑定一个接口只会绑定一个闭包一个类的实现，controller类的构造函数中声明了需要两个分别继承自SMysql接口和SRedis接口的类。容器中已经有了这两个接口的实现方式，所以直接调用闭包函数实例化类，然后将结果提供给controller类进行实例化。

本例中，我们仅仅make了controller类，也就是说我们只需要实例化controller类，而controller类依赖的DbMysql类和DbRedis类，IOC容器会自动帮我们实例化并注入。

本例中controller类的实例化很简单：

$app->bind('controller', 'controller');
$controller = $app->make('controller');
但是DbMysql和DbRedis就比较丑了：

$app->bind('SMysql', function () {
    return new DbMysql('host', 'name', 'pwd');
});
$app->bind('SRedis', function () {
    return new DbRedis('host', 'name', 'pwd');
});
其实是这两个类写的有问题，因为这两个类不是面对接口编程。但是这种自定义闭包函数的绑定非常方便，完全满足任何类的自由实例化。我们要做是依赖注入，那就全部使用接口来实现，看下一个例子。



示例七
interface MConfig
{
    public function getConfig();
}

class MysqlConfig implements MConfig
{
    public function getConfig()
    {
        // 获取配置
        return ['host', 'name', 'pwd'];
    }
}

interface RConfig
{
    public function getConfig();
}

class RedisConfig implements RConfig
{
    public function getConfig()
    {
        // 获取配置
        return ['host', 'name', 'pwd'];
    }
}

interface SMysql
{
    public function query();
}

class DbMysql implements SMysql
{
    public $config;

    public function __construct(MConfig $config)
    {
        $this->config = $config->getConfig();
        // do something
    }

    public function query()
    {
        echo __METHOD__ . PHP_EOL;
    }
}

interface SRedis
{
    public function Set();
}

class DbRedis implements SRedis
{
    public function __construct(RConfig $config)
    {
        $this->config = $config->getConfig();
        // do something
    }

    public function set()
    {
        echo __METHOD__ . PHP_EOL;
    }
}

class Controller
{
    public $mysql;
    public $redis;

    public function __construct(SMysql $mysql, SRedis $redis)
    {
        $this->mysql = $mysql;
        $this->redis = $redis;
    }

    public function action()
    {
        is_object($this->mysql) && $this->mysql->query();
        is_object($this->redis) && $this->redis->set();
    }
}

class Container
{

    public $bindings = [];

    public function bind($key, $value)
    {
        if (!$value instanceof Closure) {
            $this->bindings[$key] = $this->getClosure($value);
        } else {
            $this->bindings[$key] = $value;
        }
    }

    public function getClosure($value)
    {
        return function () use ($value) {
            return $this->build($value);
        };
    }

    public function make($key)
    {
        if (isset($this->bindings[$key])) {
            return $this->build($this->bindings[$key]);
        }
        return $this->build($key);
    }

    public function build($value)
    {
        if ($value instanceof Closure) {
            return $value();
        }
        // 实例化反射类
        $reflection = new ReflectionClass($value);
        // isInstantiable() 方法判断类是否可以实例化
        $isInstantiable = $reflection->isInstantiable();
        if ($isInstantiable) {
            // getConstructor() 方法获取类的构造函数，为NULL没有构造函数
            $constructor = $reflection->getConstructor();
            if (is_null($constructor)) {
                // 没有构造函数直接实例化对象返回
                return new $value;
            } else {
                // 有构造函数
                $params = $constructor->getParameters();
                if (empty($params)) {
                    // 构造函数没有参数，直接实例化对象返回
                    return new $value;
                } else {
                    $dependencies = [];
                    // 构造函数有参数
                    foreach ($params as $param) {
                        $dependency = $param->getClass();
                        if (is_null($dependency)) {
                            // 构造函数参数不为class，返回NULL
                            $dependencies[] = NULL;
                        } else {
                            // 类存在创建类实例
                            $dependencies[] = $this->make($param->getClass()->name);
                        }
                    }
                    return $reflection->newInstanceArgs($dependencies);
                }
            }
        }
        return null;
    }

}

$app = new Container();
$app->bind('MConfig', 'MysqlConfig');
$app->bind('RConfig', 'RedisConfig');
$app->bind('SMysql', 'DbMysql');
$app->bind('SRedis', 'DbRedis');
$app->bind('controller', 'Controller');
$controller = $app->make('controller');
$controller->action();
/**
 * 输出：
 * DbMysql::query
 * DbRedis::set
 */