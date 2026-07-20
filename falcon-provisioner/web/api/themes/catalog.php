<?php
/**
 * GET /api/themes/catalog.php
 * Public catalog of active WordPress template snapshots for the homepage theme picker.
 *
 * Source of truth: falcon_wp_templates (Blesta Theme Snapshots admin), active=1 only.
 * Capture files under /var/lib/falcon-provisioner/templates/{slug}/ must exist.
 */
declare(strict_types=1);

const FALCON_TEMPLATES_DIR = '/var/lib/falcon-provisioner/templates';
const FALCON_MASTER = 'falcon.codes';
const FALCON_DB_CNF = '/var/lib/falcon-provisioner/catalog_db.cnf';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('Access-Control-Allow-Origin: *');

function falcon_catalog_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function falcon_default_thumbnail(string $slug): string
{
    return 'https://' . FALCON_MASTER . '/assets/theme-' . rawurlencode($slug) . '.png';
}

function falcon_preview_url(string $staging): ?string
{
    $staging = trim($staging);
    if ($staging === '') {
        return null;
    }
    if (str_contains($staging, '://')) {
        return $staging;
    }
    if (str_contains($staging, '.')) {
        return 'https://' . $staging;
    }
    return 'https://' . $staging . '.' . FALCON_MASTER;
}

function falcon_capture_exists(string $slug): bool
{
    $dir = FALCON_TEMPLATES_DIR . '/' . $slug;
    return is_dir($dir)
        && is_readable($dir . '/database.sql.gz')
        && is_readable($dir . '/wp-content.tar.gz');
}

/**
 * @return array<int, array<string, mixed>>
 */
function falcon_load_catalog_from_db(): array
{
    if (!is_readable(FALCON_DB_CNF)) {
        return [];
    }

    $cfg = parse_ini_file(FALCON_DB_CNF, true, INI_SCANNER_RAW);
    if (!is_array($cfg) || !isset($cfg['client'])) {
        return [];
    }

    $client = $cfg['client'];
    $user = (string) ($client['user'] ?? '');
    $pass = (string) ($client['password'] ?? '');
    $db = (string) ($client['database'] ?? 'falc_blesta');
    $host = (string) ($client['host'] ?? 'localhost');

    if ($user === '') {
        return [];
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = @new mysqli($host, $user, $pass, $db);
    if ($mysqli->connect_errno) {
        return [];
    }

    $sql = 'SELECT slug, name, description, thumbnail_url, staging_domain, sort_order, last_captured
            FROM falcon_wp_templates
            WHERE active = 1
            ORDER BY sort_order ASC, name ASC';

    $result = $mysqli->query($sql);
    if ($result === false) {
        $mysqli->close();
        return [];
    }

    $templates = [];
    while ($row = $result->fetch_assoc()) {
        $slug = (string) ($row['slug'] ?? '');
        if ($slug === '' || !falcon_capture_exists($slug)) {
            continue;
        }

        $staging = (string) ($row['staging_domain'] ?? '');
        $thumb = trim((string) ($row['thumbnail_url'] ?? ''));
        $captured = $row['last_captured'] ?? null;
        if ($captured !== null && $captured !== '') {
            $ts = strtotime((string) $captured);
            $captured = $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
        }

        $templates[] = [
            'slug' => $slug,
            'name' => (string) ($row['name'] ?? $slug),
            'description' => (string) ($row['description'] ?? ''),
            'thumbnail_url' => $thumb !== '' ? $thumb : falcon_default_thumbnail($slug),
            'staging_domain' => $staging,
            'preview_url' => falcon_preview_url($staging),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'captured_at' => $captured,
        ];
    }

    $result->free();
    $mysqli->close();

    return $templates;
}

$templates = falcon_load_catalog_from_db();

falcon_catalog_json([
    'ok' => true,
    'count' => count($templates),
    'templates' => $templates,
]);
