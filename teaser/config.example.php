<?php
// ============================================================
//  AtoBbee teaser — settings.
//  Copy this file to config.php on your server and edit THAT copy.
//  config.php is never uploaded to GitHub, so your password stays private.
// ============================================================

return [
    // Password for admin.php (where you view and download the waitlist).
    // Change this to something long that only you know.
    'admin_password' => 'CHANGE-ME-to-something-long-and-secret',

    // Where the database file lives. Leave as-is.
    'db_path' => __DIR__ . '/data/waitlist.sqlite',
];
