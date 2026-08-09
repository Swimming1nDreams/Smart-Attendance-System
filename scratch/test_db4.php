<?php
$pdo = new PDO('mysql:host=localhost;dbname=sapas_test', 'root', '');
var_dump($pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'sapas_test'")->fetchAll(PDO::FETCH_COLUMN));
