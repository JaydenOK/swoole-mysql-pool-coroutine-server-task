@echo off

::::::::::::::  stop
echo Start Sync ...

D:/www/rsync/cwRsync_5.4/rsync.exe -avzP  --port=873 --delete --no-super --password-file=/cygdrive/D/www/rsync/cwRsync_5.4/pass.txt --exclude=logs/* --exclude=.git/ --exclude=.idea/ /cygdrive/D/www/sw-www/mysql-pool-coroutine-server-task/ root@192.168.92.208::mysql_pool_coroutine_server_task

echo Success...
:: 延时
choice /t 1 /d y /n >nul
::pause
exit