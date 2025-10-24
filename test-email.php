<?php
require 'vendor/autoload.php';
use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = $_ENV['SMTP_HOST'];
    $mail->SMTPAuth = true;
    $mail->Username = $_ENV['SMTP_USER'];
    $mail->Password = $_ENV['SMTP_PASS'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->setFrom($_ENV['SMTP_USER'], 'Test');
    $mail->addAddress($_ENV['TO_EMAIL']);
    $mail->Subject = 'Test Email from Quantum Ledger';
    $mail->Body = 'If you receive this, SMTP works!';
    $mail->send();
    echo "Email sent successfully!";
} catch (PHPMailerException $e) {
    echo "Email failed: " . $mail->ErrorInfo;
}
?>