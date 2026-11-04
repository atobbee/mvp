<?php
declare(strict_types=1);

namespace AtoBbee\Http;

use AtoBbee\Auth;
use AtoBbee\Domain\BookingService;

final class AdminController
{
    public function dashboard(): void
    {
        Auth::require('admin');
        $count = fn(string $sql, array $p = []) => (int)db()->value($sql, $p);
        view('admin/dashboard', [
            'stats' => [
                'users' => $count('SELECT COUNT(*) FROM users'),
                'open' => $count("SELECT COUNT(*) FROM packages WHERE status = 'open'"),
                'transit' => $count("SELECT COUNT(*) FROM bookings WHERE status IN ('picked_up','at_point')"),
                'completed' => $count("SELECT COUNT(*) FROM bookings WHERE status = 'completed'"),
                'problems' => $count("SELECT COUNT(*) FROM disputes WHERE status = 'open'"),
                'commission' => $count("SELECT COALESCE(SUM(commission_cents),0) FROM bookings WHERE status = 'completed'"),
                'gmv' => $count("SELECT COALESCE(SUM(price_cents),0) FROM bookings WHERE status IN ('paid','picked_up','at_point','delivered','disputed','completed')"),
            ],
            'queue' => db()->all("SELECT u.*, pp.name AS point_name FROM users u LEFT JOIN pickup_points pp ON pp.user_id = u.id
                WHERE u.verification_status = 'pending' ORDER BY u.id"),
            'disputes' => db()->all("SELECT d.*, b.ref, b.price_cents, b.proof_photo, b.id AS booking_id, s.first_name AS sender_first, s.last_name AS sender_last,
                    dr.first_name AS driver_first, dr.last_name AS driver_last
                FROM disputes d JOIN bookings b ON b.id = d.booking_id JOIN users s ON s.id = b.sender_id JOIN users dr ON dr.id = b.driver_id
                WHERE d.status = 'open' ORDER BY d.id"),
            'flagged' => db()->all("SELECT rv.*, b.ref, r1.first_name AS reviewer, r2.first_name AS reviewee FROM reviews rv
                JOIN bookings b ON b.id = rv.booking_id JOIN users r1 ON r1.id = rv.reviewer_id JOIN users r2 ON r2.id = rv.reviewee_id
                WHERE rv.flagged = 1 AND rv.hidden = 0"),
            'activity' => db()->all("SELECT b.*, pc.name AS pickup_city, dc.name AS delivery_city, d.first_name AS driver_first, d.last_name AS driver_last
                FROM bookings b JOIN packages p ON p.id = b.package_id JOIN cities pc ON pc.id = p.pickup_city_id JOIN cities dc ON dc.id = p.delivery_city_id
                JOIN users d ON d.id = b.driver_id ORDER BY b.id DESC LIMIT 25"),
        ]);
    }

    public function verify(string $id): void
    {
        Auth::require('admin');
        $decision = input('decision') === 'approve' ? 'verified' : 'rejected';
        db()->run("UPDATE users SET verification_status = ?, verification_note = COALESCE(?, verification_note) WHERE id = ? AND verification_status = 'pending'",
            [$decision, input('note') ?: null, (int)$id]);
        flash('success', $decision === 'verified' ? 'Account verified.' : 'Verification rejected.');
        redirect('/admin');
    }

    public function resolve(string $id): void
    {
        $admin = Auth::require('admin');
        $note = (string)input('note');
        if (mb_strlen($note) < 5) { flash('error', 'Add a short note explaining the decision.'); redirect('/admin'); }
        BookingService::make()->resolveDispute((int)$id, (int)$admin['id'], input('outcome') === 'refund' ? 'refund' : 'driver', $note);
        flash('success', 'Dispute resolved.');
        redirect('/admin');
    }

    public function moderateReview(string $id): void
    {
        Auth::require('admin');
        input('decision') === 'hide'
            ? db()->run('UPDATE reviews SET hidden = 1 WHERE id = ?', [(int)$id])
            : db()->run('UPDATE reviews SET flagged = 0 WHERE id = ?', [(int)$id]);
        flash('success', 'Review moderated.');
        redirect('/admin');
    }
}
