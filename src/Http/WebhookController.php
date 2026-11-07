<?php
declare(strict_types=1);

namespace Atobbee\Http;

use Atobbee\Domain\BookingService;
use Atobbee\Payments\StripeGateway;

final class WebhookController
{
    public function stripe(): void
    {
        $payload = file_get_contents('php://input') ?: '';
        $event = StripeGateway::verifyWebhook($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', (string)config('stripe_webhook_secret'));
        if (!$event) { http_response_code(400); echo 'bad signature'; return; }

        db()->insert('payment_log', ['kind' => 'webhook', 'gateway' => 'stripe', 'reference' => $event['id'] ?? null, 'payload' => mb_substr($payload, 0, 10000)]);

        if (($event['type'] ?? '') === 'checkout.session.completed') {
            $session = $event['data']['object'];
            if (($session['payment_status'] ?? '') === 'paid') {
                $id = db()->value('SELECT id FROM bookings WHERE ref = ?', [$session['client_reference_id'] ?? '']);
                if ($id) BookingService::make()->markPaid((int)$id, (string)$session['payment_intent']);
            }
        }
        // Add here later: checkout.session.async_payment_succeeded (iDEAL edge cases), charge.dispute.created,
        // account.updated (driver payout readiness), customer.subscription.* (pickup point billing).
        http_response_code(200);
        echo 'ok';
    }
}
