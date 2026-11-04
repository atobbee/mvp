<?php
declare(strict_types=1);

namespace AtoBbee\Domain;

use AtoBbee\Db;
use AtoBbee\Payments\FakeGateway;
use AtoBbee\Payments\Gateway;
use AtoBbee\Payments\StripeGateway;
use DomainException;

/** All booking state changes live here, so controllers stay thin and rules stay in one place. */
final class BookingService
{
    public const REQUIREMENTS = [
        'pin'            => 'Recipient PIN confirmation',
        'qr'             => 'QR-code confirmation',
        'signature'      => 'Signature required',
        'photo'          => 'Photo proof of delivery',
        'recipient_only' => 'Recipient-only delivery (no neighbours)',
        'id_check'       => 'ID check at delivery',
        'age_check'      => 'Minimum-age verification (18+)',
        'fragile'        => 'Fragile — handle with care',
        'upright'        => 'Keep upright',
    ];

    /** Requirements the driver must explicitly tick at delivery. */
    public const DELIVERY_CONFIRMATIONS = ['recipient_only', 'id_check', 'age_check'];

    public const CATEGORIES = [
        'food' => 'Food & groceries', 'artisan' => 'Artisan products', 'documents' => 'Documents',
        'electronics' => 'Electronics', 'clothing' => 'Clothing', 'fragile' => 'Fragile items', 'other' => 'Other',
    ];

    public function __construct(
        private Db $db,
        private Gateway $gateway,
        private Pricing $pricing,
        private Matching $matching,
    ) {}

    public static function make(): self
    {
        $gateway = config('payment_gateway') === 'stripe'
            ? new StripeGateway((string)config('stripe_secret_key'))
            : new FakeGateway();
        return new self(db(), $gateway, Pricing::fromConfig(), Matching::fromConfig());
    }

    public function gateway(): Gateway { return $this->gateway; }

    // ── Queries ──────────────────────────────────────────────────────────────

    private const ROUTE_SQL = "SELECT r.*, o.name AS origin, o.lat AS o_lat, o.lng AS o_lng,
            d.name AS destination, d.lat AS d_lat, d.lng AS d_lng,
            u.first_name, u.last_name, u.is_professional, u.languages, u.verification_status,
            v.make_model, v.fuel, v.kind AS vehicle_kind
        FROM routes r
        JOIN cities o ON o.id = r.origin_city_id
        JOIN cities d ON d.id = r.dest_city_id
        JOIN users u ON u.id = r.driver_id
        LEFT JOIN vehicles v ON v.id = r.vehicle_id";

    private const PACKAGE_SQL = "SELECT p.*, pc.name AS pickup_city, pc.lat AS p_lat, pc.lng AS p_lng,
            dc.name AS delivery_city, dc.lat AS x_lat, dc.lng AS x_lng, pp.name AS pickup_point_name
        FROM packages p
        JOIN cities pc ON pc.id = p.pickup_city_id
        JOIN cities dc ON dc.id = p.delivery_city_id
        LEFT JOIN pickup_points pp ON pp.id = p.pickup_point_id";

    public function route(int $id): ?array
    {
        $r = $this->db->one(self::ROUTE_SQL . ' WHERE r.id = ?', [$id]);
        if ($r) $r['capacity_left'] = $this->capacityLeft($r);
        return $r;
    }

    public function package(int $id): ?array
    {
        $p = $this->db->one(self::PACKAGE_SQL . ' WHERE p.id = ?', [$id]);
        if ($p) $p['requirements'] = json_decode($p['requirements'], true) ?: [];
        return $p;
    }

    public function capacityLeft(array $route): int
    {
        $marks = implode(',', array_fill(0, count(BookingStatus::ACTIVE), '?'));
        $used = (int)$this->db->value("SELECT COUNT(*) FROM bookings WHERE route_id = ? AND status IN ($marks)",
            array_merge([$route['id']], BookingStatus::ACTIVE));
        return (int)$route['capacity'] - $used;
    }

