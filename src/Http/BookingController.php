<?php
declare(strict_types=1);

namespace AtoBbee\Http;

use AtoBbee\Auth;
use AtoBbee\Domain\{BookingService, BookingStatus};

final class BookingController
{
    private function load(string $id, array $u): array
    {
        $b = BookingService::make()->booking((int)$id) ?? abort(404, 'Booking not found.');
        $isPoint = $u['role'] === 'business'
            && db()->value('SELECT id FROM pickup_points WHERE user_id = ?', [$u['id']]) == $b['package']['pickup_point_id'];
        if (!in_array((int)$u['id'], [(int)$b['sender_id'], (int)$b['driver_id']], true) && $u['role'] !== 'admin' && !$isPoint) {
            abort(404, 'Booking not found.');
        }
        return $b;
    }

    public function show(string $id): void
    {
        $u = Auth::require();
        $b = $this->load($id, $u);
        $svc = BookingService::make();
        view('booking/show', [
            'user' => $u,
            'b' => $b,
            'isSender' => (int)$u['id'] === (int)$b['sender_id'],
            'isDriver' => (int)$u['id'] === (int)$b['driver_id'],
            'stats' => $svc->driverStats((int)$b['driver_id']),
            'myReview' => db()->one('SELECT * FROM reviews WHERE booking_id = ? AND reviewer_id = ?', [$b['id'], $u['id']]),
            'point' => $b['package']['pickup_point_id'] ? db()->one('SELECT pp.*, c.name AS city FROM pickup_points pp JOIN cities c ON c.id = pp.city_id WHERE pp.id = ?', [$b['package']['pickup_point_id']]) : null,
        ]);
    }

    public function accept(string $id): void
    {
        $u = Auth::require('sender');
        BookingService::make()->acceptOffer((int)$id, (int)$u['id']);
        redirect("/bookings/$id");
    }

    public function pay(string $id): void
    {
        $u = Auth::require('sender');
        if (!input('terms')) { flash('error', 'Please accept the terms and confirm the package contains no prohibited items.'); redirect("/bookings/$id"); }
        $base = rtrim((string)config('base_url'), '/');
        $redirect = BookingService::make()->startPayment((int)$id, (int)$u['id'], "$base/bookings/$id/paid", "$base/bookings/$id");
        if ($redirect) {
            header('Location: ' . $redirect, true, 303); // Stripe hosted checkout
            exit;
        }
        flash('success', 'Booking confirmed! Share the pickup PIN with your driver at handover.');
        redirect("/bookings/$id");
    }

    /** Stripe sends the sender back here; the webhook is what actually marks the booking paid. */
    public function paidReturn(string $id): void
    {
        Auth::require('sender');
        flash('info', 'Thanks! Your payment is being confirmed — this page updates in a few seconds.');
        redirect("/bookings/$id");
    }

    public function cancel(string $id): void
    {
        $u = Auth::require('sender', 'driver');
        BookingService::make()->cancel((int)$id, (int)$u['id']);
        flash('success', 'Booking cancelled.');
        redirect("/bookings/$id");
    }

    public function pickup(string $id): void
    {
        $u = Auth::require('driver');
        BookingService::make()->confirmPickup((int)$id, (int)$u['id'], (string)input('pin'));
        flash('success', 'Pickup confirmed. Drive safely!');
        redirect("/bookings/$id");
    }

    public function deliver(string $id): void
    {
        $u = Auth::require('driver');
        $photo = $this->storePhoto($_FILES['photo'] ?? null);
        $signature = $this->storeSignature((string)($_POST['signature'] ?? ''));
        BookingService::make()->confirmDelivery((int)$id, (int)$u['id'], (string)input('pin'), (array)($_POST['confirm'] ?? []), $photo, $signature);
        flash('success', 'Delivered! Your payout is released after the 24-hour dispute window.');
        redirect("/bookings/$id");
    }

    public function dispute(string $id): void
    {
        $u = Auth::require('sender');
        $reasons = ['not_delivered' => 'Not delivered', 'damaged' => 'Package damaged', 'late' => 'Very late', 'wrong_recipient' => 'Wrong recipient', 'other' => 'Other'];
        $reason = $reasons[(string)input('reason')] ?? 'Other';
        BookingService::make()->openDispute((int)$id, (int)$u['id'], $reason, (string)input('details'));
        flash('success', 'Problem reported. The driver payout is on hold while our team reviews it.');
        redirect("/bookings/$id");
    }

    public function reviewForm(string $id): void
    {
        $u = Auth::require('sender', 'driver');
        $b = $this->load($id, $u);
        view('booking/review', ['b' => $b, 'isSender' => (int)$u['id'] === (int)$b['sender_id']]);
    }

    public function review(string $id): void
    {
        $u = Auth::require('sender', 'driver');
        BookingService::make()->review((int)$id, (int)$u['id'], (array)($_POST['score'] ?? []), (string)input('comment'));
        flash('success', 'Thanks for your review!');
        redirect(Auth::home($u));
    }

    public function flagReview(string $id): void
    {
        $u = Auth::require();
        db()->run('UPDATE reviews SET flagged = 1 WHERE id = ? AND reviewee_id = ?', [(int)$id, $u['id']]);
        flash('success', 'Thanks — a moderator will look at this review.');
        redirect(back_path(Auth::home($u)));
    }

    /** Serve proof files from outside the webroot, only to people on the booking. */
    public function proof(string $id, string $kind): void
    {
        $u = Auth::require();
        $b = $this->load($id, $u);
        $file = match ($kind) { 'photo' => $b['proof_photo'], 'signature' => $b['signature_file'], default => null };
        $path = $file ? config('upload_dir') . '/' . basename($file) : null;
        if (!$path || !is_file($path)) abort(404, 'No proof file.');
        header('Content-Type: ' . (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
    }

    private function storePhoto(?array $f): ?string
    {
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if ($f['error'] !== UPLOAD_ERR_OK) throw new \DomainException('The photo upload failed, please try again.');
        if ($f['size'] > 8 * 1024 * 1024) throw new \DomainException('Photo is too large (max 8 MB).');
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][(new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])] ?? null;
        if (!$ext) throw new \DomainException('Please upload a JPG, PNG or WebP photo.');
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        @mkdir(config('upload_dir'), 0775, true);
        if (!move_uploaded_file($f['tmp_name'], config('upload_dir') . '/' . $name)) throw new \DomainException('Could not save the photo.');
        return $name;
    }

    private function storeSignature(string $dataUrl): ?string
    {
        if (!str_starts_with($dataUrl, 'data:image/png;base64,')) return null;
        $png = base64_decode(substr($dataUrl, 22), true);
        if ($png === false || strlen($png) < 500 || strlen($png) > 2_000_000 || !str_starts_with($png, "\x89PNG")) return null;
        $name = bin2hex(random_bytes(16)) . '-sig.png';
        @mkdir(config('upload_dir'), 0775, true);
        file_put_contents(config('upload_dir') . '/' . $name, $png);
        return $name;
    }
}
