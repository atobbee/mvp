<?php
declare(strict_types=1);
// Usage: php tests/run.php   (uses a throwaway in-memory database)

$GLOBALS['__test_config'] = true;
require dirname(__DIR__) . '/src/bootstrap.php';
$GLOBALS['__config']['db_dsn'] = 'sqlite::memory:';
$GLOBALS['__config']['payment_gateway'] = 'fake';

use Atobbee\Domain\{BookingService, BookingStatus, Matching, Pricing};

$passed = 0; $failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ✓ $name\n"; } else { $failed++; echo "  ✗ $name" . ($detail ? " — $detail" : '') . "\n"; }
}
function throws(callable $fn): ?string { try { $fn(); return null; } catch (\Throwable $e) { return $e->getMessage(); } }

echo "Pricing\n";
$p = new Pricing();
check('€20 → €1.60 commission, driver €18.40', $p->split(2000) === ['price' => 2000, 'commission' => 160, 'driver' => 1840]);
check('€16.50 → minimum €1.50 applies', $p->split(1650)['commission'] === 150);
check('€17.50 → 8% is €1.40, so minimum €1.50, driver €16.00', $p->split(1750)['driver'] === 1600);
check('€22 → €1.76 fee, €20.24 to driver', $p->split(2200)['driver'] === 2024);
check('below €5 rejected', throws(fn() => $p->split(400)) !== null);
$s = $p->suggestions(65);
check('65 km suggestions €14 / €22 / €32', [$s['cost'], $s['fair'], $s['priority']] === [1400, 2200, 3200], json_encode($s));

echo "Matching\n";
$m = new Matching(1.3);
$breda = [51.5719, 4.7683]; $rdam = [51.9244, 4.4777]; $ehv = [51.4416, 5.4697];
$route = ['o_lat' => $breda[0], 'o_lng' => $breda[1], 'd_lat' => $rdam[0], 'd_lng' => $rdam[1], 'travel_date' => '2026-11-05',
    'window_start' => '15:00', 'window_end' => '17:00', 'max_detour_km' => 10, 'max_size' => 'medium', 'max_weight_kg' => 20,
    'categories' => 'food,artisan,fragile', 'capacity_left' => 2];
$pkg = ['p_lat' => $breda[0], 'p_lng' => $breda[1], 'x_lat' => $rdam[0], 'x_lng' => $rdam[1], 'pickup_date' => '2026-11-05',
    'window_start' => '15:00', 'window_end' => '16:00', 'size' => 'small', 'weight_kg' => 3.2, 'category' => 'artisan', 'requirements' => ['pin', 'fragile']];
$r = $m->evaluate($route, $pkg);
check('same corridor matches with high score', $r['ok'] && $r['score'] >= 90, json_encode($r));
check('Breda–Rotterdam ≈ 50–65 road km', abs($m->roadKm(...$breda, ...$rdam) - 57) < 8);
check('wrong date rejected', !$m->evaluate($route, ['pickup_date' => '2026-11-06'] + $pkg)['ok']);
check('too heavy rejected', !$m->evaluate($route, ['weight_kg' => 25] + $pkg)['ok']);
check('too big rejected', !$m->evaluate($route, ['size' => 'large'] + $pkg)['ok']);
check('category not accepted rejected', !$m->evaluate($route, ['category' => 'electronics'] + $pkg)['ok']);
check('no window overlap rejected', !$m->evaluate($route, ['window_start' => '18:00', 'window_end' => '19:00'] + $pkg)['ok']);
check('Eindhoven pickup is too big a detour', !$m->evaluate($route, ['p_lat' => $ehv[0], 'p_lng' => $ehv[1]] + $pkg)['ok']);
check('full route rejected', !$m->evaluate(['capacity_left' => 0] + $route, $pkg)['ok']);

echo "Booking lifecycle\n";
db()->pdo->exec(file_get_contents(APP_ROOT . '/database/schema.sql'));
db()->run("INSERT INTO cities (name, lat, lng) VALUES ('Breda', 51.5719, 4.7683), ('Rotterdam', 51.9244, 4.4777)");
$uid = fn($role, $v = 'verified') => db()->insert('users', ['email' => uniqid() . '@t.test', 'password_hash' => 'x', 'first_name' => 'T', 'last_name' => $role, 'role' => $role, 'verification_status' => $v]);
$sender = $uid('sender', 'none'); $driver = $uid('driver'); $other = $uid('sender', 'none'); $admin = $uid('admin');
$today = date('Y-m-d');
$routeId = db()->insert('routes', ['driver_id' => $driver, 'origin_city_id' => 1, 'dest_city_id' => 2, 'travel_date' => $today,
    'window_start' => '15:00', 'window_end' => '17:00', 'max_detour_km' => 10, 'max_size' => 'medium', 'max_weight_kg' => 20,
    'capacity' => 1, 'categories' => '', 'price_cents' => 2000]);
$newPackage = fn() => db()->insert('packages', ['sender_id' => $sender, 'pickup_address' => 'A', 'pickup_city_id' => 1, 'delivery_address' => 'B',
    'delivery_city_id' => 2, 'recipient_name' => 'R', 'pickup_date' => $today, 'window_start' => '15:00', 'window_end' => '16:00',
    'size' => 'small', 'weight_kg' => 3, 'category' => 'artisan', 'description' => 'cheese', 'requirements' => '["pin","photo"]']);
$pkgId = $newPackage();

$svc = BookingService::make();
check('package finds the route', count($svc->matchesForPackage($svc->package($pkgId))) === 1);
check('route finds the package', count($svc->matchesForRoute($svc->route($routeId))) === 1);
check('someone else cannot book my package', throws(fn() => $svc->book($pkgId, $routeId, $other)) !== null);

