<?php
declare(strict_types=1);

use AtoBbee\Db;

/** HTML-escape. Use for every value printed into a template. */
function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(int $cents): string
{
    return '€' . number_format($cents / 100, 2, ',', '.');
}

/** Current time as UTC ISO-8601 (what we store). */
function now_utc(?string $modify = null): string
{
    $d = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($modify) $d = $d->modify($modify);
    return $d->format('Y-m-d\TH:i:s\Z');
}

/** Format a stored UTC timestamp for display in Amsterdam time. */
function local_time(?string $utc, string $format = 'd M H:i'): string
{
    if (!$utc) return '—';
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format($format);
}

function db(): Db
{
    static $db = null;
    return $db ??= new Db(config('db_dsn'), config('db_user'), config('db_pass'));
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

/** Same-site path of the previous page (never an external URL). */
function back_path(string $fallback = '/'): string
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = parse_url($ref, PHP_URL_HOST);
    $ourHost = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
    if (!$ref || ($host && $host !== $ourHost)) return $fallback;
    $path = parse_url($ref, PHP_URL_PATH) ?: $fallback;
    $q = parse_url($ref, PHP_URL_QUERY);
    return '/' . ltrim($path, '/') . ($q ? "?$q" : '');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}

function old(string $key, mixed $default = ''): string
{
    return e($_SESSION['old'][$key] ?? $default);
}

/** Render a view inside the layout. */
function view(string $name, array $data = [], string $layout = 'layout'): void
{
    extract($data, EXTR_SKIP);
    ob_start();
    require APP_ROOT . "/views/{$name}.php";
    $content = ob_get_clean();
    require APP_ROOT . "/views/{$layout}.php";
    unset($_SESSION['old']);
}

function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    view('error', ['code' => $code, 'message' => $message ?: 'Something went wrong.']);
    exit;
}

function input(string $key, mixed $default = null): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function size_label(string $size): string
{
    return ['envelope' => 'Envelope', 'small' => 'Small parcel', 'medium' => 'Medium parcel', 'large' => 'Large parcel'][$size] ?? $size;
}

function status_badge(string $status): string
{
    ob_start();
    require APP_ROOT . '/views/partials/status.php';
    return (string)ob_get_clean();
}

function initials(string $first, string $last = ''): string
{
    return e(mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1)));
}

function stars(float $rating): string
{
    return '⭐ ' . number_format($rating, 1);
}
