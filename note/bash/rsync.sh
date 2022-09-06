#!/bin/bash

#获取昨天日期
dt=`date --date='yesterday'  "+%Y-%m-%d"`

*/3 0-8 * * * yesterday=`date --date='yesterday' "+%Y-%m-%d"`; curl -s "http://a.com/console/FbaManageTask/generateTransferFbaSuggest?date=${yesterday}" >> /home/wwwlogs/generateTransferFbaSuggest.log