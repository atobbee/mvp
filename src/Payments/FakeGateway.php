<?php
declare(strict_types=1);

namespace Atobbee\Payments;

/** Development gateway: no money moves, everything succeeds instantly. Never use in production. */
final class FakeGateway implements Gateway
{
    public function name(): string { return 'fake'; }

    public function startPayment(array $booking, string $successUrl, string $cancelUrl): array
    {
        return ['reference' => 'fake_pi_' . bin2hex(random_bytes(6)), 'redirect' => null, 'paid' => true];
    }

    public function payout(array $booking, ?string $driverAccountId): string
    {
        return 'fake_tr_' . bin2hex(random_bytes(6));
    }

    public function refund(array $booking): string
    {
        return 'fake_re_' . bin2hex(random_bytes(6));
    }

    public function onboardDriver(array $user, string $returnUrl): array
    {
        return ['account_id' => 'fake_acct_' . $user['id'], 'url' => null];
    }
}
