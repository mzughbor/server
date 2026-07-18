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

    // Find the best unclaimed bridge entry for this service.
    $best = null; $bestIdx = -1;
    foreach ($bridge as $i => $e) {
        if (!empty($e['claimed'])) continue;
        $bts = (int)($e['created_at_ts'] ?? 0);
        if ($bts <= 0) continue;
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

    if ($best === null) {
        $attempts++;
        $status = $attempts >= MAX_ATTEMPTS ? 'skipped' : 'unmapped';
        $processed[$key] = ['service_id' => $svcId, 'client_id' => $clientId,
            'status' => $status, 'attempts' => $attempts, 'ts' => date('c')];
        plog("service {$svcId} (client {$clientId}) has no bridge mapping yet"
            . ($status === 'skipped' ? " - giving up after {$attempts} tries (manual provision needed)" : " - will retry"));
        continue;
    }

    $sub   = $best['e']['subdomain'] ?? '';
    $theme = $best['e']['theme'] ?? 'default';
    $token = $best['e']['token'] ?? '';
    if ($sub === '') { continue; }

    plog("provisioning service {$svcId} (client {$clientId}, {$email}) -> {$sub}.".MASTER." theme={$theme}");

    $cmd = escapeshellcmd(PROVISION)
        . ' --subdomain ' . escapeshellarg($sub)
        . ' --theme ' . escapeshellarg($theme)
        . ' --email ' . escapeshellarg($email !== '' ? $email : 'trial@' . MASTER)
        . ' --client_id ' . escapeshellarg((string)$clientId)
        . ' --token ' . escapeshellarg($token)
        . ' 2>&1';
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

        $processed[$key] = ['service_id' => $svcId, 'client_id' => $clientId,
            'status' => 'ok', 'subdomain' => $sub, 'site_url' => $siteUrl,
            'attempts' => $attempts + 1, 'ts' => date('c')];

        // Mark bridge entry claimed (provision.sh already tries; do it here too).
        if ($bestIdx >= 0) { $bridge[$bestIdx]['claimed'] = true; $bridge[$bestIdx]['claimed_at_ts'] = time(); $bridgeDirty = true; }

        notify_client($clientId, $email, $sub, $siteUrl, $adminUrl, $adminUser);
        plog("service {$svcId} provisioned OK -> {$siteUrl}");
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
 * site URL and wp-admin credentials.
 */
function notify_client(int $clientId, string $email, string $sub, string $siteUrl, string $adminUrl, string $adminUser): void {
    // Read temp password from the root-only secret file.
    $pass = '';
    $secret = STATE_DIR . "/secrets/{$sub}.env";
    if (is_file($secret)) {
        foreach (file($secret) as $l) {
            if (strpos($l, 'WP_ADMIN_PASS=') === 0) { $pass = trim(substr($l, strlen('WP_ADMIN_PASS='))); break; }
        }
    }

    // Blesta client note (safe metadata write; does not touch branding).
    $title = 'Falcon trial site provisioned';
    $desc  = "Your WordPress trial site is live:\n"
           . "Site: {$siteUrl}\n"
           . "Admin: {$adminUrl}\n"
           . "User: {$adminUser}\n"
           . "Temp password: {$pass}";
    $descEsc = str_replace("'", "''", $desc);
    db_exec("INSERT INTO client_notes (client_id, staff_id, title, description, stickied, date_added, date_updated) "
        . "VALUES ({$clientId}, NULL, '{$title}', '{$descEsc}', 0, NOW(), NOW())");

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    $subject = 'Your Falcon trial site is live';
    $body = "Hi,\n\nYour WordPress trial site is ready:\n\n"
          . "  Website:   {$siteUrl}\n"
          . "  Dashboard: {$adminUrl}\n"
          . "  Username:  {$adminUser}\n"
          . "  Password:  {$pass}\n\n"
          . "Please log in and change your password.\n\n- Falcon of Codes";
    $headers = 'From: ' . MAIL_FROM . "\r\nContent-Type: text/plain; charset=UTF-8";
    @mail($email, $subject, $body, $headers);
}
