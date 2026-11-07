<?php
// Receives the waitlist form and saves it. Works with and without JavaScript.

require __DIR__ . '/db.php';

const CONSENT_TEXT = 'Yes, send me AtoBbee news and early-access invites. Unsubscribe anytime.';
const ROLES = ['sending', 'driving', 'pickup'];

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

if (!$roles) {
    respond(false, 'Pick at least one option.', $wantsJson);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    respond(false, "That email doesn't look quite right.", $wantsJson);
}
if (!$consent) {
    respond(false, 'Tick the box so we can email you.', $wantsJson);
}

try {
    $db = atobbee_db();
    $stmt = $db->prepare('INSERT INTO waitlist (email, role, consent_text, created_at)
                          VALUES (:email, :role, :consent, :created)
                          ON CONFLICT(email) DO UPDATE SET role = excluded.role');
    $stmt->execute([
        ':email'   => $email,
        ':role'    => implode(',', $roles),   // e.g. "sending,driving"
        ':consent' => CONSENT_TEXT,
        ':created' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    error_log('AtoBbee signup failed: ' . $e->getMessage());
    respond(false, 'Something went wrong on our side. Please try again in a moment.', $wantsJson);
}

respond(true, "You're on the list.", $wantsJson);
