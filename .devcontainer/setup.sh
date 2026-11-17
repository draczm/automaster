#!/usr/bin/env bash
sudo apt-get update -y
sudo apt-get install -y mariadb-server
php -m | grep -qi pdo_mysql || sudo env PHP_INI_DIR=/usr/local/etc/php docker-php-ext-install pdo_mysql
sudo service mariadb start || sudo service mysql start
sudo mysql -e "CREATE DATABASE IF NOT EXISTS automaster CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'app'@'127.0.0.1' IDENTIFIED BY 'app'; CREATE USER IF NOT EXISTS 'app'@'localhost' IDENTIFIED BY 'app'; GRANT ALL ON automaster.* TO 'app'@'127.0.0.1'; GRANT ALL ON automaster.* TO 'app'@'localhost'; FLUSH PRIVILEGES;"
sudo mysql automaster < database/import_hosting.sql
