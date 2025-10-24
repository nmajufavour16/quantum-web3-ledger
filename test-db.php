<?php
require 'vendor/autoload.php';
use Dotenv\Dotenv;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Exception\Exception as MongoDBException;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

try {
    $mongoUri = "mongodb://" . $_ENV['DB_USER'] . ":" . $_ENV['DB_PASS'] . "@" . $_ENV['DB_HOST'] . ":" . $_ENV['DB_PORT'] . "/" . $_ENV['DB_NAME'] . "?authSource=admin&directConnection=true";
    $manager = new Manager($mongoUri);
    echo "MongoDB connected successfully!<br>";
} catch (MongoDBException $e) {
    echo "Connection failed: " . $e->getMessage() . "<br>";
}
?>
