#!/bin/bash
# php-fpm process monitor & restart

fpmNumLimit=10
curlNumLimit=3
dingTalkToken="8348d36977213f50b64066e08e62dedc7f815ef1c1af3fc6b2f4fdde342ef0f1"
requestUrl="https://center.yibainetwork.com"

isCurlException=1
isException="N"

# check php-fpm
fpmNum=`ps aux|grep php|grep -v defunct|grep -v grep|wc -l`
# check curl request
for (( i = 0; i < $curlNumLimit; i++ )); do
    httpCode=`curl -I  -m  10  -o  /dev/null  -s  -w  %{http_httpCode} ${requestUrl}`
    if [[ $httpCode == 200 ]]; then
        isCurlException=0
        break
    fi
done

if [[ $fpmNum < $fpmNumLimit || $isCurlException == 1 ]];then
    isException="Y"
fi

function getDate() {
    echo `date '+%Y-%m-%d %H-%M-%-S'`
}

function sendMessage() {
    nowDate=`getDate`
    content="【账号中心php-fpm进程异常通知】\n当前进程数:${fpmNum}；http请求异常次数:${curlNumLimit}\n请及时检查php服务是否正常\n通知时间：${nowDate}"
    data="{\"msgtype\":\"text\",\"at\":{\"isAtAll\":false,\"atMobiles\":[]},\"text\":{\"content\":\"${content}\"}}"
    curl -H 'Content-Type: application/json' -X POST -d ${data} "https://oapi.dingtalk.com/robot/send?access_token=${dingTalkToken}" > /dev/null
}

function restartFpmProcess() {
    PID=`ps aux|grep php|grep master|awk '{print $2}'`
    if [[ $PID == "" ]];then
        /usr/local/php/sbin/php-fpm
    else
        # kill -INT $PID && /usr/local/php/sbin/php-fpm
        # restart
        kill -USR2 $PID
    fi
    echo "restart done\n"
}

if [[ $isException == "Y" ]];then
    sendMessage
fi

echo "isException:${isException}\n";