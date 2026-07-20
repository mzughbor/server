<?php
/**
 * Falcon of Codes - Blesta -> provisioner poller (Phase 4).
 *
 * Runs as root from cron every minute. Finds newly-active $0 Trial services
 * (Blesta package id 4), maps each to a {subdomain, theme} chosen on the
 * homepage (bridge.json), and runs provision.sh. Tracks handled services in
 * processed.json so each is provisioned exactly once.
 *
 * DB access: shells out to the `mysql` CLI, which authenticates as root via the
 * unix socket (no password) - the same method used everywhere else on this box.
 */

const DB            = 'falc_blesta';
const PACKAGE_ID    = 4;
const STATE_DIR     = '/var/lib/falcon-provisioner';
const BRIDGE        = STATE_DIR . '/bridge.json';
const SITES         = STATE_DIR . '/sites.json';
const PROCESSED     = STATE_DIR . '/processed.json';
const LOCKFILE      = STATE_DIR . '/poll.lock';
const PROVISION     = '/opt/falcon-provisioner/bin/provision.sh';
const LOG           = '/var/log/falcon-provisioner.log';
const MASTER        = 'falcon.codes';
const MAX_ATTEMPTS  = 5;          // give up mapping after this many minutes
const SKEW_BUFFER   = 120;        // seconds of clock skew tolerance
const MAP_WINDOW    = 4 * 3600;   // bridge entry no older than this before service
const MAIL_FROM     = 'no-reply@falcon.codes';
const OPTMAP        = '/opt/falcon-provisioner/templates/blesta_options.json'; // {field: option_pricing_id}

function plog(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [poll] ' . $msg . "\n";
    @file_put_contents(LOG, $line, FILE_APPEND);
}

/** Run a read query, return rows as arrays keyed by column name. */
function db_query(string $sql): array {
    $cmd = 'mysql -N -B ' . escapeshellarg(DB) . ' -e ' . escapeshellarg($sql) . ' 2>/dev/null';
    exec($cmd, $out, $rc);
    if ($rc !== 0) return [];
    return $out; // caller splits by \t
}

/** Run a write/DDL query. Returns true on success. */
function db_exec(string $sql): bool {
    $cmd = 'mysql ' . escapeshellarg(DB) . ' -e ' . escapeshellarg($sql) . ' 2>/dev/null';
    exec($cmd, $out, $rc);
    return $rc === 0;
}

function read_json(string $path): array {
    if (!is_file($path)) return [];
    $a = json_decode((string)@file_get_contents($path), true);
    return is_array($a) ? $a : [];
}

