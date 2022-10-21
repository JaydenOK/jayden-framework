#!/bin/bash
# 秒级定时器 ，间隔duration秒取命令文件smc_cmd.sh的命令执行
#
# 间隔 3s 取命令执行，此脚本每分钟执行:
# * * * * * root /mnt/yibai_ac_system/appdal/smc/timer.sh > /dev/null 2>&1

# 命令执行间隔duration，duration最好设置能整除60，即: duration*times=60
duration=3
times=20

minute=`date '+%M'`

ScriptDir=$(cd `dirname $0`; pwd)

lockFile=${ScriptDir}/smc.lock
logFile=${ScriptDir}/smc.log
cmdFile=${ScriptDir}/smc_cmd.sh

function task() {
    isLock=`cat ${lockFile}`
    if [[ $isLock != "" ]]; then
        echo "lock"
        return
    fi

    echo `date '+%Y-%m-%d-%H-%M-%-S'` > $lockFile

    cmd=`cat ${cmdFile}`

    if [[ $cmd == "" ]]; then
        echo -e "no cmd"
        echo "" > $lockFile
        return
    fi

    echo $cmd | tee -a $logFile &
    $cmd | tee -a $logFile &

    echo "" > $cmdFile
    echo "" > $lockFile
}

for (( i = 0; i < $times; i++ )); do
    if [[ $minute != `date '+%M'` ]]; then
        echo "task exit"
        break
    fi
    task
    sleep $duration
done