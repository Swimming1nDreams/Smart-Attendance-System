<?php
$pdo = new PDO('mysql:host=localhost', 'root', '');
$pdo->exec('DROP DATABASE IF EXISTS sapas_test');
$pdo->exec('CREATE DATABASE sapas_test');
var_dump($pdo->errorInfo());
