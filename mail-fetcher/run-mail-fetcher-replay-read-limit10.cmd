@echo off
setlocal enableextensions

set "SCRIPT_DIR=%~dp0"
cd /d "%SCRIPT_DIR%" || exit /b 1

set "LIMIT=10"
set "MAIL_FETCH_SCOPE=seen"
set "MAIL_DELETE_AFTER_SUCCESS=false"

if not exist ".\node_modules\" (
  echo [ERROR] Dossier node_modules introuvable. Lance d'abord: npm install
  exit /b 2
)

if not exist ".\logs\" mkdir ".\logs"
set "LOG_FILE=%SCRIPT_DIR%logs\mail-fetcher-replay-read.log"

echo [%date% %time%] START replay-read LIMIT=%LIMIT% SCOPE=%MAIL_FETCH_SCOPE% DELETE_AFTER_SUCCESS=%MAIL_DELETE_AFTER_SUCCESS%>>"%LOG_FILE%"
echo [%date% %time%] START replay-read LIMIT=%LIMIT% SCOPE=%MAIL_FETCH_SCOPE% DELETE_AFTER_SUCCESS=%MAIL_DELETE_AFTER_SUCCESS%

call npm run reception:principale >>"%LOG_FILE%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"

if not "%EXIT_CODE%"=="0" (
  echo [%date% %time%] ERROR exit_code=%EXIT_CODE%>>"%LOG_FILE%"
  echo [%date% %time%] ERROR exit_code=%EXIT_CODE%
  exit /b %EXIT_CODE%
)

echo [%date% %time%] END OK>>"%LOG_FILE%"
echo [%date% %time%] END OK

exit /b 0
