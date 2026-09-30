@echo off
rem Arranca o Kondo. Faça duplo clique neste ficheiro (requer PHP 8.1+ no PATH).
cd /d "%~dp0"
echo A iniciar o Kondo em http://localhost:3000 ...
echo Para parar o servidor, feche esta janela ou carregue Ctrl+C.
start "" http://localhost:3000
php -d extension=pdo_sqlite -S localhost:3000 -t public public/index.php
pause
