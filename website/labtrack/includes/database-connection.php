<?php

$type     = 'mysql';
$server   = '127.0.0.1';
$db       = 'oscaraqu_labtrack';
$port     = '3307';
$charset  = 'utf8mb4';

$username = 'root';   // default for XAMPP
$password = '';       // default for XAMPP

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$dsn = "$type:host=$server;dbname=$db;port=$port;charset=$charset";
$pdo = new PDO($dsn, $username, $password, $options);
