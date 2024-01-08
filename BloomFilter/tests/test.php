<?php
/**
 *  php+redis 布隆过滤器实现
 *
 * 布隆过滤器认为不在的，一定不会在集合中；布隆过滤器认为在的，不一定存在集合中。
 *
 */

//什么是布隆过滤器：
//布隆过滤器是一种空间效率很高的随机数据结构，专门用来检测集合中是否存在特定的元素。布隆过滤器由一个长度为m比特的位数组与k个独立的哈希函数组成的数据结构。位数组初始化均为0，所有的哈希函数都可以分别把输入数据尽量均匀地散列。当要向布隆过滤器中插入一个元素时，该元素经过k个哈希函数计算产生k个哈希值，以哈希值作为位数组中的下标，将所有k个对应的比特值由0置为1。当要查询一个元素时，同样将其经过哈希函数计算产生哈希值，然后检查对应的k个比特值：如果有任意一个比特为0，表明该元素一定不在集合中；如果所有比特均为1，表明该元素有可能性在集合中。
//由于可能出现哈希碰撞，不同元素计算的哈希值有可能一样，导致一个不存在的元素有可能对应的比特位为1，这就是所谓“假阳性”（false positive）。相对地，“假阴性”（false negative）在BF中是绝不会出现的。因此，Bloom Filter不适合那些“零错误”的应用场合。而在能容忍低错误率的应用场合下，Bloom Filter通过极少的错误换取了存储空间的极大节省。
//所以，布隆过滤器认为不在的，一定不会在集合中；布隆过滤器认为在的，不一定存在集合中。

//布隆过滤器优缺点
//（1）优点：
//节省空间：不需要存储数据本身，只需要存储数据对应hash比特位
//时间复杂度低：插入和查找的时间复杂度都为O(k)，k为哈希函数的个数
//（2）缺点：
//存在假阳性：布隆过滤器判断存在，但可能出现元素实际上不在集合中的情况；误判率取决于哈希函数的个数，对于哈希函数的个数选择，我们第4部分会讲
//不支持删除元素：如果一个元素被删除，但是却不能从布隆过滤器中删除，这也是存在假阳性的原因之一


//1，插件安装，直接命令执行：https://github.com/php-redis/bloom-filter
//2，php代码实现


require '../bootstrap.php';

$redis = new Redis();
$redis->connect('127.0.0.1');

$digests = [ // you can select several or all of them
    new BKDRDigest(),
    new DEKDigest(),
    new DJBDigest(),
    new ELFDigest(),
    new FNVDigest(),
    new JSDigest(),
    new PJWDigest(),
    new SDBMDigest(),
];

$filter = new Filter(new ModBucket(24), $redis, ...$digests);

$filter->add('fatrbaby');

if ($filter->exists('fatrbaby')) {

}