<?php
//////////////回调函数使用
use Rakit\Validation\Rules\Interfaces\BeforeValidate;

$result = $db->cache(function ($db) {
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
            throw $e;
        } catch (\Throwable $e) {
            array_pop($this->_queryCacheInfo);
            throw $e;
        }
    }
}

/////////////////////接口使用
foreach ($attribute->getRules() as $rule) {
    //$rule为继承了Rule的不同类，但不一定实现了 BeforeValidate
    //BeforeValidate 为接口类，$rule有实现此接口的(instanceof)，执行接口方法
    if ($rule instanceof BeforeValidate) {
        $rule->beforeValidate();
    }
}