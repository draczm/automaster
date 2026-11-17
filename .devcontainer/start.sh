#!/usr/bin/env bash
sudo service mariadb start || sudo service mysql start
pgrep -f "php -S 0.0.0.0:8080" >/dev/null && exit 0
export DB_HOST=127.0.0.1 DB_NAME=automaster DB_USER=app DB_PASS=app
nohup php -S 0.0.0.0:8080 -t . > /tmp/php.log 2>&1 &
