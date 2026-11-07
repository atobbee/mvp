<?php
// Receives the waitlist form and saves it. Works with and without JavaScript.

require __DIR__ . '/db.php';

const CONSENT_TEXT = 'Yes, send me AtoBbee news and early-access invites. Unsubscribe anytime.';
const ROLES = ['sending', 'driving', 'pickup'];
const COUNTRIES = ['Netherlands', 'Belgium', 'Germany', 'Other'];

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function respond(bool $ok, string $message, bool $json): void
{
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($ok ? 200 : 400);
        echo json_encode(['ok' => $ok, 'message' => $message]);
    } else {
        header('Location: index.html?' . ($ok ? 'joined=1' : 'error=' . rawurlencode($message)) . '#join');
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Please use the form on the page.', $wantsJson);
}

// Honeypot: a hidden field real people never fill in. Bots do.
if (!empty($_POST['website'])) {
    respond(true, "You're on the list.", $wantsJson);
}

$email   = strtolower(trim((string)($_POST['email'] ?? '')));
$picked  = (array)($_POST['roles'] ?? []);
$roles   = array_values(array_intersect(ROLES, array_map('strval', $picked)));
$consent = ($_POST['consent'] ?? '') === 'yes';
$firstName = mb_substr(trim(strip_tags((string)($_POST['first_name'] ?? ''))), 0, 60);
$lastName  = mb_substr(trim(strip_tags((string)($_POST['last_name'] ?? ''))), 0, 80);
$postcode  = strtoupper(mb_substr(trim(strip_tags((string)($_POST['postcode'] ?? ''))), 0, 12));
$city      = mb_substr(trim(strip_tags((string)($_POST['city'] ?? ''))), 0, 80);
$country   = (string)($_POST['country'] ?? '');
$company   = in_array('pickup', $roles, true) ? mb_substr(trim(strip_tags((string)($_POST['company'] ?? ''))), 0, 120) : '';

if (!$roles) {
    respond(false, 'Pick at least one option.', $wantsJson);
}
if ($firstName === '' || $lastName === '') {
    respond(false, 'Fill in your first name and surname.', $wantsJson);
}
if ($postcode === '' || $city === '' || !in_array($country, COUNTRIES, true)) {
    respond(false, 'Fill in your postcode, city and country.', $wantsJson);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    respond(false, "That email doesn't look quite right.", $wantsJson);
}
if (!$consent) {
    respond(false, 'Tick the box so we can email you.', $wantsJson);
}

try {
    $db = atobbee_db();
    $stmt = $db->prepare('INSERT INTO waitlist (email, role, consent_text, created_at, first_name, last_name, company, postcode, city, country)
                          VALUES (:email, :role, :consent, :created, :first, :last, :company, :postcode, :city, :country)
                          ON CONFLICT(email) DO UPDATE SET role = excluded.role, postcode = excluded.postcode, city = excluded.city, country = excluded.country,
                            first_name = COALESCE(NULLIF(excluded.first_name, \'\'), first_name),
                            last_name  = COALESCE(NULLIF(excluded.last_name, \'\'), last_name),
                            company    = COALESCE(NULLIF(excluded.company, \'\'), company)');
    $stmt->execute([
        ':email'   => $email,
        ':role'    => implode(',', $roles),   // e.g. "sending,driving"
        ':consent' => CONSENT_TEXT,
        ':created' => gmdate('c'),
        ':first'   => $firstName,
        ':last'    => $lastName,
        ':company' => $company,
        ':postcode'=> $postcode,
        ':city'    => $city,
        ':country' => $country,
    ]);
} catch (Throwable $e) {
    error_log('AtoBbee signup failed: ' . $e->getMessage());
    respond(false, 'Something went wrong on our side. Please try again in a moment.', $wantsJson);
}

respond(true, "You're on the list.", $wantsJson);
