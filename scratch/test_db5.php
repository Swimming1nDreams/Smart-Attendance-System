<?php
$pdo = new PDO('mysql:host=localhost;dbname=sapas_test', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'sapas_test'")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    echo "Dropping $table\n";
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "Creating departments\n";
$pdo->exec("CREATE TABLE departments (id INT, PRIMARY KEY(id))");

echo "Creating positions\n";
$pdo->exec("CREATE TABLE positions (id INT, PRIMARY KEY(id))");

echo "Done.\n";
