<?php
/**
 * GET /api/themes/catalog.php
 * Public catalog of active WordPress template snapshots for the homepage theme picker.
 */
declare(strict_types=1);

const FALCON_TEMPLATES_DIR = '/var/lib/falcon-provisioner/templates';
const FALCON_MASTER = 'falcon.codes';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('Access-Control-Allow-Origin: *');

function falcon_catalog_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function falcon_load_template_meta(string $path): ?array
{
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function falcon_default_thumbnail(string $slug): string
{
    return 'https://' . FALCON_MASTER . '/assets/theme-' . rawurlencode($slug) . '.png';
}

$templates = [];

if (is_dir(FALCON_TEMPLATES_DIR)) {
    foreach (glob(FALCON_TEMPLATES_DIR . '/*/meta.json') ?: [] as $metaFile) {
        $meta = falcon_load_template_meta($metaFile);
        if ($meta === null || empty($meta['slug']) || empty($meta['active'])) {
            continue;
        }

        $slug = (string) $meta['slug'];
        $staging = (string) ($meta['staging_domain'] ?? '');
        $previewUrl = $staging !== ''
            ? (str_contains($staging, '.') ? 'https://' . $staging : 'https://' . $staging . '.' . FALCON_MASTER)
            : null;

        $templates[] = [
            'slug' => $slug,
            'name' => (string) ($meta['name'] ?? $slug),
            'description' => (string) ($meta['description'] ?? ''),
            'thumbnail_url' => (string) ($meta['thumbnail_url'] ?? falcon_default_thumbnail($slug)),
            'staging_domain' => $staging,
            'preview_url' => $previewUrl,
            'sort_order' => (int) ($meta['sort_order'] ?? 0),
            'captured_at' => $meta['captured_at'] ?? null,
        ];
    }
}

usort($templates, static function (array $a, array $b): int {
    $order = ($a['sort_order'] <=> $b['sort_order']);
    return $order !== 0 ? $order : strcmp($a['name'], $b['name']);
});

falcon_catalog_json([
    'ok' => true,
    'count' => count($templates),
    'templates' => $templates,
]);
