@echo off
chcp 65001 >nul
cd /d "%~dp0"

echo.
echo   Deteniendo fabOS...
echo.

docker compose stop

echo.
echo   Contenedores detenidos. Los datos se conservan.
echo   Para volver a levantarlo:  iniciar.bat
echo.
echo   Si alguna vez quieres borrar TAMBIEN la base de datos:
echo       docker compose down -v
echo.
pause
