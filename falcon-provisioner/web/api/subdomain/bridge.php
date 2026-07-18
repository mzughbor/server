<?php
/**
 * POST /api/subdomain/bridge  (token, subdomain, theme, [email])
 * Records the homepage's chosen {subdomain, theme} keyed by reservation token
 * so the Blesta poller can pick it up after the $0 trial order completes.
 *
 * Blesta's stock order form does not persist arbitrary query params into the
 * order record, so this bridge store is the reliable carrier. The poller
 * matches a new package-4 service to a bridge entry by (a) token if it
 * survived the order URL, else (b) client email, else (c) most-recent
 * unclaimed entry within the reservation window.
 */
require __DIR__ . '/_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    falcon_json(['ok' => false, 'error' => 'method not allowed'], 405);
}

$in = $_POST;
if (empty($in) && !empty($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($body)) {
        $in = $body;
    }
}

$token     = preg_replace('/[^a-f0-9]/', '', strtolower((string)($in['token'] ?? '')));
$subdomain = falcon_normalize_name($in['subdomain'] ?? '');
$theme     = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($in['theme'] ?? '')));
$email     = trim((string)($in['email'] ?? ''));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = '';
}

if ($token === '' || $subdomain === '' || !falcon_is_valid_slug($subdomain)) {
    falcon_json(['ok' => false, 'error' => 'missing or invalid token/subdomain'], 400);
}
if ($theme === '') {
    $theme = 'default';
}

try {
    falcon_mutate_json(FALCON_BRIDGE, function (array $current) use ($token, $subdomain, $theme, $email) {
        $now = time();
        // Prune stale unclaimed entries (older than the reservation TTL + buffer).
        $current = array_values(array_filter($current, function ($e) use ($now) {
            if (!empty($e['claimed'])) {
                return ($now - (int)($e['claimed_at_ts'] ?? 0)) < 86400; // keep claimed for a day
            }
            return isset($e['created_at_ts']) && ($now - (int)$e['created_at_ts']) < (FALCON_RESERVE_TTL + 3600);
        }));

        $found = false;
        foreach ($current as &$e) {
            if (($e['token'] ?? null) === $token) {
                $e['subdomain'] = $subdomain;
                $e['theme']     = $theme;
                if ($email !== '') { $e['email'] = $email; }
                $e['updated_at']    = gmdate('c', $now);
                $found = true;
                break;
            }
        }
        unset($e);
        if (!$found) {
            $current[] = [
                'token'         => $token,
                'subdomain'     => $subdomain,
                'theme'         => $theme,
                'email'         => $email,
                'created_at'    => gmdate('c', $now),
                'created_at_ts' => $now,
                'claimed'       => false,
            ];
        }
        return ['data' => $current, 'return' => true];
    });
} catch (Throwable $e) {
    falcon_json(['ok' => false, 'error' => 'bridge store unavailable'], 500);
}

falcon_json([
    'ok'        => true,
    'subdomain' => $subdomain,
    'theme'     => $theme,
    'fqdn'      => $subdomain . '.' . FALCON_MASTER,
]);
