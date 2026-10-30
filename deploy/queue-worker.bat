@echo off
rem Queue worker: 1C order sync, Telegram retries, AjvaAI. Keeps running; restarts itself if it stops.
set PHP=D:\OSPanel\modules\php\PHP_8.1\php.exe
set APP=D:\OSPanel\domains\crm

:loop
"%PHP%" "%APP%\artisan" queue:work --timeout=120 --sleep=3 --tries=5 --max-time=3600 >> "%APP%\storage\logs\queue.log" 2>&1
timeout /t 5 /nobreak >nul
goto loop
