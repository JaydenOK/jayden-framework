#! /bin/bash

PROGRESS_NAME="php-fpm"
LOG_FILE="/tmp/daemon_php.log"
AVAILABLE_MEM=`free -m | sed -n '2p' | awk '{print $7}'`
if [ $AVAILABLE_MEM -lt 900 ];then
/etc/init.d/$PROGRESS_NAME restart
TIME=`date +"%Y%m%d %H:%M:%S"`
echo "$TIME $PROGRESS_NAME restarted." >> $LOG_FILE
fi
sleep 5
ps aux | grep $PROGRESS_NAME | grep -v root
if [ $? = 1 ];then
/etc/init.d/$PROGRESS_NAME start
TIME=`date +"%Y%m%d %H:%M:%S"`
echo "$TIME $PROGRESS_NAME restarted." >> $LOG_FILE
fi

PROGRESS_NUM_MAX=`grep pm.max_children /usr/local/php/etc/php-fpm.conf | awk -F= '{print $2}'`
if [ -z "$PROGRESS_NUM_MAX" ];then
PROGRESS_NUM_MAX=800
fi
PROGRESS_NUM_MIN=`grep pm.start_servers /usr/local/php/etc/php-fpm.conf | awk -F= '{print $2}'`
if [ -z "$PROGRESS_NUM_MIN" ];then
PROGRESS_NUM_MIN=20
fi
PROGRESS_NUM=`ps aux | grep php-fpm| grep -v grep | wc -l`
if [ $PROGRESS_NUM -lt $PROGRESS_NUM_MIN -o $PROGRESS_NUM -gt $PROGRESS_NUM_MAX ];then
pkill -9 $PROGRESS_NAME
/etc/init.d/$PROGRESS_NAME start
TIME=`date +"%Y%m%d %H:%M:%S"`
echo "$TIME $PROGRESS_NAME exception,PROGRESS_NUM $PROGRESS_NUM,restarted." >> $LOG_FILE
fi