    /** Driver reputation from completed deliveries only. */
    public function driverStats(int $driverId): array
    {
        $row = $this->db->one("SELECT COUNT(*) AS n, AVG(rv.overall) AS rating FROM reviews rv
            JOIN bookings b ON b.id = rv.booking_id
            WHERE rv.reviewee_id = ? AND rv.hidden = 0 AND b.status = 'completed'", [$driverId]);
        $completed = (int)$this->db->value("SELECT COUNT(*) FROM bookings WHERE driver_id = ? AND status = 'completed'", [$driverId]);
        $cancelledByDriver = (int)$this->db->value("SELECT COUNT(*) FROM booking_events e JOIN bookings b ON b.id = e.booking_id
            WHERE b.driver_id = ? AND e.type = 'cancelled' AND e.actor_id = ?", [$driverId, $driverId]);
        $denominator = $completed + $cancelledByDriver;
        return [
            'deliveries'  => $completed,
            'rating'      => $row['n'] ? round((float)$row['rating'], 1) : null,
            'reviews'     => (int)$row['n'],
            'reliability' => $denominator ? (int)round(100 * $completed / $denominator) : null,
        ];
    }

    /** Compatible routes for a package, best first. */
    public function matchesForPackage(array $package): array
    {
        $routes = $this->db->all(self::ROUTE_SQL . " WHERE r.status = 'active' AND r.travel_date = ?
            AND u.verification_status = 'verified' AND r.driver_id != ?", [$package['pickup_date'], $package['sender_id']]);
        $out = [];
        foreach ($routes as $r) {
            $r['capacity_left'] = $this->capacityLeft($r);
            $eval = $this->matching->evaluate($r, $package);
            if (!$eval['ok']) continue;
            $out[] = $r + ['match' => $eval, 'split' => $this->pricing->split((int)$r['price_cents']), 'stats' => $this->driverStats((int)$r['driver_id'])];
        }
        usort($out, fn($a, $b) => $b['match']['score'] <=> $a['match']['score']);
        return $out;
    }

    /** Open packages a driver could carry on this route, best first. */
    public function matchesForRoute(array $route): array
    {
        $packages = $this->db->all(self::PACKAGE_SQL . " WHERE p.status = 'open' AND p.pickup_date = ? AND p.sender_id != ?",
            [$route['travel_date'], $route['driver_id']]);
        $out = [];
        foreach ($packages as $p) {
            $p['requirements'] = json_decode($p['requirements'], true) ?: [];
            $eval = $this->matching->evaluate($route, $p);
            if (!$eval['ok']) continue;
            $already = $this->db->value("SELECT id FROM bookings WHERE package_id = ? AND route_id = ? AND status NOT IN ('cancelled','refunded')", [$p['id'], $route['id']]);
            $out[] = $p + ['match' => $eval, 'already_offered' => (bool)$already];
        }
        usort($out, fn($a, $b) => $b['match']['score'] <=> $a['match']['score']);
        return $out;
    }

    public function booking(int $id): ?array
    {
        $b = $this->db->one("SELECT b.*,
                s.first_name AS sender_first, s.last_name AS sender_last, s.email AS sender_email,
                d.first_name AS driver_first, d.last_name AS driver_last, d.bio AS driver_bio, d.languages AS driver_languages,
                d.stripe_account_id AS driver_stripe_account
            FROM bookings b JOIN users s ON s.id = b.sender_id JOIN users d ON d.id = b.driver_id WHERE b.id = ?", [$id]);
        if (!$b) return null;
        $b['package'] = $this->package((int)$b['package_id']);
        $b['route'] = $this->route((int)$b['route_id']);
        $b['confirmations'] = json_decode($b['confirmations'], true) ?: [];
        $b['events'] = $this->db->all('SELECT * FROM booking_events WHERE booking_id = ? ORDER BY id', [$id]);
        $b['dispute'] = $this->db->one("SELECT * FROM disputes WHERE booking_id = ? ORDER BY id DESC LIMIT 1", [$id]);
        return $b;
    }

    public function bookingByRef(string $ref): ?array
    {
        $id = $this->db->value('SELECT id FROM bookings WHERE ref = ?', [strtoupper(trim($ref))]);
        return $id ? $this->booking((int)$id) : null;
    }

    // ── Commands ─────────────────────────────────────────────────────────────

    /** Sender picks a driver's route for their package. */
    public function book(int $packageId, int $routeId, int $senderId): int
    {
        return $this->create($packageId, $routeId, BookingStatus::AWAITING_PAYMENT, $senderId, 'booked', 'Sender chose this driver');
    }

    /** Driver offers to carry an open package on one of their routes. */
    public function offer(int $packageId, int $routeId, int $driverId): int
    {
        return $this->create($packageId, $routeId, BookingStatus::OFFERED, $driverId, 'offered', 'Driver offered to carry this package');
    }

    private function create(int $packageId, int $routeId, string $status, int $actorId, string $event, string $note): int
    {
        return $this->db->transaction(function () use ($packageId, $routeId, $status, $actorId, $event, $note) {
            $package = $this->package($packageId) ?? throw new DomainException('Package not found.');
            $route = $this->route($routeId) ?? throw new DomainException('Route not found.');

            if ($package['status'] !== 'open') throw new DomainException('This package is no longer open for booking.');
            if ($route['status'] !== 'active') throw new DomainException('This route is no longer available.');
            if ($route['verification_status'] !== 'verified') throw new DomainException('This driver is not verified yet.');
            if ($status === BookingStatus::AWAITING_PAYMENT && (int)$package['sender_id'] !== $actorId) throw new DomainException('Not your package.');
            if ($status === BookingStatus::OFFERED && (int)$route['driver_id'] !== $actorId) throw new DomainException('Not your route.');

            $eval = $this->matching->evaluate($route, $package);
            if (!$eval['ok']) throw new DomainException('Route no longer matches: ' . implode(', ', $eval['reasons']) . '.');

            $dupe = $this->db->value("SELECT id FROM bookings WHERE package_id = ? AND route_id = ? AND status NOT IN ('cancelled','refunded')", [$packageId, $routeId]);
            if ($dupe) return (int)$dupe;

            $split = $this->pricing->split((int)$route['price_cents']);
            $id = $this->db->insert('bookings', [
                'ref' => 'TMP-' . bin2hex(random_bytes(8)),
                'package_id' => $packageId, 'route_id' => $routeId,
                'sender_id' => $package['sender_id'], 'driver_id' => $route['driver_id'],
                'price_cents' => $split['price'], 'commission_cents' => $split['commission'], 'driver_cents' => $split['driver'],
                'compatibility' => $eval['score'], 'status' => $status,
                'pickup_pin' => self::pin(), 'delivery_pin' => self::pin(),
            ]);
            $this->db->update('bookings', $id, ['ref' => sprintf('ATB-%s-%05d', date('Y'), $id)]);
            $this->event($id, $event, $note, $actorId);
            return $id;
        });
    }

    public function acceptOffer(int $bookingId, int $senderId): void
    {
        $b = $this->mustOwn($bookingId, 'sender_id', $senderId);
        $this->transition($b, BookingStatus::AWAITING_PAYMENT, $senderId, 'offer_accepted', 'Sender accepted the offer');
    }

    /** @return ?string redirect URL for hosted checkout, or null if paid immediately */
    public function startPayment(int $bookingId, int $senderId, string $successUrl, string $cancelUrl): ?string
    {
        $b = $this->mustOwn($bookingId, 'sender_id', $senderId);
        if ($b['status'] !== BookingStatus::AWAITING_PAYMENT) throw new DomainException('This booking is not awaiting payment.');
        if ($this->capacityLeft($b['route']) < 0) throw new DomainException('The driver has no capacity left on this route.');
        if ($b['package']['status'] !== 'open') throw new DomainException('This package has already been booked with another driver.');

        $result = $this->gateway->startPayment($b, $successUrl, $cancelUrl);
        $this->db->update('bookings', $bookingId, ['payment_ref' => $result['reference']]);
        $this->log($bookingId, 'charge_started', $result['reference'], $b['price_cents']);
        if ($result['paid']) $this->markPaid($bookingId, $result['reference']);
        return $result['redirect'];
    }

    /** Called when the gateway confirms payment (immediately for fake, via webhook for Stripe). */
    public function markPaid(int $bookingId, string $reference): void
    {
        $this->db->transaction(function () use ($bookingId, $reference) {
            $b = $this->booking($bookingId) ?? throw new DomainException('Booking not found.');
            if ($b['status'] === BookingStatus::PAID) return; // webhook retries are fine
            $this->transition($b, BookingStatus::PAID, null, 'paid', money((int)$b['price_cents']) . ' held until delivery is confirmed');
            $this->db->update('bookings', $bookingId, ['paid_at' => now_utc(), 'payment_ref' => $reference]);
            $this->db->update('packages', (int)$b['package_id'], ['status' => 'booked']);
            // Any other offers or unpaid bookings for the same package are void now.
            foreach ($this->db->all("SELECT id FROM bookings WHERE package_id = ? AND id != ? AND status IN ('offered','awaiting_payment')", [$b['package_id'], $bookingId]) as $other) {
                $this->db->update('bookings', (int)$other['id'], ['status' => BookingStatus::CANCELLED, 'cancelled_at' => now_utc()]);
                $this->event((int)$other['id'], 'cancelled', 'Sender booked another driver', null);
            }
            $this->log($bookingId, 'charge', $reference, (int)$b['price_cents']);
        });
    }

    public function confirmPickup(int $bookingId, int $driverId, string $pin): void
    {
        $b = $this->mustOwn($bookingId, 'driver_id', $driverId);
        if ($b['status'] !== BookingStatus::PAID) throw new DomainException('This booking is not ready for pickup.');
        $this->checkPin($b, 'pickup_pin', $pin);
        $this->transition($b, BookingStatus::PICKED_UP, $driverId, 'picked_up', 'Pickup PIN confirmed');
        $this->db->update('bookings', $bookingId, ['picked_up_at' => now_utc()]);
    }

    /**
     * Driver hands over to the recipient at the delivery address.
     * @param array $confirmations requirement keys the driver ticked
     */
    public function confirmDelivery(int $bookingId, int $driverId, string $pin, array $confirmations, ?string $photoFile, ?string $signatureFile): void
    {
        $b = $this->mustOwn($bookingId, 'driver_id', $driverId);
        if ($b['status'] !== BookingStatus::PICKED_UP) throw new DomainException('This package has not been picked up yet.');
        if ($b['package']['pickup_point_id']) throw new DomainException('This package goes to a pickup point — hand it over there.');

        $req = $b['package']['requirements'];
        foreach (self::DELIVERY_CONFIRMATIONS as $key) {
            if (in_array($key, $req, true) && !in_array($key, $confirmations, true)) {
                throw new DomainException('Please confirm: ' . self::REQUIREMENTS[$key] . '.');
            }
        }
        if (in_array('photo', $req, true) && !$photoFile) throw new DomainException('A delivery photo is required for this package.');
        if (in_array('signature', $req, true) && !$signatureFile) throw new DomainException('The recipient needs to sign for this package.');

        $this->checkPin($b, 'delivery_pin', $pin);
        $this->deliver($b, $driverId, 'Delivered to recipient, PIN confirmed', [
            'confirmations' => json_encode(array_values(array_intersect($confirmations, self::DELIVERY_CONFIRMATIONS))),
            'proof_photo' => $photoFile,
            'signature_file' => $signatureFile,
        ]);
    }

    /** Pickup point receives the package from the driver (scans or types the booking ref). */
    public function receiveAtPoint(int $bookingId, int $businessUserId): void
    {
        $b = $this->booking($bookingId) ?? throw new DomainException('Booking not found.');
        $point = $this->pointOf($businessUserId);
        if ((int)$b['package']['pickup_point_id'] !== (int)$point['id']) throw new DomainException('This package is not addressed to your pickup point.');
        if ($b['status'] !== BookingStatus::PICKED_UP) throw new DomainException('This package is not on its way to you yet.');
        $this->transition($b, BookingStatus::AT_POINT, $businessUserId, 'at_point', 'Received at ' . $point['name']);
    }

    /** Recipient collects from the pickup point by showing their PIN. */
    public function collectAtPoint(int $bookingId, int $businessUserId, string $pin): void
    {
        $b = $this->booking($bookingId) ?? throw new DomainException('Booking not found.');
        $point = $this->pointOf($businessUserId);
        if ((int)$b['package']['pickup_point_id'] !== (int)$point['id']) throw new DomainException('This package is not at your pickup point.');
        if ($b['status'] !== BookingStatus::AT_POINT) throw new DomainException('This package is not waiting for collection.');
        $this->checkPin($b, 'delivery_pin', $pin);
        $this->deliver($b, $businessUserId, 'Collected by recipient at ' . $point['name'], []);
    }

    private function deliver(array $b, int $actorId, string $note, array $extra): void
    {
        $hours = (int)config('dispute_window_hours', 24);
        $this->transition($b, BookingStatus::DELIVERED, $actorId, 'delivered', $note);
        $this->db->update('bookings', (int)$b['id'], array_filter($extra, fn($v) => $v !== null) + [
            'delivered_at' => now_utc(),
            'payout_due_at' => now_utc("+{$hours} hours"),
        ]);
    }

    public function cancel(int $bookingId, int $actorId): void
    {
        $b = $this->booking($bookingId) ?? throw new DomainException('Booking not found.');
        if (!in_array($actorId, [(int)$b['sender_id'], (int)$b['driver_id']], true)) throw new DomainException('Not your booking.');
        if (!BookingStatus::canTransition($b['status'], BookingStatus::CANCELLED)) {
            throw new DomainException('This booking can no longer be cancelled. Report a problem instead.');
        }
        $this->db->transaction(function () use ($b, $actorId) {
            $wasPaid = $b['status'] === BookingStatus::PAID;
            $who = $actorId === (int)$b['driver_id'] ? 'driver' : 'sender';
            $this->transition($b, BookingStatus::CANCELLED, $actorId, 'cancelled', "Cancelled by $who" . ($wasPaid ? ', full refund issued' : ''));
            $this->db->update('bookings', (int)$b['id'], ['cancelled_at' => now_utc()]);
            if ($wasPaid) {
                $ref = $this->gateway->refund($b);
                $this->log((int)$b['id'], 'refund', $ref, (int)$b['price_cents']);
                $this->db->update('packages', (int)$b['package_id'], ['status' => 'open']); // sender can rebook
            }
        });
    }

    public function openDispute(int $bookingId, int $senderId, string $reason, string $details): void
    {
        $b = $this->mustOwn($bookingId, 'sender_id', $senderId);
        if (!BookingStatus::canTransition($b['status'], BookingStatus::DISPUTED)) throw new DomainException('You cannot report a problem on this booking right now.');
        if ($b['status'] === BookingStatus::DELIVERED && $b['payout_due_at'] < now_utc()) throw new DomainException('The 24-hour dispute window has closed.');
        if (mb_strlen($details) < 10) throw new DomainException('Please describe what went wrong.');
        $this->db->transaction(function () use ($b, $senderId, $reason, $details) {
            $this->db->insert('disputes', ['booking_id' => $b['id'], 'opened_by' => $senderId, 'reason' => $reason, 'details' => $details]);
            $this->transition($b, BookingStatus::DISPUTED, $senderId, 'disputed', "Problem reported: $reason. Payout on hold.");
        });
    }

    /** @param string $outcome 'driver' (release payout) or 'refund' */
    public function resolveDispute(int $disputeId, int $adminId, string $outcome, string $note): void
    {
        $d = $this->db->one('SELECT * FROM disputes WHERE id = ?', [$disputeId]) ?? throw new DomainException('Dispute not found.');
        if ($d['status'] !== 'open') throw new DomainException('Already resolved.');
        $b = $this->booking((int)$d['booking_id']);
        $this->db->transaction(function () use ($d, $b, $adminId, $outcome, $note) {
            $this->db->update('disputes', (int)$d['id'], [
                'status' => $outcome === 'refund' ? 'resolved_refund' : 'resolved_driver',
                'resolution' => $note, 'resolved_by' => $adminId, 'resolved_at' => now_utc(),
            ]);
            if ($outcome === 'refund') {
                $ref = $this->gateway->refund($b);
                $this->log((int)$b['id'], 'refund', $ref, (int)$b['price_cents']);
                $this->transition($b, BookingStatus::REFUNDED, $adminId, 'refunded', 'Sender refunded after review: ' . $note);
                $this->db->update('packages', (int)$b['package_id'], ['status' => 'cancelled']);
            } else {
                $this->releasePayout($b, $adminId, 'Payout released after review: ' . $note);
            }
        });
    }

    /** Cron: pay drivers whose dispute window passed without a problem report. */
    public function releaseDuePayouts(): array
    {
        $done = [];
        $due = $this->db->all("SELECT id FROM bookings WHERE status = 'delivered' AND payout_due_at <= ?", [now_utc()]);
        foreach ($due as $row) {
            try {
                $b = $this->booking((int)$row['id']);
                $this->db->transaction(fn() => $this->releasePayout($b, null, 'Dispute window closed, payout released'));
                $done[] = $b['ref'];
            } catch (\Throwable $e) {
                error_log("Payout failed for booking {$row['id']}: " . $e->getMessage());
            }
        }
        return $done;
    }

    /** Cron: unpaid bookings older than 30 minutes give their capacity back. */
    public function expireUnpaid(int $minutes = 30): int
    {
        $stale = $this->db->all("SELECT b.id FROM bookings b WHERE b.status = 'awaiting_payment'
            AND (SELECT MAX(e.created_at) FROM booking_events e WHERE e.booking_id = b.id) <= ?", [now_utc("-{$minutes} minutes")]);
        foreach ($stale as $row) {
            $b = $this->booking((int)$row['id']);
            $this->transition($b, BookingStatus::CANCELLED, null, 'cancelled', 'Payment not completed in time');
            $this->db->update('bookings', (int)$b['id'], ['cancelled_at' => now_utc()]);
        }
        return count($stale);
    }

    private function releasePayout(array $b, ?int $actorId, string $note): void
    {
        $ref = $this->gateway->payout($b, $b['driver_stripe_account']);
        $this->log((int)$b['id'], 'transfer', $ref, (int)$b['driver_cents']);
        $this->transition($b, BookingStatus::COMPLETED, $actorId, 'completed', $note . ' (' . money((int)$b['driver_cents']) . ')');
        $this->db->update('bookings', (int)$b['id'], ['paid_out_at' => now_utc(), 'transfer_ref' => $ref]);
        $this->db->update('packages', (int)$b['package_id'], ['status' => 'completed']);
    }

    public function review(int $bookingId, int $reviewerId, array $scores, string $comment): void
    {
        $b = $this->booking($bookingId) ?? throw new DomainException('Booking not found.');
        if (!in_array($b['status'], [BookingStatus::DELIVERED, BookingStatus::COMPLETED], true)) throw new DomainException('You can review once the delivery is done.');
        $reviewee = match ($reviewerId) {
            (int)$b['sender_id'] => (int)$b['driver_id'],
            (int)$b['driver_id'] => (int)$b['sender_id'],
            default => throw new DomainException('Not your booking.'),
        };
        $clean = [];
        foreach (['communication', 'punctuality', 'care', 'accuracy', 'overall'] as $k) {
            $v = (int)($scores[$k] ?? 0);
            if ($v < 1 || $v > 5) throw new DomainException('Please rate every category from 1 to 5.');
            $clean[$k] = $v;
        }
        if ($this->db->value('SELECT id FROM reviews WHERE booking_id = ? AND reviewer_id = ?', [$bookingId, $reviewerId])) {
            throw new DomainException('You already reviewed this delivery.');
        }
        $this->db->insert('reviews', $clean + [
            'booking_id' => $bookingId, 'reviewer_id' => $reviewerId, 'reviewee_id' => $reviewee,
            'comment' => mb_substr($comment, 0, 1000) ?: null,
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    public function transition(array $b, string $to, ?int $actorId, string $event, string $note): void
    {
        if (!BookingStatus::canTransition($b['status'], $to)) {
            throw new DomainException(sprintf('Cannot move booking from "%s" to "%s".', BookingStatus::label($b['status']), BookingStatus::label($to)));
        }
        // Optimistic guard against two requests racing on the same booking.
        $changed = $this->db->run('UPDATE bookings SET status = ? WHERE id = ? AND status = ?', [$to, $b['id'], $b['status']]);
        if ($changed !== 1) throw new DomainException('This booking was just updated by someone else. Refresh and try again.');
        $this->event((int)$b['id'], $event, $note, $actorId);
    }

    private function checkPin(array $b, string $field, string $pin): void
    {
        $max = (int)config('max_pin_failures', 5);
        if ((int)$b['pin_failures'] >= $max) throw new DomainException('Too many wrong PINs. This booking is locked — contact support.');
        if (!hash_equals((string)$b[$field], preg_replace('/\D/', '', $pin))) {
            $this->db->run('UPDATE bookings SET pin_failures = pin_failures + 1 WHERE id = ?', [$b['id']]);
            $this->event((int)$b['id'], 'pin_failed', 'Wrong PIN entered', null);
            $left = $max - (int)$b['pin_failures'] - 1;
            throw new DomainException("That PIN is not right. $left attempt" . ($left === 1 ? '' : 's') . ' left.');
        }
        $this->db->run('UPDATE bookings SET pin_failures = 0 WHERE id = ?', [$b['id']]);
    }

    private function mustOwn(int $bookingId, string $field, int $userId): array
    {
        $b = $this->booking($bookingId) ?? throw new DomainException('Booking not found.');
        if ((int)$b[$field] !== $userId) throw new DomainException('Not your booking.');
        return $b;
    }

    private function pointOf(int $businessUserId): array
    {
        return $this->db->one('SELECT * FROM pickup_points WHERE user_id = ?', [$businessUserId])
            ?? throw new DomainException('You do not have a pickup point.');
    }

    private function event(int $bookingId, string $type, string $note, ?int $actorId): void
    {
        $this->db->insert('booking_events', ['booking_id' => $bookingId, 'type' => $type, 'note' => $note, 'actor_id' => $actorId]);
    }

    private function log(int $bookingId, string $kind, ?string $ref, ?int $amount): void
    {
        $this->db->insert('payment_log', ['booking_id' => $bookingId, 'kind' => $kind, 'gateway' => $this->gateway->name(), 'reference' => $ref, 'amount_cents' => $amount]);
    }

    private static function pin(): string
    {
        return str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
