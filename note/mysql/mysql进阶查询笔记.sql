##################################################################################################
#############################################  mysql 进阶笔记   ###################################
##################################################################################################
# MySQL select from join on where group by having order by limit 执行顺序
# 书写顺序：select [查询列表] from [表] [连接类型] join [表2] on [连接条件] where [筛选条件] group by [分组列表] having [分组后的筛选条件] order by [排序列表] limit [偏移, 条目数]
# 执行顺序：from [表] [连接类型] join [表2] on [连接条件] where [筛选条件] group by [分组列表] having [分组后的筛选条件] order by [排序列表] limit [偏移, 条目数] select [查询列表]


# 项目要求做一个访问统计的图表，要求统计粒度有日，周，月三个挡位
# 按日统计
SELECT
    count(*) AS cnt,
    create_day
FROM
    tb_pageview
WHERE
    create_day >= '2017-07-01'
AND create_day <= '2018-07-31'
GROUP BY
    create_day;

#  按日、周、月模板如下：
select DATE_FORMAT(create_time,'%Y%u') weeks,count(caseid) count from tc_case group by weeks;
select DATE_FORMAT(create_time,'%Y%m%d') days,count(caseid) count from tc_case group by days;
select DATE_FORMAT(create_time,'%Y%m') months,count(caseid) count from tc_case group by months;

########################
CREATE TABLE user (
  uid int(11) unsigned NOT NULL AUTO_INCREMENT,
  username varchar(20) NOT NULL DEFAULT '' COMMENT '用户名',
  createtime timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (uid)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='用户表';

CREATE TABLE user_login (
  id int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'id',
  uid int(11) NOT NULL DEFAULT '0' COMMENT '登录用户UID',
  logintime timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '登录时间',
  PRIMARY KEY (id)
) ENGINE=InnoDB AUTO_INCREMENT=8223 DEFAULT CHARSET=utf8 COMMENT='登录日志表';

#  连续留存率sql语句（示例以2020-09-01日注册用户为统计，7日的留存率,sql查出数据，留存率=total/9月一号注册用户）
select logindate, gap, count(*) as total from
	(
		select uid, username
		from user
		where createtime BETWEEN '2020-09-01' and '2020-09-01 23:59:59'
	) t1
	inner join  ### inner join (uid) 去除右表未登录过的用户
	(
		select uid, DATE_FORMAT(logintime,'%Y-%m-%d') as  logindate, DATEDIFF(logintime,'2020-09-01') as gap
		from user_login
		where logintime BETWEEN '2020-09-02' and '2020-09-08 23:59:59' group by uid, logindate
	) t2
on t1.uid=t2.uid  ## left join 筛选 t2 观察对象数据
group by logindate, gap
order by logindate ASC;


####### 查询 9.1号注册、9.2号有登录过的用户信息 (限制左表，where)
select u.* from user as u left join  user_login as l on u.uid=l.uid
where u.createtime BETWEEN '2020-09-01' and '2020-09-01 23:59:59'
and l.logintime BETWEEN '2020-09-02' and '2020-09-02 23:59:59'
group by u.uid;

####### 查询 9.1号注册的9.2号没登录过的用户信息
select u.* from user as u left join  user_login as l
                                     on u.uid=l.uid and l.logintime BETWEEN '2020-09-02' and '2020-09-02 23:59:59'  # 限制右表只要2号的登录用户
where u.createtime BETWEEN '2020-09-01' and '2020-09-01 23:59:59'   # 限制左表只要1号的注册用户
  and l.uid is null  # 限制左表, 右表uid为null才要
group by u.uid;

# 在left join语句中，左表过滤必须放where条件中，右表过滤必须放on条件中
# 从这个伪代码中，我们可以看出两点：
# 1、右表限制用ON
# 如果想对右表进行限制，则一定要在on条件中进行，若在where中进行则可能导致数据缺失，导致左表在右表中无匹配行的行在最终结果中不出现
# 违背了我们对left join的理解。因为对左表无右表匹配行的行而言，遍历右表后b=FALSE,所以会尝试用NULL补齐右表，但是此时我们的P2对右表行进行了限制，NULL若不满足P2(NULL一般都不会满足限制条件，除非IS NULL这种)，则不会加入最终的结果中，导致结果缺失。
# 2、左表限制用WHERE
# 如果没有where条件，无论on条件对左表进行怎样的限制，左表的每一行都至少会有一行的合成结果
# 对左表行而言，若右表若没有对应的行，则右表遍历结束后b=FALSE，会用一行NULL来生成数据，而这个数据是多余的。所以对左表进行过滤必须用where。


########################

######## sql日常优化总结
使用select 语句时，应该指出列名，不应该使用 * 代替所有的列名，查询哪个列就指定哪个列
避免不必要的排序，例如union,order by 等
不能随便在代码里面定义事务，应该按照业务要求使用事务，要保持事务简短，避免大事务，尽量把锁竞争大的表放在事务最后面
避免在where子句做隐式转换，（可能导致索引失效 int,string混淆）
应将sql语句中的数据库函数，计算表达式等放置在等号的右边，避免在列上做函数处理，尽量把计算放在业务层 （left(create_time,10)='2022-02-01' 使用大于、小于即可）
多表关联，尽量选择数据量少的数据来做驱动表
对于连续的数值，尽量使用 between 不用 in
对于长字符串建立索引，尽量为索引指定前缀长度，例如message字段 index(message(20))
创建的组合索引尽量控制字段不超过5个
避免在where子句上对索引列使用LIKE ’%xxx%’,’%xxx’
查询的结果集较大的时候，尽量用limit 分页，每一页控制数据量不要超过5000

避免使用delete删除全表的操作，应该用truncate
多表连接的时候，尽量用表的别名来引用列，尽量不要超过4个多表连接
使用insert 的时候，指定插入的字段名字，按照表的结构顺序插入