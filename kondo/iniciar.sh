#!/bin/sh
cd "$(dirname "$0")" && exec php -S "${HOST:-localhost}:${PORT:-3000}" -t public public/index.php
