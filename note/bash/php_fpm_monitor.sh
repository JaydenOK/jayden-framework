#!/bin/bash

fpmNumLimit=10
curlNumLimit=3
dingTalkToken="xxx"
#dingTalkToken="3e02e598ca54cdf351cb3c2ba01d0277d1f1e0a3286ed744bebb3ad3e932932a"   #test env
requestUrl="https://center.yibainetwork.com/shop/Index/index"

isRequestException=1
requestExceptionNum=0
isException="N"

# check php-fpm
fpmNum=`ps aux|grep php|grep -v defunct|grep -v grep|wc -l`
# check curl request
for (( i = 0; i < $curlNumLimit; i++ )); do
    httpCode=`curl -I  -m  10  -o  /dev/null  -s  -w  %{http_code} ${requestUrl}`
    if [[ $httpCode == 200 ]]; then
        isRequestException=0
        break
    else
        requestExceptionNum=$((requestExceptionNum+1))
        sleep 2
    fi
done

if [[ $fpmNum < $fpmNumLimit || $isRequestException == 1 ]];then
    isException="Y"
fi

function getDate() {
    echo `date '+%Y-%m-%d_%H:%M:%S'`
}

function sendMessage() {
    nowDate=`getDate`
    content="【账号中心php-fpm进程异常通知】\n当前进程数:${fpmNum}；http请求异常次数:${requestExceptionNum}\n请及时检查php服务是否正常\n通知时间：${nowDate}"
    data="{\"msgtype\":\"text\",\"at\":{\"isAtAll\":false,\"atMobiles\":[]},\"text\":{\"content\":\"${content}\"}}"
    curl -H 'Content-Type: application/json' -X POST -d ${data} "https://oapi.dingtalk.com/robot/send?access_token=${dingTalkToken}" > /dev/null
}

function restartPhpFpm() {
    PID=`ps aux|grep php|grep master|awk '{print $2}'`
    if [[ $PID == "" ]];then
        /usr/local/php/sbin/php-fpm
    else
        # kill -INT $PID && /usr/local/php/sbin/php-fpm
        # restart
        kill -USR2 $PID
    fi
    echo -e "restart done\n"
}

if [[ $isException == "Y" ]];then
    sendMessage
fi

echo -e "isException:${isException}\n";