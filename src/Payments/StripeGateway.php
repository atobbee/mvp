<?php
declare(strict_types=1);

namespace Atobbee\Payments;

/**
 * Stripe Connect via the REST API (no SDK dependency, just curl).
 * Uses Checkout Sessions for payment (cards + iDEAL if enabled in your dashboard),
 * Express connected accounts for drivers, and Transfers for payouts.
 *
 * Test with sk_test_ keys first. Check Stripe's current docs before going live:
 * the API version used here is pinned in STRIPE_VERSION.
 */
final class StripeGateway implements Gateway
{
    private const API = 'https://api.stripe.com/v1/';
    private const STRIPE_VERSION = '2024-06-20';

    public function __construct(private string $secretKey)
    {
        if ($secretKey === '') throw new \RuntimeException('Stripe secret key missing in config.php');
    }

    public function name(): string { return 'stripe'; }

    public function startPayment(array $booking, string $successUrl, string $cancelUrl): array
    {
        $session = $this->request('POST', 'checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $booking['ref'],
            'metadata' => ['booking_ref' => $booking['ref']],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $booking['price_cents'],
                    'product_data' => ['name' => 'Atobbee delivery ' . $booking['ref']],
                ],
            ]],
            'payment_intent_data' => [
                'transfer_group' => $booking['ref'],
                'metadata' => ['booking_ref' => $booking['ref']],
            ],
        ]);
        return ['reference' => $session['id'], 'redirect' => $session['url'], 'paid' => false];
    }

    public function payout(array $booking, ?string $driverAccountId): string
    {
        if (!$driverAccountId) throw new \RuntimeException("Driver has no Stripe account for {$booking['ref']}");
        $pi = $this->paymentIntentFor($booking['payment_ref']);
        $transfer = $this->request('POST', 'transfers', [
            'amount' => $booking['driver_cents'],
            'currency' => 'eur',
            'destination' => $driverAccountId,
            'transfer_group' => $booking['ref'],
            // Tie the transfer to the charge so it works before the funds are "available".
            'source_transaction' => $pi['latest_charge'],
            'metadata' => ['booking_ref' => $booking['ref']],
        ], idempotencyKey: 'payout-' . $booking['ref']);
        return $transfer['id'];
    }

    public function refund(array $booking): string
    {
        $pi = $this->paymentIntentFor($booking['payment_ref']);
        $refund = $this->request('POST', 'refunds', [
            'payment_intent' => $pi['id'],
            'metadata' => ['booking_ref' => $booking['ref']],
        ], idempotencyKey: 'refund-' . $booking['ref']);
        return $refund['id'];
    }

    public function onboardDriver(array $user, string $returnUrl): array
    {
        $accountId = $user['stripe_account_id'];
        if (!$accountId) {
            $account = $this->request('POST', 'accounts', [
                'type' => 'express',
                'country' => 'NL',
                'email' => $user['email'],
                'capabilities' => ['transfers' => ['requested' => 'true']],
                'business_type' => $user['is_professional'] ? 'company' : 'individual',
                'metadata' => ['atobbee_user_id' => (string)$user['id']],
            ]);
            $accountId = $account['id'];
        }
        $link = $this->request('POST', 'account_links', [
            'account' => $accountId,
            'refresh_url' => $returnUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);
        return ['account_id' => $accountId, 'url' => $link['url']];
    }

    /** payment_ref holds either a Checkout Session id (cs_) or a PaymentIntent id (pi_). */
    private function paymentIntentFor(string $ref): array
    {
        if (str_starts_with($ref, 'cs_')) {
            $session = $this->request('GET', 'checkout/sessions/' . rawurlencode($ref));
            $ref = $session['payment_intent'];
        }
        return $this->request('GET', 'payment_intents/' . rawurlencode($ref));
    }

    /**
     * Verify a webhook and return the decoded event, or null if the signature is invalid.
     */
    public static function verifyWebhook(string $payload, string $sigHeader, string $secret, int $tolerance = 300): ?array
    {
        $parts = [];
        foreach (explode(',', $sigHeader) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            $parts[$k][] = $v;
        }
        $t = (int)($parts['t'][0] ?? 0);
        if (!$t || abs(time() - $t) > $tolerance) return null;
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($parts['v1'] ?? [] as $sig) {
            if (hash_equals($expected, $sig)) return json_decode($payload, true);
        }
        return null;
    }

    private function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $ch = curl_init(self::API . $path . ($method === 'GET' && $params ? '?' . http_build_query($params) : ''));
        $headers = ['Stripe-Version: ' . self::STRIPE_VERSION];
        if ($idempotencyKey) $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new \RuntimeException("Stripe request failed: $err");
        $data = json_decode((string)$body, true) ?? [];
        if ($status >= 400) {
            throw new \RuntimeException('Stripe error: ' . ($data['error']['message'] ?? "HTTP $status"));
        }
        return $data;
    }
}
