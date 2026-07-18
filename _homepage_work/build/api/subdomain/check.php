<?php
/**
 * GET /api/subdomain/check?name=NAME
 * Returns availability for a candidate subdomain. No token issued.
 */
require __DIR__ . '/_lib.php';

$name = falcon_normalize_name($_GET['name'] ?? '');

if ($name === '') {
    falcon_json(['ok' => false, 'error' => 'missing name'], 400);
}

if (!falcon_is_valid_slug($name)) {
    falcon_json([
        'ok'        => true,
        'available' => false,
        'subdomain' => $name,
        'fqdn'      => $name . '.' . FALCON_MASTER,
        'reason'    => 'invalid',
        'message'   => 'Use 3-32 chars: lowercase letters, numbers, hyphens (not at the ends).',
    ]);
}

list($available, $reason) = falcon_availability($name);

$messages = [
    'reserved_word' => 'That name is reserved.',
    'taken'         => 'That subdomain is already taken.',
    'held'          => 'That subdomain is currently on hold. Try another.',
];

falcon_json([
    'ok'        => true,
    'available' => $available,
    'subdomain' => $name,
    'fqdn'      => $name . '.' . FALCON_MASTER,
    'reason'    => $available ? null : $reason,
    'message'   => $available ? 'Available!' : ($messages[$reason] ?? 'Unavailable.'),
]);