function write_json_locked(string $path, array $data): void {
    $fh = fopen($path, 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($path, 0640);
}

/** True when a captured WP template exists for this catalog slug. */
function template_exists(string $slug): bool {
    if ($slug === '' || $slug === 'default') return false;
    $dir = STATE_DIR . '/templates/' . $slug;
    return is_dir($dir)
        && is_file($dir . '/database.sql.gz')
        && is_file($dir . '/wp-content.tar.gz');
}

/** Load the Blesta config-option map {field => option_pricing_id}. */
function load_option_map(): array {
    $m = json_decode((string)@file_get_contents(OPTMAP), true);
    return is_array($m) ? $m : [];
}

/** Read a service's Blesta config-option values as {field => value}. */
function read_service_options(int $svcId, array $map): array {
    if (!$map) return [];
    $ids = implode(',', array_map('intval', array_values($map)));
    $rows = db_query("SELECT option_pricing_id, value FROM service_options WHERE service_id={$svcId} AND option_pricing_id IN ({$ids})");
    $byId = [];
    foreach ($rows as $r) { list($pid, $val) = array_pad(explode("\t", $r), 2, ''); $byId[(int)$pid] = $val; }
    $out = [];
    foreach ($map as $field => $pid) { if (array_key_exists((int)$pid, $byId)) $out[$field] = $byId[(int)$pid]; }
    return $out;
}

/** Upsert one client-visible config-option value on a service. */
function write_service_option(int $svcId, int $pid, string $value): void {
    $v = str_replace("'", "''", $value);
    db_exec("DELETE FROM service_options WHERE service_id={$svcId} AND option_pricing_id={$pid}");
    db_exec("INSERT INTO service_options (service_id, option_pricing_id, qty, value, encrypted) VALUES ({$svcId}, {$pid}, 1, '{$v}', 0)");
}

/** Parse a root-only secret env file into an assoc array. */
function read_secret(string $sub): array {
    $out = []; $f = STATE_DIR . "/secrets/{$sub}.env";
    if (is_file($f)) {
        foreach (file($f) as $l) {
            $l = rtrim($l, "\r\n"); if ($l === '') continue;
            $p = strpos($l, '='); if ($p === false) continue;
            $out[substr($l, 0, $p)] = substr($l, $p + 1);
        }
    }
    return $out;
}

// --- single-instance lock -------------------------------------------------
$lock = fopen(LOCKFILE, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // previous run still going
}

// --- candidate services (active, package 4) -------------------------------
$sql = "SELECT s.id, s.client_id, UNIX_TIMESTAMP(s.date_added)
        FROM services s
        JOIN package_pricing pp ON pp.id = s.pricing_id
        WHERE pp.package_id = " . PACKAGE_ID . "
          AND s.status = 'active'
        ORDER BY s.date_added ASC";
$rows = db_query($sql);

$processed = read_json(PROCESSED);           // keyed by service_id => record
$bridge    = read_json(BRIDGE);
$optMap    = load_option_map();              // {field => option_pricing_id}
$bridgeDirty = false;                         // bridge.json is owned by falco6812 (API writer)
$handled   = 0;

foreach ($rows as $row) {
    list($svcId, $clientId, $addedTs) = array_pad(explode("\t", $row), 3, '');
    $svcId    = (int)$svcId;
    $clientId = (int)$clientId;
    $addedTs  = (int)$addedTs;
    if ($svcId <= 0) continue;

    $key = (string)$svcId;
    $prev = $processed[$key] ?? null;
    if ($prev && ($prev['status'] ?? '') === 'ok') continue;
    if ($prev && ($prev['status'] ?? '') === 'skipped') continue;
    $attempts = $prev['attempts'] ?? 0;

    // Client primary email.
    $emailRows = db_query("SELECT email FROM contacts WHERE client_id = {$clientId} AND contact_type='primary' ORDER BY id ASC LIMIT 1");
    $email = $emailRows ? trim($emailRows[0]) : '';

    // PRIMARY source: Blesta custom order fields (config options) on the service.
    $svcOpts     = read_service_options($svcId, $optMap);
    $subPrimary  = trim($svcOpts['subdomain'] ?? '');
    $themePrimary = trim($svcOpts['theme'] ?? '');

    // Find the best unclaimed bridge entry for this service (fallback + token).
    $best = null; $bestIdx = -1;
    foreach ($bridge as $i => $e) {
        if (!empty($e['claimed'])) continue;
        $bts = (int)($e['created_at_ts'] ?? 0);
        if ($bts <= 0) continue;
        // If we already have the subdomain from Blesta, match the bridge by it
        // (for token + claim); otherwise fall back to the time/email heuristic.
        if ($subPrimary !== '') {
            if (($e['subdomain'] ?? '') === $subPrimary) { $best = ['e' => $e, 'bts' => $bts, 'emailMatch' => true]; $bestIdx = $i; break; }
            continue;
        }
        // Bridge must have been created before (or ~same time as) the order.
        if ($bts > $addedTs + SKEW_BUFFER) continue;
        if ($addedTs - $bts > MAP_WINDOW) continue;
        // Prefer email match, else newest.
        $emailMatch = ($email !== '' && ($e['email'] ?? '') === $email);
        if ($best === null
            || ($emailMatch && !$best['emailMatch'])
            || ($emailMatch === $best['emailMatch'] && $bts > $best['bts'])) {
            $best = ['e' => $e, 'bts' => $bts, 'emailMatch' => $emailMatch];
            $bestIdx = $i;
        }
    }

    // Resolve subdomain/theme/token: Blesta field first, bridge as fallback.
    if ($subPrimary !== '') {
        $sub    = $subPrimary;
        $theme  = $themePrimary !== '' ? $themePrimary : ($best['e']['theme'] ?? 'default');
        $token  = $best['e']['token'] ?? '';
        $source = 'blesta_field';
    } elseif ($best !== null) {
        $sub    = $best['e']['subdomain'] ?? '';
        $theme  = $best['e']['theme'] ?? 'default';
        $token  = $best['e']['token'] ?? '';
        $source = 'bridge';
    } else {
        $attempts++;
        $status = $attempts >= MAX_ATTEMPTS ? 'skipped' : 'unmapped';
        $processed[$key] = ['service_id' => $svcId, 'client_id' => $clientId,
            'status' => $status, 'attempts' => $attempts, 'ts' => date('c')];
        plog("service {$svcId} (client {$clientId}) has no subdomain mapping yet (no Blesta field, no bridge)"
            . ($status === 'skipped' ? " - giving up after {$attempts} tries (manual provision needed)" : " - will retry"));
        continue;
    }
    if ($sub === '') { continue; }

    plog("provisioning service {$svcId} (client {$clientId}, {$email}) -> {$sub}.".MASTER
        . " theme={$theme} template=" . (template_exists($theme) ? $theme : 'none') . " [source={$source}]");

    $cmd = escapeshellcmd(PROVISION)
        . ' --subdomain ' . escapeshellarg($sub)
        . ' --theme ' . escapeshellarg($theme)
        . ' --email ' . escapeshellarg($email !== '' ? $email : 'trial@' . MASTER)
        . ' --client_id ' . escapeshellarg((string)$clientId)
        . ' --token ' . escapeshellarg($token);
    if (template_exists($theme)) {
        $cmd .= ' --template ' . escapeshellarg($theme);
    }
    $cmd .= ' 2>&1';
    exec($cmd, $pout, $prc);
    $poutStr = implode("\n", $pout);

    if ($prc === 0 && strpos($poutStr, 'PROVISION_OK') !== false) {
        // Parse key=value result lines.
        $res = [];
        foreach ($pout as $l) {
            if (strpos($l, '=') !== false && strpos($l, ' ') === false) {
                list($k, $v) = explode('=', $l, 2);
                $res[$k] = $v;
            }
        }
        $siteUrl = $res['site_url'] ?? "https://{$sub}." . MASTER . '/';
        $adminUrl = $res['wp_admin_url'] ?? "https://{$sub}." . MASTER . '/wp-admin/';
        $adminUser = $res['wp_admin_user'] ?? 'falconadmin';

        // Gather credentials (passwords come from the root-only secret file).
        $secret = read_secret($sub);
        $creds = [
            'subdomain'    => $sub,
            'theme'        => $theme,
            'site_url'     => rtrim($siteUrl, '/'),
            'wp_admin_url' => $adminUrl,
            'wp_user'      => $adminUser,
            'wp_pass'      => $secret['WP_ADMIN_PASS'] ?? '',
            'ftp_access'   => $res['ftp_access'] ?? ($secret['FTP_ACCESS'] ?? ''),
            'ftp_user'     => $res['ftp_user'] ?? ($secret['FTP_USER'] ?? ''),
            'ftp_pass'     => $secret['FTP_PASS'] ?? '',
        ];

        // C) Write client-visible config options so the logged-in client sees
        //    them under Services -> (service) -> Configurable Options.
        foreach ($creds as $field => $val) {
            if (!isset($optMap[$field])) continue;
            write_service_option($svcId, (int)$optMap[$field], (string)$val);
        }

        $processed[$key] = ['service_id' => $svcId, 'client_id' => $clientId,
            'status' => 'ok', 'subdomain' => $sub, 'site_url' => $siteUrl,
            'source' => $source, 'attempts' => $attempts + 1, 'ts' => date('c')];

        // Mark bridge entry claimed (provision.sh already tries; do it here too).
        if ($bestIdx >= 0) { $bridge[$bestIdx]['claimed'] = true; $bridge[$bestIdx]['claimed_at_ts'] = time(); $bridgeDirty = true; }

        notify_client($clientId, $email, $creds);
        plog("service {$svcId} provisioned OK -> {$siteUrl} (creds written to service {$svcId})");
        $handled++;
    } else {
        $attempts++;
        $status = $attempts >= MAX_ATTEMPTS ? 'failed' : 'error';
        $processed[$key] = ['service_id' => $svcId, 'client_id' => $clientId,
            'status' => $status, 'attempts' => $attempts, 'ts' => date('c')];
        plog("service {$svcId} provision FAILED (attempt {$attempts}): " . substr($poutStr, -400));
    }
}

write_json_locked(PROCESSED, $processed);
// bridge.json is written by the API (falco6812); only rewrite when we actually
// claimed an entry, and keep it owned by falco6812 so the API can keep writing.
if ($bridgeDirty) {
    write_json_locked(BRIDGE, $bridge);
    @chown(BRIDGE, 'falco6812');
    @chgrp(BRIDGE, 'falco6812');
}
flock($lock, LOCK_UN);
fclose($lock);
if ($handled > 0) plog("run complete: {$handled} site(s) provisioned");
exit(0);

/**
 * Notify the client: add a Blesta client note + best-effort email with the
 * site URL, WordPress admin credentials, and site FTP/FTPS credentials.
 */
function notify_client(int $clientId, string $email, array $c): void {
    $siteUrl  = $c['site_url'] ?? '';
    $adminUrl = $c['wp_admin_url'] ?? '';
    $adminUser= $c['wp_user'] ?? '';
    $wpPass   = $c['wp_pass'] ?? '';
    $ftpAcc   = $c['ftp_access'] ?? '';
    $ftpUser  = $c['ftp_user'] ?? '';
    $ftpPass  = $c['ftp_pass'] ?? '';

    // Blesta client note (safe metadata write; does not touch branding).
    $title = 'Falcon trial site provisioned';
    $desc  = "Your WordPress trial site is live:\n"
           . "Site: {$siteUrl}\n\n"
           . "WordPress admin\n"
           . "  URL: {$adminUrl}\n"
           . "  User: {$adminUser}\n"
           . "  Password: {$wpPass}\n\n"
           . "File access (FTP / FTPS) - site files only\n"
           . "  Connect: {$ftpAcc}\n"
           . "  User: {$ftpUser}\n"
           . "  Password: {$ftpPass}";
    $descEsc = str_replace("'", "''", $desc);
    db_exec("INSERT INTO client_notes (client_id, staff_id, title, description, stickied, date_added, date_updated) "
        . "VALUES ({$clientId}, NULL, '{$title}', '{$descEsc}', 0, NOW(), NOW())");

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    $subject = 'Your Falcon trial site is live';
    $body = "Hi,\n\nYour WordPress trial site is ready:\n\n"
          . "  Website:   {$siteUrl}\n\n"
          . "WordPress admin\n"
          . "  Dashboard: {$adminUrl}\n"
          . "  Username:  {$adminUser}\n"
          . "  Password:  {$wpPass}\n\n"
          . "File access (FTP / FTPS) - your site files only\n"
          . "  Connect:   {$ftpAcc}\n"
          . "  Username:  {$ftpUser}\n"
          . "  Password:  {$ftpPass}\n\n"
          . "You can also view these anytime in your client area under Services.\n"
          . "Please log in and change your passwords.\n\n- Falcon of Codes";
    $headers = 'From: ' . MAIL_FROM . "\r\nContent-Type: text/plain; charset=UTF-8";
    @mail($email, $subject, $body, $headers);
}
