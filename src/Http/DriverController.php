<?php
declare(strict_types=1);

namespace Atobbee\Http;

use Atobbee\Auth;
use Atobbee\Domain\{BookingService, Matching, Pricing};

final class DriverController
{
    public function dashboard(): void
    {
        $u = Auth::require('driver');
        $svc = BookingService::make();
        $bookings = db()->all("SELECT b.*, pc.name AS pickup_city, dc.name AS delivery_city, p.size, p.weight_kg, p.description,
                p.requirements, p.pickup_date, p.window_start, p.window_end, s.first_name AS sender_first, s.last_name AS sender_last,
                (SELECT COUNT(*) FROM reviews rv WHERE rv.booking_id = b.id AND rv.reviewer_id = b.driver_id) AS reviewed
            FROM bookings b JOIN packages p ON p.id = b.package_id
            JOIN cities pc ON pc.id = p.pickup_city_id JOIN cities dc ON dc.id = p.delivery_city_id
            JOIN users s ON s.id = b.sender_id
            WHERE b.driver_id = ? AND b.status NOT IN ('cancelled') ORDER BY b.id DESC LIMIT 50", [$u['id']]);
        $routes = db()->all("SELECT r.id FROM routes r WHERE r.driver_id = ? AND r.status = 'active' AND r.travel_date >= ? ORDER BY r.travel_date, r.window_start", [$u['id'], date('Y-m-d')]);
        $routes = array_map(function ($r) use ($svc) {
            $route = $svc->route((int)$r['id']);
            return $route + ['match_count' => count($svc->matchesForRoute($route))];
        }, $routes);
        $sum = fn(string $where, array $p = []) => (int)db()->value("SELECT COALESCE(SUM(driver_cents),0) FROM bookings WHERE driver_id = ? AND $where", array_merge([$u['id']], $p));
        view('driver/dashboard', [
            'user' => $u,
            'stats' => $svc->driverStats((int)$u['id']),
            'bookings' => $bookings,
            'routes' => $routes,
            'earnings' => [
                'pending' => $sum("status IN ('paid','picked_up','at_point','delivered','disputed')"),
                'paid' => $sum("status = 'completed'"),
                'month' => $sum("status = 'completed' AND paid_out_at >= ?", [gmdate('Y-m-01\T00:00:00\Z')]),
            ],
            'vehicle' => db()->one('SELECT * FROM vehicles WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$u['id']]),
        ]);
    }

    public function routeForm(): void
    {
        $u = Auth::require('driver');
        $this->mustBeVerified($u);
        view('driver/route_form', [
            'cities' => PageController::cities(),
            'vehicle' => db()->one('SELECT * FROM vehicles WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$u['id']]),
        ]);
    }

    public function createRoute(): void
    {
        $u = Auth::require('driver');
        $this->mustBeVerified($u);
        $vehicle = db()->one('SELECT * FROM vehicles WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$u['id']]);
        $in = fn($k) => (string)input($k, '');
        $cats = array_values(array_intersect((array)($_POST['categories'] ?? []), array_keys(BookingService::CATEGORIES)));
        $price = (int)round((float)str_replace(',', '.', $in('price')) * 100);
        $data = [
            'driver_id' => $u['id'], 'vehicle_id' => $vehicle['id'] ?? null,
            'origin_city_id' => (int)$in('origin_city_id'), 'dest_city_id' => (int)$in('dest_city_id'),
            'travel_date' => $in('travel_date'), 'window_start' => $in('window_start'), 'window_end' => $in('window_end'),
            'max_detour_km' => max(0, min(100, (int)$in('max_detour_km'))),
            'max_size' => $in('max_size'), 'max_weight_kg' => min((float)($vehicle['max_weight_kg'] ?? 20), max(0.1, (float)$in('max_weight_kg'))),
            'capacity' => max(1, min(20, (int)$in('capacity'))),
            'categories' => implode(',', $cats), 'price_cents' => $price,
        ];
        $errors = [];
        if (!$vehicle) $errors[] = 'Add your vehicle on your profile first.';
        if ($data['origin_city_id'] === $data['dest_city_id']) $errors[] = 'Departure and destination must be different cities.';
        elseif ((int)db()->value('SELECT COUNT(*) FROM cities WHERE id IN (?, ?)', [$data['origin_city_id'], $data['dest_city_id']]) !== 2) $errors[] = 'Choose cities from the list.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['travel_date']) || $data['travel_date'] < date('Y-m-d')) $errors[] = 'Pick a travel date from today onwards.';
        if ($data['window_end'] <= $data['window_start']) $errors[] = 'Departure window end must be after its start.';
        if (!isset(Matching::SIZES[$data['max_size']])) $errors[] = 'Choose the biggest package size you can take.';
        if ($vehicle && Matching::SIZES[$data['max_size']] > Matching::SIZES[$vehicle['max_size']]) $errors[] = 'Your vehicle cannot take packages that size.';
        if (!$cats) $errors[] = 'Choose at least one category you accept.';
        if ($price < (int)config('min_price_cents')) $errors[] = 'Minimum price is ' . money((int)config('min_price_cents')) . '.';
        if ($price > 50000) $errors[] = 'That price looks too high.';
        if ($errors) {
            $_SESSION['old'] = $_POST;
            foreach ($errors as $e) flash('error', $e);
            redirect('/driver/routes/new');
        }
        $id = db()->insert('routes', $data);
        flash('success', 'Route published! Here are packages that fit.');
        redirect("/driver/routes/$id");
    }

    public function showRoute(string $id): void
    {
        $u = Auth::require('driver');
        $svc = BookingService::make();
        $route = $svc->route((int)$id);
        if (!$route || (int)$route['driver_id'] !== (int)$u['id']) abort(404, 'Route not found.');
        view('driver/route', [
            'route' => $route,
            'matches' => $route['status'] === 'active' ? $svc->matchesForRoute($route) : [],
            'split' => Pricing::fromConfig()->split((int)$route['price_cents']),
            'bookings' => db()->all("SELECT b.*, p.description, pc.name AS pickup_city, dc.name AS delivery_city FROM bookings b
                JOIN packages p ON p.id = b.package_id JOIN cities pc ON pc.id = p.pickup_city_id JOIN cities dc ON dc.id = p.delivery_city_id
                WHERE b.route_id = ? AND b.status != 'cancelled' ORDER BY b.id DESC", [$route['id']]),
        ]);
    }

    public function offer(string $id): void
    {
        $u = Auth::require('driver');
        $this->mustBeVerified($u);
        BookingService::make()->offer((int)input('package_id'), (int)$id, (int)$u['id']);
        flash('success', 'Offer sent. The sender gets to accept and pay.');
        redirect("/driver/routes/$id");
    }

    public function cancelRoute(string $id): void
    {
        $u = Auth::require('driver');
        $active = db()->value("SELECT COUNT(*) FROM bookings WHERE route_id = ? AND status IN ('awaiting_payment','paid','picked_up','at_point')", [(int)$id]);
        if ($active) {
            flash('error', 'This route has active bookings. Cancel those first so senders are refunded.');
            redirect("/driver/routes/$id");
        }
        db()->run("UPDATE routes SET status = 'cancelled' WHERE id = ? AND driver_id = ?", [(int)$id, $u['id']]);
        db()->run("UPDATE bookings SET status = 'cancelled', cancelled_at = ? WHERE route_id = ? AND status = 'offered'", [now_utc(), (int)$id]);
        flash('success', 'Route cancelled.');
        redirect('/driver');
    }

    public function profile(): void
    {
        $u = Auth::require('driver');
        view('driver/profile', [
            'user' => $u,
            'vehicle' => db()->one('SELECT * FROM vehicles WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$u['id']]),
            'gateway' => config('payment_gateway'),
        ]);
    }

    public function saveProfile(): void
    {
        $u = Auth::require('driver');
        $langs = array_intersect((array)($_POST['languages'] ?? []), ['NL', 'EN', 'DE', 'FR', 'PL', 'TR', 'AR', 'ES']);
        db()->update('users', (int)$u['id'], [
            'bio' => mb_substr((string)input('bio'), 0, 500),
            'languages' => implode(',', $langs),
        ]);
        $v = [
            'make_model' => mb_substr((string)input('make_model'), 0, 60),
            'year' => (int)input('year') ?: null,
            'fuel' => in_array(input('fuel'), ['petrol', 'diesel', 'electric', 'hybrid', 'none'], true) ? input('fuel') : 'petrol',
            'kind' => in_array(input('kind'), ['car', 'van', 'cargo_bike', 'bike'], true) ? input('kind') : 'car',
            'max_size' => isset(Matching::SIZES[input('max_size')]) ? input('max_size') : 'medium',
            'max_weight_kg' => max(1, min(1000, (float)input('max_weight_kg'))),
            'plate_masked' => self::maskPlate((string)input('plate')),
        ];
        if ($v['make_model'] === '') { flash('error', 'Tell us what you drive.'); redirect('/driver/profile'); }
        $existing = db()->one('SELECT id FROM vehicles WHERE user_id = ?', [$u['id']]);
        $existing ? db()->update('vehicles', (int)$existing['id'], $v) : db()->insert('vehicles', $v + ['user_id' => $u['id']]);
        flash('success', 'Profile saved.');
        redirect('/driver/profile');
    }

    /**
     * MVP: the driver declares they are ready and an admin checks documents out-of-band.
     * Production: replace with a regulated ID provider (e.g. iDIN, Onfido, Stripe Identity) callback.
     */
    public function submitVerification(): void
    {
        $u = Auth::require('driver');
        if (!in_array($u['verification_status'], ['none', 'rejected'], true)) redirect('/driver/profile');
        if (!db()->value('SELECT id FROM vehicles WHERE user_id = ?', [$u['id']])) { flash('error', 'Add your vehicle first.'); redirect('/driver/profile'); }
        $pro = (bool)input('is_professional');
        $kvk = preg_replace('/\D/', '', (string)input('kvk_number'));
        if ($pro && strlen($kvk) !== 8) { flash('error', 'A KvK number has 8 digits.'); redirect('/driver/profile'); }
        db()->update('users', (int)$u['id'], [
            'verification_status' => 'pending',
            'is_professional' => $pro ? 1 : 0,
            'kvk_number' => $pro ? $kvk : null,
            'verification_note' => $pro ? 'Professional transporter, KvK ' . $kvk : 'Private driver: ID + driving licence',
        ]);
        flash('success', 'Submitted! We usually verify within one working day.');
        redirect('/driver');
    }

    public function payoutOnboarding(): void
    {
        $u = Auth::require('driver');
        $result = BookingService::make()->gateway()->onboardDriver($u, rtrim((string)config('base_url'), '/') . '/driver/profile');
        db()->update('users', (int)$u['id'], ['stripe_account_id' => $result['account_id']]);
        if ($result['url']) { header('Location: ' . $result['url'], true, 303); exit; }
        flash('success', 'Payout account connected (demo).');
        redirect('/driver/profile');
    }

    private function mustBeVerified(array $u): void
    {
        if ($u['verification_status'] !== 'verified') {
            flash('error', 'You can publish routes once your identity is verified.');
            redirect('/driver/profile');
        }
    }

    private static function maskPlate(string $plate): ?string
    {
        $p = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $plate));
        if ($p === '') return null;
        $parts = explode('-', $p);
        if (count($parts) === 3) return $parts[0] . '-**-' . $parts[2];
        return substr($p, 0, 2) . str_repeat('*', max(0, strlen($p) - 4)) . substr($p, -2);
    }
}