$bid = $svc->book($pkgId, $routeId, $sender);
$b = $svc->booking($bid);
check('booking created awaiting payment with ref', $b['status'] === BookingStatus::AWAITING_PAYMENT && preg_match('/^ATB-\d{4}-\d{5}$/', $b['ref']) === 1);
check('split stored on booking', $b['commission_cents'] === 160 && $b['driver_cents'] === 1840);

$svc->startPayment($bid, $sender, 'x', 'y');
check('fake payment marks paid', $svc->booking($bid)['status'] === BookingStatus::PAID);
check('package marked booked', $svc->package($pkgId)['status'] === 'booked');
check('route at capacity → second package no longer matches', (function () use ($svc, $newPackage, $routeId) {
    $p2 = $newPackage(); return count($svc->matchesForPackage($svc->package($p2))) === 0;
})());

check('cannot deliver before pickup', throws(fn() => $svc->confirmDelivery($bid, $driver, '0000', [], 'p.jpg', null)) !== null);
$wrong = $b['pickup_pin'] === '0000' ? '1111' : '0000';
check('wrong pickup PIN refused', str_contains((string)throws(fn() => $svc->confirmPickup($bid, $driver, $wrong)), 'not right'));
$svc->confirmPickup($bid, $driver, $b['pickup_pin']);
check('right pickup PIN → in transit', $svc->booking($bid)['status'] === BookingStatus::PICKED_UP);
check('photo requirement enforced', str_contains((string)throws(fn() => $svc->confirmDelivery($bid, $driver, $b['delivery_pin'], [], null, null)), 'photo'));
$svc->confirmDelivery($bid, $driver, $b['delivery_pin'], [], 'proof.jpg', null);
$b = $svc->booking($bid);
check('delivered with 24h dispute window', $b['status'] === BookingStatus::DELIVERED && $b['payout_due_at'] > now_utc('+23 hours'));
check('no payout before window closes', $svc->releaseDuePayouts() === []);
db()->run('UPDATE bookings SET payout_due_at = ? WHERE id = ?', [now_utc('-1 minute'), $bid]);
check('payout released after window', $svc->releaseDuePayouts() === [$b['ref']] && $svc->booking($bid)['status'] === BookingStatus::COMPLETED);
check('cannot cancel a completed booking', throws(fn() => $svc->cancel($bid, $sender)) !== null);

$svc->review($bid, $sender, ['communication' => 5, 'punctuality' => 5, 'care' => 4, 'accuracy' => 5, 'overall' => 5], 'Great');
check('review counts towards driver rating', $svc->driverStats($driver)['rating'] === 5.0 && $svc->driverStats($driver)['deliveries'] === 1);
check('one review per booking per person', throws(fn() => $svc->review($bid, $sender, ['communication' => 5, 'punctuality' => 5, 'care' => 5, 'accuracy' => 5, 'overall' => 5], '')) !== null);

echo "Disputes and cancellation\n";
db()->run("UPDATE routes SET capacity = 5");
$p3 = $newPackage();
$b3 = $svc->book($p3, $routeId, $sender); $svc->startPayment($b3, $sender, 'x', 'y');
$svc->cancel($b3, $driver);
check('driver cancel after payment → refund + package reopened', $svc->booking($b3)['status'] === 'cancelled' && $svc->package($p3)['status'] === 'open'
    && db()->value("SELECT COUNT(*) FROM payment_log WHERE booking_id = ? AND kind = 'refund'", [$b3]) == 1);

$b4 = $svc->book($p3, $routeId, $sender); $svc->startPayment($b4, $sender, 'x', 'y');
$bb = $svc->booking($b4);
$svc->confirmPickup($b4, $driver, $bb['pickup_pin']);
$svc->confirmDelivery($b4, $driver, $bb['delivery_pin'], [], 'p.jpg', null);
$svc->openDispute($b4, $sender, 'damaged', 'The cheese arrived squashed.');
db()->run('UPDATE bookings SET payout_due_at = ? WHERE id = ?', [now_utc('-1 minute'), $b4]);
check('disputed booking is not paid out by cron', $svc->releaseDuePayouts() === [] && $svc->booking($b4)['status'] === 'disputed');
$did = (int)db()->value('SELECT id FROM disputes WHERE booking_id = ?', [$b4]);
$svc->resolveDispute($did, $admin, 'refund', 'Photo shows damage');
check('admin refund resolves dispute', $svc->booking($b4)['status'] === 'refunded');

echo "Offers\n";
$p5 = $newPackage();
$o1 = $svc->offer($p5, $routeId, $driver);
check('driver offer waits for sender', $svc->booking($o1)['status'] === BookingStatus::OFFERED);
check('sender cannot pay an unaccepted offer', throws(fn() => $svc->startPayment($o1, $sender, 'x', 'y')) !== null);
$svc->acceptOffer($o1, $sender);
$svc->startPayment($o1, $sender, 'x', 'y');
check('accepted offer can be paid', $svc->booking($o1)['status'] === BookingStatus::PAID);

echo "Unverified drivers\n";
$newbie = $uid('driver', 'pending');
$r2 = db()->insert('routes', ['driver_id' => $newbie, 'origin_city_id' => 1, 'dest_city_id' => 2, 'travel_date' => $today,
    'window_start' => '15:00', 'window_end' => '17:00', 'max_detour_km' => 10, 'categories' => '', 'price_cents' => 1500]);
$p6 = $newPackage();
check('unverified driver routes are hidden from senders', !in_array($r2, array_column($svc->matchesForPackage($svc->package($p6)), 'id')));
check('unverified driver cannot be booked directly', throws(fn() => $svc->book($p6, $r2, $sender)) !== null);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
