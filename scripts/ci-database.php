<?php

if (getenv('CI') !== 'true' || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '33461') {
    exit(2);
}
$pdo = new PDO('mysql:host=127.0.0.1;port=33461', 'root', getenv('MYSQL_ROOT_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS brnmail_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec("GRANT ALL PRIVILEGES ON brnmail_test.* TO 'brnmail'@'%'");
echo "CI database prepared\n";
