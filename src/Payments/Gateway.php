<?php
declare(strict_types=1);

namespace AtoBbee\Payments;

/**
 * Money flow ("separate charges and transfers" in Stripe Connect terms):
 *  1. Sender pays the full price to the platform account (charge).
 *  2. Funds stay on the platform while the delivery happens and the dispute window runs.
 *  3. On completion the platform transfers price − commission to the driver's connected account.
 *  4. On a refund decision the charge is refunded to the sender instead.
 */
interface Gateway
{
    public function name(): string;

    /**
     * Start collecting payment for a booking.
     * @return array{reference:string, redirect:?string, paid:bool}
     *   redirect: URL to send the sender to (hosted checkout), or null if paid immediately.
     */
    public function startPayment(array $booking, string $successUrl, string $cancelUrl): array;

    /** Pay the driver their share. Returns the transfer reference. */
    public function payout(array $booking, ?string $driverAccountId): string;

    /** Refund the sender in full. Returns the refund reference. */
    public function refund(array $booking): string;

    /** Create a payout account for a driver and return an onboarding URL (or null if not applicable). */
    public function onboardDriver(array $user, string $returnUrl): array;
}
