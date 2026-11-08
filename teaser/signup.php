<?php
// Receives the waitlist form and saves it. Works with and without JavaScript.

require __DIR__ . '/db.php';

const CONSENT_TEXT = 'Yes, send me Atobbee news and early-access invites. Unsubscribe anytime.';
const ROLES = ['sending', 'driving', 'pickup'];
const COUNTRIES = ['Netherlands', 'Belgium', 'Germany', 'Other'];

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
$lang = (($_POST['lang'] ?? 'nl') === 'en') ? 'en' : 'nl';

// Messages in Dutch (default) and English, matching the language switch on the site.
const MESSAGES = [
    'form'    => ['nl' => 'Gebruik het formulier op de pagina.', 'en' => 'Please use the form on the page.'],
    'ok'      => ['nl' => 'Je staat op de lijst.', 'en' => "You're on the list."],
    'role'    => ['nl' => 'Kies minstens één optie.', 'en' => 'Pick at least one option.'],
    'name'    => ['nl' => 'Vul je voornaam en achternaam in.', 'en' => 'Fill in your first name and surname.'],
    'loc'     => ['nl' => 'Vul je postcode, plaats en land in.', 'en' => 'Fill in your postcode, city and country.'],
    'email'   => ['nl' => 'Dat e-mailadres klopt niet helemaal.', 'en' => "That email doesn't look quite right."],
    'consent' => ['nl' => 'Vink het vakje aan zodat we je mogen mailen.', 'en' => 'Tick the box so we can email you.'],
    'oops'    => ['nl' => 'Er ging bij ons iets mis. Probeer het zo nog eens.', 'en' => 'Something went wrong on our side. Please try again in a moment.'],
];
function msg(string $key, string $lang): string { return MESSAGES[$key][$lang]; }

function respond(bool $ok, string $message, bool $json): void
{
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($ok ? 200 : 400);
        echo json_encode(['ok' => $ok, 'message' => $message]);
    } else {
        global $lang;
        header('Location: index.html?' . ($lang === 'en' ? 'lang=en&' : '') . ($ok ? 'joined=1' : 'error=' . rawurlencode($message)) . '#join');
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, msg('form', $lang), $wantsJson);
}

// Honeypot: a hidden field real people never fill in. Bots do.
if (!empty($_POST['website'])) {
    respond(true, msg('ok', $lang), $wantsJson);
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
    respond(false, msg('role', $lang), $wantsJson);
}
if ($firstName === '' || $lastName === '') {
    respond(false, msg('name', $lang), $wantsJson);
}
if ($postcode === '' || $city === '' || !in_array($country, COUNTRIES, true)) {
    respond(false, msg('loc', $lang), $wantsJson);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    respond(false, msg('email', $lang), $wantsJson);
}
if (!$consent) {
    respond(false, msg('consent', $lang), $wantsJson);
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
    error_log('Atobbee signup failed: ' . $e->getMessage());
    respond(false, msg('oops', $lang), $wantsJson);
}

respond(true, msg('ok', $lang), $wantsJson);
