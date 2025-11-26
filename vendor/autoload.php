<?php

/**
 * Lightweight PSR-4 style autoloader to replace the missing Composer autoloader.
 * 
 * It supports only the namespaces actually used in this project:
 * - Dotenv\*          → vendor/vlucas/phpdotenv/src
 * - PHPMailer\PHPMailer\* → vendor/phpmailer/phpmailer/src
 */

spl_autoload_register(function (string $class): void {
    // Map namespace prefixes to base directories
    $prefixes = [
        'Dotenv\\'               => __DIR__ . '/vlucas/phpdotenv/src/',
        'PHPMailer\\PHPMailer\\' => __DIR__ . '/phpmailer/phpmailer/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

// Return true like the real Composer autoloader does
return true;


