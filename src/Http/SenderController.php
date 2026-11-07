<?php
declare(strict_types=1);

namespace Atobbee\Http;

use Atobbee\Auth;
use Atobbee\Domain\{BookingService, Matching};

final class SenderController
{
    public function dashboard(): void
    {
        $u = Auth::require('sender');
        $bookings = db()->all("SELECT b.*, pc.name AS pickup_city, dc.name AS delivery_city, d.first_name AS driver_first, d.last_name AS driver_last,
                (SELECT COUNT(*) FROM reviews rv WHERE rv.booking_id = b.id AND rv.reviewer_id = b.sender_id) AS reviewed
            FROM bookings b JOIN packages p ON p.id = b.package_id
            JOIN cities pc ON pc.id = p.pickup_city_id JOIN cities dc ON dc.id = p.delivery_city_id
            JOIN users d ON d.id = b.driver_id
            WHERE b.sender_id = ? AND b.status != 'cancelled' ORDER BY b.id DESC", [$u['id']]);
        $open = db()->all("SELECT p.*, pc.name AS pickup_city, dc.name AS delivery_city,
                (SELECT COUNT(*) FROM bookings b WHERE b.package_id = p.id AND b.status = 'offered') AS offers
            FROM packages p JOIN cities pc ON pc.id = p.pickup_city_id JOIN cities dc ON dc.id = p.delivery_city_id
            WHERE p.sender_id = ? AND p.status = 'open' ORDER BY p.pickup_date", [$u['id']]);
        view('sender/dashboard', ['user' => $u, 'bookings' => $bookings, 'open' => $open]);
    }

    public function form(): void
    {
        Auth::require('sender');
        view('sender/send', [
            'cities' => PageController::cities(),
            'points' => db()->all("SELECT pp.*, c.name AS city FROM pickup_points pp JOIN cities c ON c.id = pp.city_id
                JOIN users u ON u.id = pp.user_id
                WHERE pp.paused = 0 AND pp.accepts_pickup = 1 AND pp.subscription_status = 'active' AND u.verification_status = 'verified'
                ORDER BY c.name, pp.name"),
            'prefill' => ['from' => input('from'), 'to' => input('to'), 'date' => input('date'), 'size' => input('size')],
        ]);
    }

    public function create(): void
    {
        $u = Auth::require('sender');
        $in = fn($k) => (string)input($k, '');
        $req = array_values(array_intersect((array)($_POST['requirements'] ?? []), array_keys(BookingService::REQUIREMENTS)));
        if (!in_array('pin', $req, true)) $req[] = 'pin'; // always included

        $data = [
            'sender_id' => $u['id'],
            'pickup_address' => $in('pickup_address'), 'pickup_city_id' => (int)$in('pickup_city_id'),
            'delivery_address' => $in('delivery_address'), 'delivery_city_id' => (int)$in('delivery_city_id'),
            'pickup_point_id' => (int)$in('pickup_point_id') ?: null,
            'recipient_name' => $in('recipient_name'), 'recipient_email' => $in('recipient_email') ?: null,
            'pickup_date' => $in('pickup_date'), 'window_start' => $in('window_start'), 'window_end' => $in('window_end'),
            'size' => $in('size'), 'weight_kg' => (float)str_replace(',', '.', $in('weight_kg')),
            'category' => $in('category'), 'description' => $in('description'),
            'declared_value_cents' => $in('declared_value') !== '' ? (int)round((float)str_replace(',', '.', $in('declared_value')) * 100) : null,
            'requirements' => json_encode($req), 'note_to_driver' => $in('note_to_driver') ?: null,
        ];

        $errors = [];
        if ($data['pickup_point_id']) {
            $point = db()->one("SELECT * FROM pickup_points WHERE id = ? AND paused = 0 AND subscription_status = 'active'", [$data['pickup_point_id']]);
            if (!$point) { $errors[] = 'That pickup point is not available right now.'; }
            else {
                $data['delivery_city_id'] = (int)$point['city_id'];
                $data['delivery_address'] = $point['name'] . ', ' . $point['address'];
                if ((Matching::SIZES[$data['size']] ?? 9) > Matching::SIZES[$point['max_size']]) $errors[] = 'This package is too big for that pickup point.';
                $load = (int)db()->value("SELECT COUNT(*) FROM packages WHERE pickup_point_id = ? AND pickup_date = ? AND status IN ('open','booked')", [$point['id'], $data['pickup_date']]);
                if ($load >= (int)$point['daily_limit']) $errors[] = 'That pickup point is full for this date. Pick another point or date.';
            }
        }

        $cityIds = array_column(db()->all('SELECT id FROM cities'), 'id');
        if ($data['pickup_address'] === '' || $data['delivery_address'] === '') $errors[] = 'Please fill in both addresses.';
        if (!in_array($data['pickup_city_id'], $cityIds) || !in_array($data['delivery_city_id'], $cityIds)) $errors[] = 'Please choose a pickup and delivery city.';
        if ($data['pickup_city_id'] === $data['delivery_city_id'] && !$data['pickup_point_id']) $errors[] = 'Pickup and delivery are in the same city — Atobbee is for trips between cities for now.';
        if ($data['recipient_name'] === '') $errors[] = 'Who is receiving the package?';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['pickup_date']) || $data['pickup_date'] < date('Y-m-d')) $errors[] = 'Pick a date from today onwards.';
        if (!preg_match('/^\d{2}:\d{2}$/', $data['window_start']) || !preg_match('/^\d{2}:\d{2}$/', $data['window_end']) || $data['window_end'] <= $data['window_start']) $errors[] = 'The pickup window needs a start and a later end time.';
        if (!isset(Matching::SIZES[$data['size']])) $errors[] = 'Choose a package size.';
        if ($data['weight_kg'] <= 0 || $data['weight_kg'] > 300) $errors[] = 'Weight must be between 0 and 300 kg.';
        if (!isset(BookingService::CATEGORIES[$data['category']])) $errors[] = 'Choose a category.';
        if (mb_strlen($data['description']) < 3) $errors[] = 'Describe the package in a few words.';
        if (!input('prohibited_ack')) $errors[] = 'Please confirm the package contains no prohibited items.';
        if ($data['recipient_email'] && !filter_var($data['recipient_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Recipient email looks wrong.';

        if ($errors) {
            $_SESSION['old'] = $_POST;
            foreach ($errors as $e) flash('error', $e);
            redirect('/send');
        }
        $id = db()->insert('packages', $data);
        redirect("/packages/$id");
    }

    public function show(string $id): void
    {
        $u = Auth::require('sender');
        $svc = BookingService::make();
        $package = $svc->package((int)$id);
        if (!$package || (int)$package['sender_id'] !== (int)$u['id']) abort(404, 'Package not found.');

        $bookings = db()->all("SELECT b.*, d.first_name AS driver_first, d.last_name AS driver_last
            FROM bookings b JOIN users d ON d.id = b.driver_id WHERE b.package_id = ? AND b.status NOT IN ('cancelled') ORDER BY b.id DESC", [$package['id']]);
        $active = array_values(array_filter($bookings, fn($b) => $b['status'] !== 'offered'));
        if ($active && $package['status'] !== 'open') redirect('/bookings/' . $active[0]['id']);

        $offers = [];
        foreach (array_filter($bookings, fn($b) => $b['status'] === 'offered') as $b) {
            $route = $svc->route((int)$b['route_id']);
            $offers[] = $b + ['route' => $route, 'stats' => $svc->driverStats((int)$b['driver_id'])];
        }
        view('sender/matches', [
            'package' => $package,
            'matches' => $package['status'] === 'open' ? $svc->matchesForPackage($package) : [],
            'offers' => $offers,
            'pending' => array_values(array_filter($bookings, fn($b) => $b['status'] === 'awaiting_payment')),
        ]);
    }

    public function book(string $id): void
    {
        $u = Auth::require('sender');
        $bookingId = BookingService::make()->book((int)$id, (int)input('route_id'), (int)$u['id']);
        redirect("/bookings/$bookingId");
    }

    public function cancel(string $id): void
    {
        $u = Auth::require('sender');
        $n = db()->run("UPDATE packages SET status = 'cancelled' WHERE id = ? AND sender_id = ? AND status = 'open'", [(int)$id, $u['id']]);
        if ($n) {
            db()->run("UPDATE bookings SET status = 'cancelled', cancelled_at = ? WHERE package_id = ? AND status IN ('offered','awaiting_payment')", [now_utc(), (int)$id]);
            flash('success', 'Package request withdrawn.');
        }
        redirect('/dashboard');
    }
}
