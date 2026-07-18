<?php
/**
 * Falcon of Codes - subdomain API shared library.
 * Runs as the site user (falco6812) under lsphp83.
 *
 * State lives OUTSIDE the web root under /var/lib/falcon-provisioner so it is
 * never web-served. The provisioner (root) writes sites.json; the API
 * (falco6812) writes reservations.json + bridge.json. Directory is
 * 2750 falco6812:falco6812 (setgid) so root-created files stay group-readable.
 */

// Refuse direct web access; this file is an include-only library.
if (isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

const FALCON_MASTER      = 'falcon.codes';
const FALCON_STATE_DIR   = '/var/lib/falcon-provisioner';
const FALCON_RESERVATIONS = FALCON_STATE_DIR . '/reservations.json';
const FALCON_SITES        = FALCON_STATE_DIR . '/sites.json';
const FALCON_BRIDGE       = FALCON_STATE_DIR . '/bridge.json';
const FALCON_HOME         = '/home/falcon.codes';
const FALCON_RESERVE_TTL  = 2700; // 45 minutes, in seconds

// Slug: 3-32 chars, must start & end alphanumeric, lowercase.
const FALCON_SLUG_RE = '/^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$/';

const FALCON_RESERVED = [
    'www', 'portal', 'mail', 'api', 'admin', 'demo-test', 'ns1', 'ns2',
    'ftp', 'cpanel', 'webmail', 'mx', 'smtp', 'imap', 'pop', 'root',
    'blesta', 'falcon', 'static', 'cdn', 'assets',
];

function falcon_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function falcon_normalize_name(?string $raw): string {
    return strtolower(trim((string)$raw));
}

function falcon_is_valid_slug(string $name): bool {
    if (strlen($name) < 3 || strlen($name) > 32) {
        return false;
    }
    return (bool)preg_match(FALCON_SLUG_RE, $name);
}

function falcon_is_reserved(string $name): bool {
    return in_array($name, FALCON_RESERVED, true);
}

/**
 * Read a JSON file under an flock shared lock. Returns [] if missing/invalid.
 */
function falcon_read_json(string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $fh = @fopen($path, 'r');
    if (!$fh) {
        return [];
    }
    $data = [];
    if (flock($fh, LOCK_SH)) {
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    fclose($fh);
    return $data;
}

/**
 * Mutate a JSON file atomically under an exclusive lock. The callback receives
 * the current array and returns the new array to persist.
 */
function falcon_mutate_json(string $path, callable $mutator) {
    $fh = fopen($path, 'c+');
    if (!$fh) {
        throw new RuntimeException('cannot open state file');
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new RuntimeException('cannot lock state file');
    }
    $raw = stream_get_contents($fh);
    $current = json_decode((string)$raw, true);
    if (!is_array($current)) {
        $current = [];
    }
    $result = $mutator($current);
    $new = $result['data'] ?? $current;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($new, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($path, 0640);
    return $result['return'] ?? null;
}

/** Drop expired reservations from an array keyed by anything, value has expires_at. */
function falcon_prune_reservations(array $items): array {
    $now = time();
    $out = [];
    foreach ($items as $item) {
        if (!isset($item['expires_at'])) {
            continue;
        }
        if (strtotime($item['expires_at']) > $now) {
            $out[] = $item;
        }
    }
    return $out;
}

function falcon_site_dir_exists(string $name): bool {
    $fqdn = $name . '.' . FALCON_MASTER;
    return is_dir(FALCON_HOME . '/' . $fqdn)
        || is_dir(FALCON_HOME . '/public_html/' . $fqdn);
}

function falcon_is_provisioned(string $name): bool {
    $fqdn = $name . '.' . FALCON_MASTER;
    foreach (falcon_read_json(FALCON_SITES) as $rec) {
        if (($rec['subdomain'] ?? null) === $name || ($rec['fqdn'] ?? null) === $fqdn) {
            return true;
        }
    }
    return false;
}

function falcon_is_reserved_active(string $name): bool {
    $now = time();
    foreach (falcon_read_json(FALCON_RESERVATIONS) as $rec) {
        if (($rec['subdomain'] ?? null) === $name
            && isset($rec['expires_at'])
            && strtotime($rec['expires_at']) > $now) {
            return true;
        }
    }
    return false;
}

/**
 * Full availability decision. Returns [bool available, string|null reason].
 */
function falcon_availability(string $name): array {
    if (!falcon_is_valid_slug($name)) {
        return [false, 'invalid'];
    }
    if (falcon_is_reserved($name)) {
        return [false, 'reserved_word'];
    }
    if (falcon_site_dir_exists($name)) {
        return [false, 'taken'];
    }
    if (falcon_is_provisioned($name)) {
        return [false, 'taken'];
    }
    if (falcon_is_reserved_active($name)) {
        return [false, 'held'];
    }
    return [true, null];
}
