<?php
$localCfg = __DIR__ . '/db.local.php';
if (is_file($localCfg)) {
    require $localCfg; // задаёт $host, $dbname, $username, $password на хостинге
}
$host = $host ?? (getenv('DB_HOST') ?: 'localhost');
$dbname = $dbname ?? (getenv('DB_NAME') ?: 'automaster');
$username = $username ?? (getenv('DB_USER') ?: 'root');
$password = $password ?? (getenv('DB_PASS') ?: '');

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die('Ошибка подключения к базе данных: ' . $e->getMessage());
}
?>