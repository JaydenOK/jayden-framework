<?php
////////////////  static 的 self 区别 : （ 区别对于静态方法而言,self:: 当前代码类方法，static:: 继承类方法【符合对面对象重写静态类方法】）

class Car
{
    public static function model()
    {
        self::getModel();
    }

    protected static function getModel()
    {
        echo "I am a Car!";
    }

}
class Mercedes extends Car
{

    protected static function getModel()
    {
        echo "I am a Mercedes!";
    }
}
Mercedes::model(); ////但实际输出是：I am a Car!

//对于self的解释
//关键字“self”的工作原理是：它会调用当前类（current class）的方法。因为model方法只在class Car中定义的，所以对它来说当前类就是class Car。model中的self::getModel()，调用的自然也就是class Car中的getModel方法。

//这个行为似乎不是我们想要的，它不符合面向对象的设计原则。如何解决呢？可以使用关键字static。

//static关键字和延迟静态绑定（late static binding）
//在PHP5.3中，加入了一个新的特性，叫做延迟静态绑定。它可以帮我们实现多态，解决上面的问题。简单来说，延迟静态绑定意味着，当我们用static关键字调用一个继承方法时，它将在运行时绑定调用类(calling class)。
//在上面的例子中，如果我们使用延迟静态绑定（static），意味当我们调用“Mercedes::model();”时，class Mercedes中的getModel方法将会被调用。因为Mercedes是我们的调用类。
//延迟绑定的例子
//class Car
//{
//    public static function model()
//    {
//        static::getModel();
//    }
//    protected static function getModel()
//    {
//        echo "I am a Car!";
//    }
//}
//Mercedes::model();

//php中的self和static
//现在我们将例子中的self用static替换，可以看到，两者的区别在于:self引用的是当前类(current class)而static允许函数调用在运行时绑定调用类(calling class)。


//////////////  匿名函数使用
use Rakit\Validation\Rules\Interfaces\BeforeValidate;
//$db 为cache 方法通过 call_user_func($callable, $this); 传入的$this对象,$this又是调用者对象，即是new DB()对象[new DB()等于$db]
//使用外部参数可以使用 use ($outParams)
$outParams = 1;
$result = (new DB())->cache(function ($db) use ($outParams) {
    // SQL 查询的结果将从缓存中提供
    // 如果启用查询缓存并且在缓存中找到查询结果
    return $db->createCommand('SELECT * FROM customer WHERE id=1')->queryOne();

});

//$db类：
class DB
{
    public function cache(callable $callable, $duration = null, $dependency = null)
    {
        $this->_queryCacheInfo[] = [$duration === null ? $this->queryCacheDuration : $duration, $dependency];
        try {
            //$this 即调用实例作为回调函数的参数，传入回调函数
            $result = call_user_func($callable, $this);
            array_pop($this->_queryCacheInfo);
            //回调函数返回值，再返回到主调用函数
            return $result;
        } catch (\Exception $e) {
            array_pop($this->_queryCacheInfo);
        } catch (\Throwable $e) {
            array_pop($this->_queryCacheInfo);
        }
    }
}

/////////////////////   interface接口模式使用（切面增加功能）
$attribute = new Rakit\Validation\Attribute();
foreach ($attribute->getRules() as $rule) {
    //$rule为继承了Rule的不同类，但不一定实现了 BeforeValidate
    //BeforeValidate 为接口类，$rule有实现此接口的(instanceof)，执行接口方法
    if ($rule instanceof BeforeValidate) {
        $rule->beforeValidate();
    }
}


/////////////////////////////////////////////////////////////////////////////
/// 面向对象的三大特性：
//1、封装
//隐藏对象的属性和实现细节，仅对外提供公共访问方式，将变化隔离，便于使用，提高复用性和安全性。
//2、继承
//提高代码复用性；继承是多态的前提。
//3、多态
//父类或接口定义的引用变量可以指向子类或具体实现类的实例对象。提高了程序的拓展性。
//
//五大基本原则：
//1、单一职责原则SRP(Single Responsibility Principle)
//类的功能要单一，不能包罗万象，跟杂货铺似的。
//2、开放封闭原则OCP(Open－Close Principle)
//一个模块对于拓展是开放的，对于修改是封闭的，想要增加功能热烈欢迎，想要修改，哼，一万个不乐意。
//3、里式替换原则LSP(the Liskov Substitution Principle LSP)
//子类可以替换父类出现在父类能够出现的任何地方。比如你能代表你爸去你姥姥家干活。哈哈~~
//4、依赖倒置原则DIP(the Dependency Inversion Principle DIP)
//高层次的模块不应该依赖于低层次的模块，他们都应该依赖于抽象。抽象不应该依赖于具体实现，具体实现应该依赖于抽象。就是你出国要说你是中国人，而不能说你是哪个村子的。比如说中国人是抽象的，下面有具体的xx省，xx市，xx县。你要依赖的是抽象的中国人，而不是你是xx村的。
//5、接口分离原则ISP(the Interface Segregation Principle ISP)
//设计时采用多个与特定客户类有关的接口比采用一个通用的接口要好。就比如一个手机拥有打电话，看视频，玩游戏等功能，把这几个功能拆分成不同的接口，比在一个接口里要好的多。
//
//最后
//1、抽象会使复杂的问题更加简单化。
//2、从以前面向过程的执行者，变成了张张嘴的指挥者。
//3、面向对象更符合人类的思维，面向过程则是机器的思想