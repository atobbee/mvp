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
        created_at   TEXT NOT NULL
    )');
    return $pdo;
}
