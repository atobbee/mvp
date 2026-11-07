<?php
declare(strict_types=1);

namespace Atobbee\Http;

use Atobbee\Domain\{BookingService, Matching, Pricing};

final class PageController
{
    public function home(): void
    {
        $svc = BookingService::make();
        $routes = db()->all("SELECT r.id FROM routes r JOIN users u ON u.id = r.driver_id
            WHERE r.status = 'active' AND r.travel_date >= ? AND u.verification_status = 'verified'
            ORDER BY r.travel_date, r.window_start LIMIT 6", [date('Y-m-d')]);
        $routes = array_map(fn($r) => $svc->route((int)$r['id']), $routes);
        view('home', ['routes' => $routes, 'cities' => self::cities(), 'pricing' => Pricing::fromConfig()]);
    }

    public function trust(): void
    {
        view('trust');
    }

    public function driverProfile(string $id): void
    {
        $driver = db()->one("SELECT * FROM users WHERE id = ? AND role = 'driver' AND verification_status = 'verified'", [(int)$id]) ?? abort(404, 'Driver not found.');
        $svc = BookingService::make();
        view('driver_profile', [
            'driver' => $driver,
            'vehicle' => db()->one('SELECT * FROM vehicles WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$driver['id']]),
            'stats' => $svc->driverStats((int)$driver['id']),
            'reviews' => db()->all("SELECT rv.*, u.first_name FROM reviews rv JOIN users u ON u.id = rv.reviewer_id
                JOIN bookings b ON b.id = rv.booking_id
                WHERE rv.reviewee_id = ? AND rv.hidden = 0 AND b.status = 'completed' ORDER BY rv.id DESC LIMIT 10", [$driver['id']]),
        ]);
    }

    public function priceSuggestions(): void
    {
        header('Content-Type: application/json');
        $from = db()->one('SELECT * FROM cities WHERE id = ?', [(int)input('from')]);
        $to = db()->one('SELECT * FROM cities WHERE id = ?', [(int)input('to')]);
        if (!$from || !$to) { echo json_encode(['error' => 'unknown city']); return; }
        $km = Matching::fromConfig()->roadKm((float)$from['lat'], (float)$from['lng'], (float)$to['lat'], (float)$to['lng']);
        echo json_encode(['km' => round($km)] + Pricing::fromConfig()->suggestions($km));
    }

    public static function cities(): array
    {
        return db()->all('SELECT * FROM cities ORDER BY name');
    }
}
