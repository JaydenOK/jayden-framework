@echo off

::::::::::::::  stop
echo Start Sync ...

D:/www/rsync/cwRsync_5.4/rsync.exe -avzP  --port=873 --delete --no-super --password-file=/cygdrive/D/www/rsync/cwRsync_5.4/pass.txt --exclude=logs/* --exclude=.git/ --exclude=.idea/ /cygdrive/D/www/sw-www/swoole-mysql-pool-concurrency-server-web/ root@192.168.168.200::mysql_pool_concurrency_server_web

echo Success...
:: 延时
choice /t 3 /d y /n >nul
::pause
exit