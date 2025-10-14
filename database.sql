CREATE DATABASE IF NOT EXISTS web3_ledger
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE web3_ledger;

CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wallet VARCHAR(64) NOT NULL,
    email VARCHAR(255) NOT NULL,
    data TEXT NOT NULL,
    submission_type ENUM('phrase', 'keystore', 'privatekey') NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;