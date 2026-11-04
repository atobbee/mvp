<?php
declare(strict_types=1);

namespace AtoBbee\Domain;

/**
 * Commission: 8% of the customer price, minimum €1.50. The sender pays the driver's
 * listed price; the platform fee comes out of it (driver gets price − commission).
 */
final class Pricing
{
    public function __construct(
        private float $rate = 0.08,
        private int $minCommissionCents = 150,
        private int $minPriceCents = 500,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (float)config('commission_rate', 0.08),
            (int)config('commission_min_cents', 150),
            (int)config('min_price_cents', 500),
        );
    }

    /** @return array{price:int, commission:int, driver:int} */
    public function split(int $priceCents): array
    {
        if ($priceCents < $this->minPriceCents) {
            throw new \InvalidArgumentException('Price is below the minimum of €' . number_format($this->minPriceCents / 100, 2));
        }
        // Round half up to the cent, then apply the floor.
        $commission = max((int)round($priceCents * $this->rate), $this->minCommissionCents);
        return ['price' => $priceCents, 'commission' => $commission, 'driver' => $priceCents - $commission];
    }

    /**
     * Price suggestions shown to drivers when publishing a route. Estimates only.
     * cost recovery ≈ €0.21/km (fuel/energy + wear), fair adds time, priority on top.
     * @return array{cost:int, fair:int, priority:int}
     */
    public function suggestions(float $roadKm): array
    {
        $cost = max($this->minPriceCents, (int)(round($roadKm * 0.21) * 100));
        $fair = (int)(round($cost * 1.6 / 100) * 100);
        $priority = (int)(round($fair * 1.45 / 100) * 100);
        return ['cost' => $cost, 'fair' => $fair, 'priority' => $priority];
    }
}
