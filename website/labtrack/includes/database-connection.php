<?php

$type     = 'mysql';
$server   = 'localhost';
$db       = 'oscaraqu_labtrack';
$charset  = 'utf8mb4';

$username = 'oscaraqu_labtrack_user';
$password = 'CHANGE_ME';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$dsn = "$type:host=$server;dbname=$db;charset=$charset";
$pdo = new PDO($dsn, $username, $password, $options);