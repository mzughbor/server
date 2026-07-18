<?php
/**
 * POST /api/subdomain/reserve   (name=NAME)
 * Places a 45-minute hold on a subdomain and returns a reservation_token.
 * Does NOT create any CyberPanel site.
 */
require __DIR__ . '/_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    falcon_json(['ok' => false, 'error' => 'method not allowed'], 405);
}

$name = falcon_normalize_name($_POST['name'] ?? '');
if ($name === '' && !empty($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($body)) {
        $name = falcon_normalize_name($body['name'] ?? '');
    }
}

if ($name === '') {
    falcon_json(['ok' => false, 'error' => 'missing name'], 400);
}

if (!falcon_is_valid_slug($name)) {
    falcon_json(['ok' => false, 'available' => false, 'error' => 'invalid', 'subdomain' => $name], 400);
}

try {
    $result = falcon_mutate_json(FALCON_RESERVATIONS, function (array $current) use ($name) {
        // Prune expired entries first.
        $current = falcon_prune_reservations($current);

        // Re-check availability now that we hold the exclusive lock.
        // (site dir / provisioned checks read other files; reservation check
        //  uses our freshly-pruned in-lock array to avoid TOCTOU.)
        if (falcon_is_reserved($name)) {
            return ['data' => $current, 'return' => ['status' => 'unavailable', 'reason' => 'reserved_word']];
        }
        if (falcon_site_dir_exists($name) || falcon_is_provisioned($name)) {
            return ['data' => $current, 'return' => ['status' => 'unavailable', 'reason' => 'taken']];
        }
        foreach ($current as $rec) {
            if (($rec['subdomain'] ?? null) === $name) {
                return ['data' => $current, 'return' => ['status' => 'unavailable', 'reason' => 'held']];
            }
        }

        $now   = time();
        $token = bin2hex(random_bytes(16));
        $entry = [
            'subdomain'  => $name,
            'token'      => $token,
            'created_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + FALCON_RESERVE_TTL),
        ];
        $current[] = $entry;
        return ['data' => $current, 'return' => ['status' => 'ok', 'entry' => $entry]];
    });
} catch (Throwable $e) {
    falcon_json(['ok' => false, 'error' => 'reservation store unavailable'], 500);
}

if (($result['status'] ?? '') !== 'ok') {
    $reason   = $result['reason'] ?? 'unavailable';
    $messages = [
        'reserved_word' => 'That name is reserved.',
        'taken'         => 'That subdomain is already taken.',
        'held'          => 'That subdomain is currently on hold. Try another.',
    ];
    falcon_json([
        'ok'        => true,
        'available' => false,
        'subdomain' => $name,
        'fqdn'      => $name . '.' . FALCON_MASTER,
        'reason'    => $reason,
        'message'   => $messages[$reason] ?? 'Unavailable.',
    ]);
}

$entry = $result['entry'];
falcon_json([
    'ok'                => true,
    'available'         => true,
    'subdomain'         => $entry['subdomain'],
    'fqdn'              => $entry['subdomain'] . '.' . FALCON_MASTER,
    'reservation_token' => $entry['token'],
    'expires_at'        => $entry['expires_at'],
    'message'           => 'Reserved for 45 minutes.',
]);
