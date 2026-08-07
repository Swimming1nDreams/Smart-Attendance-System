<?php

declare(strict_types=1);

$mysqlInitCommandAttr = null;

if (defined('PDO\\Mysql::ATTR_INIT_COMMAND')) {
    $mysqlInitCommandAttr = PDO\Mysql::ATTR_INIT_COMMAND;
} elseif (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
    $mysqlInitCommandAttr = PDO::MYSQL_ATTR_INIT_COMMAND;
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

if ($mysqlInitCommandAttr !== null) {
    $options[$mysqlInitCommandAttr] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
}

return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'name' => getenv('DB_NAME') ?: 'smart_attendance',
    'user' => getenv('DB_USER') ?: 'root',
    'pass' => getenv('DB_PASS') ?: '',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    'timezone' => getenv('DB_TIMEZONE') ?: '+06:30',
    'options' => $options,
];
