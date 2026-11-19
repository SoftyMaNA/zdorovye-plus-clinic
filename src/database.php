<?php
declare(strict_types=1);
function database(): PDO
{
    $name = getenv('DB_NAME') ?: 'zdorovye_plus';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('Invalid DB_NAME');
    return new PDO('mysql:host='.(getenv('DB_HOST') ?: '127.0.0.1').
        ';port='.(getenv('DB_PORT') ?: '3306').';dbname='.$name.';charset=utf8mb4',
        getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
}
