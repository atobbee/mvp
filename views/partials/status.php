<?php
use AtoBbee\Domain\BookingStatus;
$cls = match ($status) {
    'completed', 'delivered' => 'ok',
    'picked_up', 'at_point', 'paid' => 'honey',
    'disputed', 'awaiting_payment', 'offered' => 'warn',
    'refunded', 'cancelled' => 'bad',
    default => '',
};
?><span class="badge <?= $cls ?>"><?= e(BookingStatus::label($status)) ?></span>
