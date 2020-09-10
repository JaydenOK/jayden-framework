#!/usr/bin/env bash
############################################## mysql 相关笔记   ####################################
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
    create_day

#  按日、周、月模板如下：
select DATE_FORMAT(create_time,'%Y%u') weeks,count(caseid) count from tc_case group by weeks;
select DATE_FORMAT(create_time,'%Y%m%d') days,count(caseid) count from tc_case group by days;
select DATE_FORMAT(create_time,'%Y%m') months,count(caseid) count from tc_case group by months;

###############
