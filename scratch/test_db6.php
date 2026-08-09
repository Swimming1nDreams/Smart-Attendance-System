<?php
$pdo = new PDO('mysql:host=localhost;dbname=sapas_test', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

for ($i = 0; $i < 5; $i++) {
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'sapas_test'")->fetchAll(PDO::FETCH_COLUMN);
    echo "Iteration $i: Found " . count($tables) . " tables\n";
    
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    
    $pdo->exec("CREATE TABLE departments (id INT, PRIMARY KEY(id))");
    $pdo->exec("CREATE TABLE positions (id INT, PRIMARY KEY(id))");
}
echo "Done.\n";
