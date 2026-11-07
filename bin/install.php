<?php
declare(strict_types=1);
// Usage: php bin/install.php [--demo] [--fresh]
//   --fresh  deletes the SQLite database first
//   --demo   adds demo users, routes and bookings (password for all: demo1234)

require dirname(__DIR__) . '/src/bootstrap.php';

$args = array_slice($argv, 1);
$dsn = config('db_dsn');

if (in_array('--fresh', $args, true) && str_starts_with($dsn, 'sqlite:')) {
    $file = substr($dsn, 7);
    foreach ([$file, "$file-wal", "$file-shm"] as $f) if (is_file($f)) unlink($f);
}
@mkdir(APP_ROOT . '/storage', 0775, true);
@mkdir(config('upload_dir'), 0775, true);

$db = db();
$db->pdo->exec(file_get_contents(APP_ROOT . '/database/schema.sql'));

// Initial market: Breda–Rotterdam corridor plus neighbours. Real geocoding comes later.
$cities = [
    ['Breda', 'NL', 51.5719, 4.7683], ['Rotterdam', 'NL', 51.9244, 4.4777],
    ['Tilburg', 'NL', 51.5555, 5.0913], ['Eindhoven', 'NL', 51.4416, 5.4697],
    ['Antwerpen', 'BE', 51.2194, 4.4025], ['Dordrecht', 'NL', 51.8133, 4.6901],
    ['Roosendaal', 'NL', 51.5308, 4.4653], ['Etten-Leur', 'NL', 51.5706, 4.6356],
    ['Bergen op Zoom', 'NL', 51.4949, 4.2911], ['Oosterhout', 'NL', 51.6450, 4.8597],
    ["'s-Hertogenbosch", 'NL', 51.6978, 5.3037], ['Zevenbergen', 'NL', 51.6450, 4.6047],
    ['Barendrecht', 'NL', 51.8565, 4.5341], ['Ridderkerk', 'NL', 51.8720, 4.6020],
    ['Zwijndrecht', 'NL', 51.8175, 4.6390], ['Gorinchem', 'NL', 51.8342, 4.9744],
    ['Den Haag', 'NL', 52.0705, 4.3007], ['Delft', 'NL', 52.0116, 4.3571],
    ['Utrecht', 'NL', 52.0907, 5.1214],
];
foreach ($cities as [$name, $country, $lat, $lng]) {
    $db->run('INSERT OR IGNORE INTO cities (name, country, lat, lng) VALUES (?,?,?,?)', [$name, $country, $lat, $lng]);
}
echo "Schema ready, " . count($cities) . " cities loaded.\n";

if (!in_array('--demo', $args, true)) {
    echo "Create your admin with: php bin/create-admin.php you@example.com 'a-strong-password'\n";
    exit;
}

if ($db->value('SELECT COUNT(*) FROM users') > 0) {
    echo "Users already exist, skipping demo data. Use --fresh --demo to reset.\n";
    exit;
}

$city = fn(string $n) => (int)$db->value('SELECT id FROM cities WHERE name = ?', [$n]);
$hash = password_hash('demo1234', PASSWORD_DEFAULT);
$user = fn(array $u) => $db->insert('users', $u + ['password_hash' => $hash]);

$admin  = $user(['email' => 'admin@atobbee.test', 'first_name' => 'Admin', 'last_name' => 'Atobbee', 'role' => 'admin', 'verification_status' => 'verified']);
$pieter = $user(['email' => 'pieter@atobbee.test', 'first_name' => 'Pieter', 'last_name' => 'Verhoeven', 'role' => 'sender']);
$sophie = $user(['email' => 'sophie@atobbee.test', 'first_name' => 'Sophie', 'last_name' => 'van den Berg', 'role' => 'driver',
    'verification_status' => 'verified', 'languages' => 'NL,EN,FR',
    'bio' => 'Always happy to talk about road trips. Quick handover preferred, but I enjoy a friendly chat. Coffee is always welcome at pickup!']);
$marco  = $user(['email' => 'marco@atobbee.test', 'first_name' => 'Marco', 'last_name' => 'Thijssen', 'role' => 'driver',
    'verification_status' => 'verified', 'is_professional' => 1, 'kvk_number' => '00000000', 'languages' => 'NL,EN',
    'bio' => 'Professional transporter. Fragile and bulky items are my thing.']);
