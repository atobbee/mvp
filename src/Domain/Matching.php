<?php
declare(strict_types=1);

namespace AtoBbee\Domain;

/**
 * Rule-based route compatibility (deliberately not AI).
 *
 * Hard filters (any fails → no match): same date, overlapping time windows, package fits
 * size/weight, category accepted, capacity left, detour within the driver's maximum.
 *
 * Detour = extra road km for origin → pickup → delivery → destination versus origin → destination.
 * Score (0–100) starts at 100 and loses up to 35 points for detour used and up to 15 for a
 * narrow time-window overlap.
 */
final class Matching
{
    public const SIZES = ['envelope' => 1, 'small' => 2, 'medium' => 3, 'large' => 4];

    public function __construct(private float $roadFactor = 1.3) {}

    public static function fromConfig(): self
    {
        return new self((float)config('road_factor', 1.3));
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    public function roadKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return self::haversineKm($lat1, $lng1, $lat2, $lng2) * $this->roadFactor;
    }

    /**
     * @param array $route   keys: o_lat,o_lng,d_lat,d_lng,travel_date,window_start,window_end,max_detour_km,
     *                       max_size,max_weight_kg,categories,capacity_left
     * @param array $package keys: p_lat,p_lng,x_lat,x_lng,pickup_date,window_start,window_end,size,weight_kg,
     *                       category,requirements(array)
     * @return array{ok:bool, score:int, detour_km:float, reasons:string[]}
     */
    public function evaluate(array $route, array $package): array
    {
        $reasons = [];

        if ($route['travel_date'] !== $package['pickup_date']) $reasons[] = 'different date';

        $overlap = self::overlapMinutes($route['window_start'], $route['window_end'], $package['window_start'], $package['window_end']);
        if ($overlap <= 0) $reasons[] = 'time windows do not overlap';

        if ((self::SIZES[$package['size']] ?? 99) > (self::SIZES[$route['max_size']] ?? 0)) $reasons[] = 'package too big';
        if ((float)$package['weight_kg'] > (float)$route['max_weight_kg']) $reasons[] = 'package too heavy';

        $cats = array_filter(explode(',', (string)$route['categories']));
        if ($cats && !in_array($package['category'], $cats, true)) $reasons[] = 'category not accepted';
        if (in_array('fragile', $package['requirements'], true) && $cats && !in_array('fragile', $cats, true)) {
            $reasons[] = 'driver does not take fragile items';
        }
        if (($route['capacity_left'] ?? 1) <= 0) $reasons[] = 'no capacity left';

        $direct = $this->roadKm($route['o_lat'], $route['o_lng'], $route['d_lat'], $route['d_lng']);
        $via = $this->roadKm($route['o_lat'], $route['o_lng'], $package['p_lat'], $package['p_lng'])
             + $this->roadKm($package['p_lat'], $package['p_lng'], $package['x_lat'], $package['x_lng'])
             + $this->roadKm($package['x_lat'], $package['x_lng'], $route['d_lat'], $route['d_lng']);
        $detour = max(0.0, $via - $direct);
        if ($detour > (float)$route['max_detour_km']) $reasons[] = sprintf('detour %.0f km exceeds %d km', $detour, $route['max_detour_km']);

        if ($reasons) return ['ok' => false, 'score' => 0, 'detour_km' => round($detour, 1), 'reasons' => $reasons];

        $pkgWindow = max(1, self::minutes($package['window_end']) - self::minutes($package['window_start']));
        $overlapRatio = min(1.0, $overlap / $pkgWindow);
        $detourRatio = $route['max_detour_km'] > 0 ? $detour / (float)$route['max_detour_km'] : 0.0;

        $score = 100 - 35 * $detourRatio - 15 * (1 - $overlapRatio);
        return ['ok' => true, 'score' => (int)max(50, min(100, round($score))), 'detour_km' => round($detour, 1), 'reasons' => []];
    }

    public static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);
        return $h * 60 + $m;
    }

    public static function overlapMinutes(string $aStart, string $aEnd, string $bStart, string $bEnd): int
    {
        return min(self::minutes($aEnd), self::minutes($bEnd)) - max(self::minutes($aStart), self::minutes($bStart));
    }
}
