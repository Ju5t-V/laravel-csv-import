@echo off
setlocal
cd /d "%~dp0"
set "URL=http://localhost:8000"

where docker >nul 2>&1
if errorlevel 1 (
  echo Docker is not installed. Install Docker Desktop, start it and run this again.
  goto :fail
)
docker info >nul 2>&1
if errorlevel 1 (
  echo The Docker engine is not running. Start Docker Desktop and run this again.
  goto :fail
)

echo Starting containers (the first run installs dependencies, give it a few minutes)...
docker compose up -d --build
if errorlevel 1 (
  echo docker compose failed.
  goto :fail
)

echo Waiting for %URL%/up (up to 3 minutes)...
for /l %%i in (1,1,36) do (
  curl.exe -s -o nul -f "%URL%/up" >nul 2>&1
  if not errorlevel 1 goto :ready
  echo   [%%i/36] not ready yet, retrying in 5 s
  ping -n 6 127.0.0.1 >nul
)
echo The app did not become ready in time. Check: docker compose logs -f app
goto :fail

:ready
echo Ready: %URL%
start "" "%URL%"
echo.
echo What to try:
echo   Generate a sample CSV:  docker compose exec app php artisan products:generate-csv 10000 --dirty
echo                           (written to storage/app/samples, then drag it onto the page)
echo   Run the CLI demo:       docker compose exec app composer demo
echo   Stop everything:        docker compose down
echo.
pause
exit /b 0

:fail
echo.
pause
exit /b 1