$lena   = $user(['email' => 'lena@atobbee.test', 'first_name' => 'Lena', 'last_name' => 'Kowalski', 'role' => 'driver',
    'verification_status' => 'verified', 'languages' => 'NL,EN,PL', 'bio' => 'Commuting Breda–Rotterdam most weekdays.']);
$karin  = $user(['email' => 'karin@atobbee.test', 'first_name' => 'Karin', 'last_name' => 'Janssen', 'role' => 'driver',
    'verification_status' => 'pending', 'verification_note' => 'Submitted ID + driving licence']);
$shop   = $user(['email' => 'kaashoek@atobbee.test', 'first_name' => 'Daan', 'last_name' => 'de Kaashoek', 'role' => 'business',
    'verification_status' => 'verified', 'kvk_number' => '00000001']);

$v = fn(array $x) => $db->insert('vehicles', $x);
$vs = $v(['user_id' => $sophie, 'make_model' => 'Tesla Model 3', 'year' => 2022, 'fuel' => 'electric', 'kind' => 'car', 'max_size' => 'medium', 'max_weight_kg' => 20, 'plate_masked' => '12-**-XZ']);
$vm = $v(['user_id' => $marco, 'make_model' => 'VW Transporter', 'year' => 2020, 'fuel' => 'diesel', 'kind' => 'van', 'max_size' => 'large', 'max_weight_kg' => 300, 'plate_masked' => 'VK-**-21']);
$vl = $v(['user_id' => $lena, 'make_model' => 'Volvo V60', 'year' => 2019, 'fuel' => 'hybrid', 'kind' => 'car', 'max_size' => 'medium', 'max_weight_kg' => 25, 'plate_masked' => 'HX-**-07']);

$db->insert('pickup_points', ['user_id' => $shop, 'name' => 'De Kaashoek', 'address' => 'Grote Markt 1', 'city_id' => $city('Breda'),
    'daily_limit' => 10, 'max_size' => 'medium', 'subscription_status' => 'active', 'subscription_until' => now_utc('+1 month')]);

$today = (new DateTimeImmutable('now'))->format('Y-m-d');
$tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$r = fn(array $x) => $db->insert('routes', $x);
$r(['driver_id' => $sophie, 'vehicle_id' => $vs, 'origin_city_id' => $city('Breda'), 'dest_city_id' => $city('Rotterdam'), 'travel_date' => $today,
    'window_start' => '15:00', 'window_end' => '17:00', 'max_detour_km' => 10, 'max_size' => 'medium', 'max_weight_kg' => 20,
    'categories' => 'food,artisan,documents,clothing,fragile', 'price_cents' => 2000]);
$r(['driver_id' => $marco, 'vehicle_id' => $vm, 'origin_city_id' => $city('Breda'), 'dest_city_id' => $city('Rotterdam'), 'travel_date' => $today,
    'window_start' => '14:00', 'window_end' => '16:00', 'max_detour_km' => 20, 'max_size' => 'large', 'max_weight_kg' => 300,
    'categories' => 'food,artisan,documents,electronics,clothing,fragile', 'price_cents' => 2800]);
$r(['driver_id' => $lena, 'vehicle_id' => $vl, 'origin_city_id' => $city('Etten-Leur'), 'dest_city_id' => $city('Rotterdam'), 'travel_date' => $today,
    'window_start' => '15:30', 'window_end' => '17:00', 'max_detour_km' => 15, 'max_size' => 'medium', 'max_weight_kg' => 25,
    'categories' => 'food,artisan,documents,clothing', 'price_cents' => 1750]);
$r(['driver_id' => $marco, 'vehicle_id' => $vm, 'origin_city_id' => $city('Tilburg'), 'dest_city_id' => $city('Antwerpen'), 'travel_date' => $tomorrow,
    'window_start' => '16:30', 'window_end' => '18:00', 'max_detour_km' => 15, 'max_size' => 'large', 'max_weight_kg' => 300,
    'categories' => 'food,artisan,documents,electronics,clothing,fragile', 'price_cents' => 2400]);

echo "Demo data loaded. Log in with any of these (password demo1234):\n";
foreach (['admin', 'pieter', 'sophie', 'marco', 'lena', 'karin', 'kaashoek'] as $n) echo "  $n@atobbee.test\n";
