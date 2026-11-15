@echo off
rem Versión del archivo: 1.0.0 (2026-11-15)
rem Compila el Optimizador de video en un solo .exe (con ffmpeg dentro).
rem Requisitos: Python 3.10+ en el PATH. Resultado: dist\SmartPizarra-Optimizador.exe
cd /d "%~dp0"
python -m pip install --upgrade imageio-ffmpeg pyinstaller || goto :error
python -m PyInstaller --noconfirm --clean --onefile --windowed ^
  --name "SmartPizarra-Optimizador" ^
  --collect-binaries imageio_ffmpeg --collect-data imageio_ffmpeg ^
  optimizador_video.py || goto :error
echo.
echo Listo: %~dp0dist\SmartPizarra-Optimizador.exe
goto :eof
:error
echo.
echo ERROR al compilar.
exit /b 1
