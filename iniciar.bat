@echo off
chcp 65001 >nul
setlocal enabledelayedexpansion
cd /d "%~dp0"

echo.
echo   fabOS  -  Ean Fablab
echo   ====================
echo.

REM ---------- 1) Docker ----------
docker info >nul 2>&1
if not errorlevel 1 goto :docker_listo

echo   [1/5] Docker no responde. Abriendo Docker Desktop...
if exist "%ProgramFiles%\Docker\Docker\Docker Desktop.exe" (
    start "" "%ProgramFiles%\Docker\Docker\Docker Desktop.exe"
) else (
    echo         No encontre Docker Desktop. Abrelo a mano.
)
call :esperar_docker
if errorlevel 1 (
    echo.
    echo   ERROR: Docker no arranco despues de 2 minutos.
    echo   Abrelo manualmente y vuelve a ejecutar este archivo.
    echo.
    pause
    exit /b 1
)
goto :docker_ok

:docker_listo
echo   [1/5] Docker ya esta en marcha.
:docker_ok

REM ---------- 2) Configuracion ----------
if exist ".env" goto :env_ok
echo   [2/5] Creando .env a partir de .env.example...
copy /y ".env.example" ".env" >nul
docker run --rm -v "%cd%:/opt" -w /opt laravelsail/php84-composer:latest php artisan key:generate >nul 2>&1
goto :env_fin
:env_ok
echo   [2/5] Configuracion presente.
:env_fin

REM ---------- 3) Contenedores ----------
echo   [3/5] Levantando contenedores...
docker compose up -d
if errorlevel 1 (
    echo.
    echo   ERROR al levantar los contenedores. Revisa el mensaje de arriba.
    echo.
    pause
    exit /b 1
)

REM ---------- 4) Base de datos ----------
echo   [4/5] Esperando a PostgreSQL...
call :esperar_db
if errorlevel 1 (
    echo.
    echo   ERROR: la base de datos no respondio.
    echo   Mira los registros con:  docker compose logs pgsql
    echo.
    pause
    exit /b 1
)

REM ---------- 5) Permisos y migraciones ----------
REM En Windows el montaje entrega las carpetas como root y el servidor corre
REM como "sail": sin esto la aplicacion no puede escribir sus registros.
docker compose exec -T laravel.test chown -R sail:sail storage bootstrap/cache >nul 2>&1

echo   [5/5] Aplicando migraciones pendientes...
docker compose exec -T -u sail laravel.test php artisan migrate --force --no-interaction

echo.
echo   ------------------------------------------------
echo     Aplicacion    http://localhost
echo     Backoffice    http://localhost/admin
echo     Mailpit       http://localhost:8025
echo   ------------------------------------------------
echo.
echo   Para detenerlo:  detener.bat
echo.

start "" http://localhost
endlocal
exit /b 0


REM ================= subrutinas =================

:esperar_docker
set /a intentos=0
:bucle_docker
ping -n 4 127.0.0.1 >nul
docker info >nul 2>&1
if not errorlevel 1 (
    echo         Docker listo.
    exit /b 0
)
set /a intentos+=1
if !intentos! lss 40 goto :bucle_docker
exit /b 1

:esperar_db
set /a intentos=0
:bucle_db
docker compose exec -T pgsql pg_isready -U sail -d fabos >nul 2>&1
if not errorlevel 1 (
    echo         Base de datos lista.
    exit /b 0
)
set /a intentos+=1
ping -n 3 127.0.0.1 >nul
if !intentos! lss 30 goto :bucle_db
exit /b 1
