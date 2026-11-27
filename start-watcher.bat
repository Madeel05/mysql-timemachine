@echo off
title MySQL TimeMachine Background Watcher
cd /d "%~dp0"

where php >nul 2>nul
if %errorlevel%==0 (
    php watcher.php --interval=60
    goto :eof
)

for /d %%D in (D:\laragon\bin\php\php-*) do (
    if exist "%%D\php.exe" (
        "%%D\php.exe" watcher.php --interval=60
        goto :eof
    )
)

echo PHP executable not found in PATH or D:\laragon\bin\php\.
pause
