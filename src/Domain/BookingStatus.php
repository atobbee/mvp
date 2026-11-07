<?php
declare(strict_types=1);

namespace Atobbee\Domain;

/**
 * Booking lifecycle. Every status change goes through BookingService::transition(),
 * which checks this table, so a booking can never jump states.
 *
 *  offered ──accept──▶ awaiting_payment ──pay──▶ paid ──pickup PIN──▶ picked_up
 *                                                              │
 *                       ┌──────── delivery PIN ◀───────────────┤
 *                       ▼                                      ▼
 *                   delivered ◀──recipient PIN── at_point (handed to pickup point)
 *                       │
 *          24h, no dispute│        dispute opened
 *                       ▼                ▼
 *                   completed ◀─admin── disputed ──admin──▶ refunded
 */
final class BookingStatus
{
    public const OFFERED = 'offered';
    public const AWAITING_PAYMENT = 'awaiting_payment';
    public const PAID = 'paid';
    public const PICKED_UP = 'picked_up';
    public const AT_POINT = 'at_point';
    public const DELIVERED = 'delivered';
    public const DISPUTED = 'disputed';
    public const COMPLETED = 'completed';
    public const REFUNDED = 'refunded';
    public const CANCELLED = 'cancelled';

    /** Statuses that occupy a slot in the driver's route capacity. */
    public const ACTIVE = [self::AWAITING_PAYMENT, self::PAID, self::PICKED_UP, self::AT_POINT];

    public const TRANSITIONS = [
        self::OFFERED          => [self::AWAITING_PAYMENT, self::CANCELLED],
        self::AWAITING_PAYMENT => [self::PAID, self::CANCELLED],
        self::PAID             => [self::PICKED_UP, self::CANCELLED],
        self::PICKED_UP        => [self::DELIVERED, self::AT_POINT, self::DISPUTED],
        self::AT_POINT         => [self::DELIVERED, self::DISPUTED],
        self::DELIVERED        => [self::COMPLETED, self::DISPUTED],
        self::DISPUTED         => [self::COMPLETED, self::REFUNDED],
        self::COMPLETED        => [],
        self::REFUNDED         => [],
        self::CANCELLED        => [],
    ];

    public const LABELS = [
        self::OFFERED          => 'Offer from driver',
        self::AWAITING_PAYMENT => 'Awaiting payment',
        self::PAID             => 'Awaiting pickup',
        self::PICKED_UP        => 'In transit',
        self::AT_POINT         => 'At pickup point',
        self::DELIVERED        => 'Delivered · dispute window',
        self::DISPUTED         => 'Under review',
        self::COMPLETED        => 'Completed',
        self::REFUNDED         => 'Refunded',
        self::CANCELLED        => 'Cancelled',
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function label(string $s): string
    {
        return self::LABELS[$s] ?? $s;
    }
}
