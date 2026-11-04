<?php
// Copy to config.php and adjust. config.php is not committed.
return [
    'app_name'   => 'AtoBbee',
    'base_url'   => 'http://localhost:8000',
    'env'        => 'dev',                      // dev | prod (prod hides errors, forces secure cookies)

    // SQLite by default. For MySQL: 'mysql:host=127.0.0.1;dbname=atobbee;charset=utf8mb4'
    'db_dsn'     => 'sqlite:' . __DIR__ . '/storage/atobbee.sqlite',
    'db_user'    => null,
    'db_pass'    => null,

    // Business rules
    'commission_rate'        => 0.08,   // 8%
    'commission_min_cents'   => 150,    // €1.50
    'min_price_cents'        => 500,    // €5.00 lowest price a driver can ask
    'dispute_window_hours'   => 24,
    'max_pin_failures'       => 5,
    'road_factor'            => 1.3,   // straight-line km → estimated road km
    'business_fee_cents'     => 99,     // €0.99 / month pickup point subscription

    // Payments: 'fake' (no money moves) or 'stripe' (test or live keys)
    'payment_gateway'        => 'fake',
    'stripe_secret_key'      => '',     // sk_test_...
    'stripe_webhook_secret'  => '',     // whsec_...

    // Where proof-of-delivery photos go (must NOT be inside public/)
    'upload_dir'             => __DIR__ . '/storage/uploads',
];
