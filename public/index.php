<?php

date_default_timezone_set('GMT');

require_once('./../vendor/autoload.php');

use App\Bootstrap;

$dbHost = config('DB_HOST');
$dbPort = config('DB_PORT');
$dbName = config('DB_NAME');
$dbUser = config('DB_USER_NAME');
$dbPass = config('DB_PASSWORD');
$dbSocket = config('DB_SOCKET');

// Create PDO connection with Unix socket
$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};unix_socket={$dbSocket}";

$pdo = new PDO($dsn, $dbUser, $dbPass);

$bootstrap = new App\Bootstrap($_POST['action'] ?? '', $pdo);
