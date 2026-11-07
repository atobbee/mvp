<?php
declare(strict_types=1);

namespace Atobbee\Http;

use Atobbee\Auth;
use Atobbee\Domain\{BookingService, Matching};

final class PointController
{
    public function dashboard(): void
    {
        $u = Auth::require('business');
        $point = db()->one('SELECT pp.*, c.name AS city FROM pickup_points pp JOIN cities c ON c.id = pp.city_id WHERE pp.user_id = ?', [$u['id']]);
        $packages = [];
        $counts = ['expected' => 0, 'waiting' => 0, 'collected' => 0];
        if ($point) {
            $packages = db()->all("SELECT b.id, b.ref, b.status, b.delivered_at, p.size, p.weight_kg, p.recipient_name, p.pickup_date,
                    r.window_end AS eta, s.first_name AS sender_first, s.last_name AS sender_last
                FROM bookings b JOIN packages p ON p.id = b.package_id JOIN routes r ON r.id = b.route_id JOIN users s ON s.id = b.sender_id
                WHERE p.pickup_point_id = ? AND (b.status IN ('paid','picked_up','at_point') OR (b.status IN ('delivered','completed') AND b.delivered_at >= ?))
                ORDER BY CASE b.status WHEN 'at_point' THEN 0 WHEN 'picked_up' THEN 1 WHEN 'paid' THEN 2 ELSE 3 END, p.pickup_date", [$point['id'], now_utc('-1 day')]);
            foreach ($packages as $p) {
                if (in_array($p['status'], ['paid', 'picked_up'], true)) $counts['expected']++;
                elseif ($p['status'] === 'at_point') $counts['waiting']++;
                else $counts['collected']++;
            }
        }
        view('point/dashboard', ['user' => $u, 'point' => $point, 'packages' => $packages, 'counts' => $counts, 'cities' => PageController::cities()]);
    }

    public function settings(): void
    {
        $u = Auth::require('business');
        $data = [
            'name' => mb_substr((string)input('name'), 0, 80),
            'address' => mb_substr((string)input('address'), 0, 120),
            'city_id' => (int)input('city_id'),
            'opening_hours' => mb_substr((string)input('opening_hours'), 0, 120),
            'daily_limit' => max(1, min(200, (int)input('daily_limit'))),
            'max_size' => isset(Matching::SIZES[input('max_size')]) ? input('max_size') : 'medium',
            'accepts_pickup' => input('accepts_pickup') ? 1 : 0,
            'accepts_dropoff' => input('accepts_dropoff') ? 1 : 0,
            'paused' => input('paused') ? 1 : 0,
        ];
        if ($data['name'] === '' || $data['address'] === '' || !db()->value('SELECT id FROM cities WHERE id = ?', [$data['city_id']])) {
            flash('error', 'Name, address and city are required.');
            redirect('/point');
        }
        $existing = db()->one('SELECT id FROM pickup_points WHERE user_id = ?', [$u['id']]);
        if ($existing) {
            db()->update('pickup_points', (int)$existing['id'], $data);
        } else {
            db()->insert('pickup_points', $data + ['user_id' => $u['id']]);
            $kvk = preg_replace('/\D/', '', (string)input('kvk_number'));
            db()->update('users', (int)$u['id'], ['kvk_number' => $kvk ?: null, 'verification_status' => 'pending',
                'verification_note' => 'Business pickup point' . ($kvk ? ", KvK $kvk" : ', no KvK given')]);
        }
        flash('success', 'Pickup point saved.');
        redirect('/point');
    }

    /** Driver hands over the package: business scans the QR (or types the booking ref). */
    public function receive(): void
    {
        $u = Auth::require('business');
        $svc = BookingService::make();
        $b = $svc->bookingByRef((string)input('ref')) ?? throw new \DomainException('No booking with that reference.');
        $svc->receiveAtPoint((int)$b['id'], (int)$u['id']);
        flash('success', "{$b['ref']} received. Keep it safe for {$b['package']['recipient_name']}.");
        redirect('/point');
    }

    /** Recipient collects: business checks the booking ref and the recipient's PIN. */
    public function collect(): void
    {
        $u = Auth::require('business');
        $svc = BookingService::make();
        $b = $svc->bookingByRef((string)input('ref')) ?? throw new \DomainException('No booking with that reference.');
        $svc->collectAtPoint((int)$b['id'], (int)$u['id'], (string)input('pin'));
        flash('success', "{$b['ref']} handed to the recipient.");
        redirect('/point');
    }

    /**
     * MVP: activates the subscription without charging.
     * Production: Stripe Billing subscription (€0.99/month price) + webhook to keep status in sync.
     */
    public function subscribe(): void
    {
        $u = Auth::require('business');
        db()->run("UPDATE pickup_points SET subscription_status = 'active', subscription_until = ? WHERE user_id = ?", [now_utc('+1 month'), $u['id']]);
        flash('success', 'Atobbee Business activated.' . (config('payment_gateway') === 'fake' ? ' (Demo: no charge.)' : ''));
        redirect('/point');
    }
}
