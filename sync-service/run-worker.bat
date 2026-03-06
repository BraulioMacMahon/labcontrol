@echo off
REM Run the sync worker from the sync-service folder and redirect logs
cd /d "%~dp0"
if not exist "logs" mkdir "logs"
node index.js >> "logs\stdout.log" 2>> "logs\stderr.log"
