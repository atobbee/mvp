<?php
// Opens (and on first use, creates) the SQLite database file.
// SQLite = the whole database is one file. Nothing to install or pay for.

function atobbee_db(): PDO
{
    $config = require (file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/config.example.php');
    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE IF NOT EXISTS waitlist (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        email        TEXT NOT NULL UNIQUE,
        role         TEXT NOT NULL,
        consent_text TEXT NOT NULL,
        created_at   TEXT NOT NULL,
        first_name   TEXT,
        company      TEXT
    )');
    // Upgrade databases created before these columns existed
    $cols = array_column($pdo->query('PRAGMA table_info(waitlist)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['first_name', 'company'] as $col) {
        if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE waitlist ADD COLUMN $col TEXT");
    }
    return $pdo;
}
