<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Atobbee\Auth;
use Atobbee\Http\{AdminController, AuthController, BookingController, DriverController, PageController, PointController, SenderController, WebhookController};

// Built-in dev server: let it serve static files directly.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/') ?: '/';

// Webhooks have no session and no CSRF token; they are verified by signature instead.
if ($path === '/webhooks/stripe' && $method === 'POST') {
    (new WebhookController())->stripe();
    exit;
}

Auth::startSession();
if ($method === 'POST') verify_csrf();

$routes = [
    ['GET',  '/',                              [PageController::class, 'home']],
    ['GET',  '/trust',                         [PageController::class, 'trust']],
    ['GET',  '/drivers/{id}',                  [PageController::class, 'driverProfile']],
    ['GET',  '/api/price-suggestions',         [PageController::class, 'priceSuggestions']],

    ['GET',  '/login',                         [AuthController::class, 'loginForm']],
    ['POST', '/login',                         [AuthController::class, 'login']],
    ['GET',  '/register',                      [AuthController::class, 'registerForm']],
    ['POST', '/register',                      [AuthController::class, 'register']],
    ['POST', '/logout',                        [AuthController::class, 'logout']],

    ['GET',  '/dashboard',                     [SenderController::class, 'dashboard']],
    ['GET',  '/send',                          [SenderController::class, 'form']],
    ['POST', '/send',                          [SenderController::class, 'create']],
    ['GET',  '/packages/{id}',                 [SenderController::class, 'show']],
    ['POST', '/packages/{id}/book',            [SenderController::class, 'book']],
    ['POST', '/packages/{id}/cancel',          [SenderController::class, 'cancel']],

    ['GET',  '/bookings/{id}',                 [BookingController::class, 'show']],
    ['POST', '/bookings/{id}/accept',          [BookingController::class, 'accept']],
    ['POST', '/bookings/{id}/pay',             [BookingController::class, 'pay']],
    ['GET',  '/bookings/{id}/paid',            [BookingController::class, 'paidReturn']],
    ['POST', '/bookings/{id}/cancel',          [BookingController::class, 'cancel']],
    ['POST', '/bookings/{id}/pickup',          [BookingController::class, 'pickup']],
    ['POST', '/bookings/{id}/deliver',         [BookingController::class, 'deliver']],
    ['POST', '/bookings/{id}/dispute',         [BookingController::class, 'dispute']],
    ['GET',  '/bookings/{id}/review',          [BookingController::class, 'reviewForm']],
    ['POST', '/bookings/{id}/review',          [BookingController::class, 'review']],
    ['GET',  '/bookings/{id}/proof/{kind}',    [BookingController::class, 'proof']],
    ['POST', '/reviews/{id}/flag',             [BookingController::class, 'flagReview']],

    ['GET',  '/driver',                        [DriverController::class, 'dashboard']],
    ['GET',  '/driver/routes/new',             [DriverController::class, 'routeForm']],
    ['POST', '/driver/routes',                 [DriverController::class, 'createRoute']],
    ['GET',  '/driver/routes/{id}',            [DriverController::class, 'showRoute']],
    ['POST', '/driver/routes/{id}/offer',      [DriverController::class, 'offer']],
    ['POST', '/driver/routes/{id}/cancel',     [DriverController::class, 'cancelRoute']],
    ['GET',  '/driver/profile',                [DriverController::class, 'profile']],
    ['POST', '/driver/profile',                [DriverController::class, 'saveProfile']],
    ['POST', '/driver/verify',                 [DriverController::class, 'submitVerification']],
    ['POST', '/driver/payouts',                [DriverController::class, 'payoutOnboarding']],

    ['GET',  '/point',                         [PointController::class, 'dashboard']],
    ['POST', '/point/settings',                [PointController::class, 'settings']],
    ['POST', '/point/receive',                 [PointController::class, 'receive']],
    ['POST', '/point/collect',                 [PointController::class, 'collect']],
    ['POST', '/point/subscribe',               [PointController::class, 'subscribe']],

    ['GET',  '/admin',                         [AdminController::class, 'dashboard']],
    ['POST', '/admin/users/{id}/verify',       [AdminController::class, 'verify']],
    ['POST', '/admin/disputes/{id}/resolve',   [AdminController::class, 'resolve']],
    ['POST', '/admin/reviews/{id}/moderate',   [AdminController::class, 'moderateReview']],
];

foreach ($routes as [$m, $pattern, [$class, $action]]) {
    if ($m !== $method) continue;
    $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
    if (preg_match($regex, $path, $match)) {
        $params = array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);
        try {
            (new $class())->$action(...$params);
        } catch (DomainException $e) {
            // Business-rule violations become a friendly message on the previous page.
            flash('error', $e->getMessage());
            redirect(back_path());
        }
        exit;
    }
}

abort(404, 'That page does not exist.');
