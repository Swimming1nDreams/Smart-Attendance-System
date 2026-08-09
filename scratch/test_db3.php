<?php
$pdo = new PDO('mysql:host=localhost', 'root', '');
var_dump($pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN));
