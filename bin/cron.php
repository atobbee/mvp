<?php
declare(strict_types=1);
// Run every 5 minutes:  */5 * * * * php /path/to/atobbee/bin/cron.php >> /path/to/atobbee/storage/cron.log 2>&1
require dirname(__DIR__) . '/src/bootstrap.php';

use Atobbee\Domain\BookingService;

$svc = BookingService::make();
$expired = $svc->expireUnpaid();
$paid = $svc->releaseDuePayouts();
printf("[%s] expired unpaid: %d, payouts released: %s\n", now_utc(), $expired, $paid ? implode(', ', $paid) : 'none');
