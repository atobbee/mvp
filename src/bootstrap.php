<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    $prefix = 'Atobbee\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

$configFile = is_file(APP_ROOT . '/config.php') ? APP_ROOT . '/config.php' : APP_ROOT . '/config.example.php';
$GLOBALS['__config'] = require $configFile;

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['__config'][$key] ?? $default;
}

date_default_timezone_set('Europe/Amsterdam');

if (config('env') === 'prod') {
    ini_set('display_errors', '0');
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

require __DIR__ . '/helpers.php';
