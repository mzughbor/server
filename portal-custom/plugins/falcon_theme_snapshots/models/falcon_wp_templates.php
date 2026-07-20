<?php
/**
 * WordPress template catalog (Phase 2 primary product)
 *
 * @package blesta
 * @subpackage plugins.falconthemesnapshots.models
 * @copyright Copyright (c) 2026, Falcon of Codes
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link https://falcon.codes/
 */
class FalconWpTemplates extends FalconThemeSnapshotsModel
{
    public const STORAGE_ROOT = '/var/lib/falcon-provisioner/templates';
    public const CAPTURE_BIN = '/opt/falcon-provisioner/bin/snapshot_capture.sh';
    public const CAPTURE_LOG = '/var/log/falcon-provisioner-capture.log';
    public const ADMIN_TEST_BIN = '/opt/falcon-provisioner/bin/admin_test_provision.sh';
    public const CLONE_STAGING_BIN = '/opt/falcon-provisioner/bin/clone_staging_wp.sh';
    public const SYNC_META_BIN = '/opt/falcon-provisioner/bin/sync_template_meta.sh';
    public const PROVISION_LOG = '/var/log/falcon-provisioner.log';
    public const STAGING_SECRETS_DIR = '/var/lib/falcon-provisioner/secrets';

    public function __construct()
    {
        parent::__construct();

        Language::loadLang(
            'falcon_wp_templates',
            null,
            PLUGINDIR . 'falcon_theme_snapshots' . DS . 'language' . DS
        );
    }

    /**
     * @param array $vars
     * @return stdClass|false
     */
    public function add(array $vars)
    {
        $vars = $this->normalizeVars($vars);
        $this->Input->setRules($this->getRules($vars));

        if (!$this->Input->validates($vars)) {
            return false;
        }

        $fields = [
            'company_id' => $vars['company_id'],
            'slug' => $vars['slug'],
            'name' => $vars['name'],
            'description' => $vars['description'],
            'thumbnail_url' => $vars['thumbnail_url'],
            'staging_domain' => $vars['staging_domain'],
            'storage_dir' => self::STORAGE_ROOT . DS . $vars['slug'],
            'active' => (int) ($vars['active'] ?? 0),
            'sort_order' => (int) ($vars['sort_order'] ?? 0),
            'date_created' => $this->mysqlNow(),
        ];

        $this->Record->insert('falcon_wp_templates', $fields);
        $template = $this->get($this->Record->lastInsertId());
        if ($template && !empty($template->staging_domain)) {
            $this->maybeProvisionStaging($template);
        }

        return $template;
    }

    /**
     * @param int $template_id
     * @param array $vars
     * @return stdClass|false
     */
    public function edit($template_id, array $vars)
    {
        if (!($template = $this->get($template_id))) {
            return false;
        }

        $vars = $this->normalizeVars($vars, true);
        $this->Input->setRules($this->getRules($vars, true, $template_id));

        if (!$this->Input->validates($vars)) {
            return false;
        }

        $fields = array_intersect_key(
            $vars,
            array_flip(['name', 'description', 'thumbnail_url', 'staging_domain', 'active', 'sort_order'])
        );
        if (isset($fields['active'])) {
            $fields['active'] = (int) $fields['active'];
        }
        if (isset($fields['sort_order'])) {
            $fields['sort_order'] = (int) $fields['sort_order'];
        }
        $fields['date_updated'] = $this->mysqlNow();

        $this->Record->where('id', '=', $template_id)->update('falcon_wp_templates', $fields);

        $updated = $this->get($template_id);
        if ($updated) {
            $this->syncMetaActive($template->slug, (bool) $updated->active, $updated);
            if (!empty($updated->staging_domain) && !$this->stagingSiteReady($updated->staging_domain)) {
                $this->maybeProvisionStaging($updated);
            }
        }

        return $updated;
    }

    /**
     * Toggle catalog visibility without requiring full edit form fields.
     *
     * @param int $template_id
     * @param int $active 0 or 1
     * @return stdClass|false
     */
    public function setActive($template_id, $active)
    {
        if (!($template = $this->get($template_id))) {
            return false;
        }

        $this->Record->where('id', '=', $template_id)->update('falcon_wp_templates', [
            'active' => (int) $active,
            'date_updated' => $this->mysqlNow(),
        ]);

        $updated = $this->get($template_id);
        if ($updated) {
            $this->syncMetaActive($template->slug, (bool) $updated->active, $updated);
        }

        return $updated;
    }

    /**
     * @param int $template_id
     * @return stdClass|false
     */
    public function get($template_id)
    {
        return $this->Record->select()->from('falcon_wp_templates')->
            where('id', '=', $template_id)->fetch();
    }

    /**
     * @param int $company_id
     * @param string $slug
     * @return stdClass|false
     */
    public function getBySlug($company_id, $slug)
    {
        return $this->Record->select()->from('falcon_wp_templates')->
            where('company_id', '=', $company_id)->
            where('slug', '=', $slug)->fetch();
    }

    /**
     * All templates for admin dropdowns (no pagination).
     *
     * @param int $company_id
     * @return array
     */
    public function getAllForSelect($company_id)
    {
        return $this->Record->select()->from('falcon_wp_templates')->
            where('company_id', '=', $company_id)->
            order(['name' => 'asc'])->
            fetchAll();
    }

    /**
     * Admin test: create Blesta trial service and run provisioner poller.
     *
     * @param int $company_id
     * @param string $subdomain
     * @param string $theme_slug
     * @return array|false
     */
    public function adminTestProvision($company_id, $subdomain, $theme_slug)
    {
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/', $subdomain)) {
            $this->Input->setErrors([
                'test' => ['invalid' => Language::_('FalconWpTemplates.!error.test.subdomain', true)]
            ]);
            return false;
        }

        if ($theme_slug === '' || !($template = $this->getBySlug($company_id, $theme_slug))) {
            $this->Input->setErrors([
                'test' => ['invalid' => Language::_('FalconWpTemplates.!error.test.theme', true)]
            ]);
            return false;
        }

        if (empty($template->last_captured)) {
            $this->Input->setErrors([
                'test' => ['invalid' => Language::_('FalconWpTemplates.!error.test.not_captured', true)]
            ]);
            return false;
        }

        $cmd = sprintf(
            'sudo %s %s %s 2>&1',
            escapeshellarg(self::ADMIN_TEST_BIN),
            escapeshellarg($subdomain),
            escapeshellarg($theme_slug)
        );

        $started = gmdate('Y-m-d H:i:s');
        $this->logProvision(sprintf(
            '[admin_test_start] sub=%s theme=%s template_id=%d cmd=%s',
            $subdomain,
            $theme_slug,
            (int) $template->id,
            $cmd
        ));

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $outputText = implode("\n", $output);

        $this->logProvision(sprintf(
            '[admin_test_finish] sub=%s theme=%s exit=%d started=%s output=%s',
            $subdomain,
            $theme_slug,
            $code,
            $started,
            $outputText !== '' ? $outputText : '(empty)'
        ));

        foreach ($output as $line) {
            if (strpos($line, 'TEST_OK ') === 0) {
                $json = trim(substr($line, strlen('TEST_OK ')));
                $result = json_decode($json, true);
                if (is_array($result)) {
                    return $result;
                }
            }
            if (strpos($line, 'TEST_FAIL ') === 0) {
                $json = trim(substr($line, strlen('TEST_FAIL ')));
                $fail = json_decode($json, true);
                $message = is_array($fail) ? ($fail['message'] ?? '') : '';
                $this->Input->setErrors([
                    'test' => [
                        'failed' => $message !== ''
                            ? $message
                            : Language::_('FalconWpTemplates.!error.test.failed', true)
                    ]
                ]);
                return false;
            }
        }

        $this->Input->setErrors([
            'test' => [
                'failed' => Language::_('FalconWpTemplates.!error.test.failed', true, $outputText)
            ]
        ]);
        return false;
    }

    /**
     * @param int $company_id
     * @param int $page
     * @param array $order_by
     * @return array
     */
    public function getList($company_id, $page = 1, array $order_by = ['sort_order' => 'asc'])
    {
        $templates = $this->Record->select()->from('falcon_wp_templates')->
            where('company_id', '=', $company_id)->
            order($order_by)->
            limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())->
            fetchAll();

        foreach ($templates as $template) {
            $template->staging_ready = $this->stagingSiteReady($template->staging_domain);
            $template->staging_admin_url = null;
            if ($template->staging_ready) {
                $creds = $this->getStagingCredentials($template->staging_domain);
                $template->staging_admin_url = $creds['WP_ADMIN_URL']
                    ?? ('https://' . $template->staging_domain . '/wp-admin/');
            }
            $template->catalog_visible = $this->isCatalogVisible($template);
        }

        return $templates;
    }

    /**
     * Whether capture artifacts exist for catalog API listing (matches catalog.php).
     *
     * @param string $slug
     * @return bool
     */
    public function captureReady($slug)
    {
        $dir = self::STORAGE_ROOT . DS . $slug;

        return is_dir($dir)
            && is_readable($dir . DS . 'database.sql.gz')
            && is_readable($dir . DS . 'wp-content.tar.gz');
    }

    /**
     * Whether the template is returned by GET /api/themes/catalog.php.
     *
     * @param stdClass $template
     * @return bool
     */
    public function isCatalogVisible($template)
    {
        return !empty($template->active) && $this->captureReady($template->slug);
    }

    /**
     * @param int $company_id
     * @return int
     */
    public function getListCount($company_id)
    {
        return $this->Record->select(['id'])->from('falcon_wp_templates')->
            where('company_id', '=', $company_id)->
            numResults();
    }

    /**
     * Clone golden staging-base to the template staging domain when missing.
     *
     * @param stdClass $template
     * @return array|false Parsed clone output on success
     */
    public function maybeProvisionStaging($template)
    {
        if (empty($template->staging_domain)) {
            return false;
        }

        if ($this->stagingSiteReady($template->staging_domain)) {
            return ['status' => 'already_exists'];
        }

        return $this->provisionStaging($template);
    }

    /**
     * @param stdClass $template
     * @return array|false
     */
    public function provisionStaging($template)
    {
        if (empty($template->staging_domain)) {
            $this->Input->setErrors([
                'staging' => ['missing' => Language::_('FalconWpTemplates.!error.staging_domain.empty', true)]
            ]);
            return false;
        }

        $cmd = sprintf(
            'sudo %s --domain %s 2>&1',
            escapeshellarg(self::CLONE_STAGING_BIN),
            escapeshellarg($template->staging_domain)
        );

        $started = gmdate('Y-m-d H:i:s');
        $this->logProvision(sprintf(
            '[staging_clone_start] template_id=%d slug=%s staging=%s cmd=%s',
            (int) $template->id,
            $template->slug,
            $template->staging_domain,
            $cmd
        ));

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $outputText = implode("\n", $output);
        $parsed = $this->parseStagingCloneOutput($outputText);

        $this->logProvision(sprintf(
            '[staging_clone_finish] template_id=%d slug=%s exit=%d started=%s output=%s',
            (int) $template->id,
            $template->slug,
            $code,
            $started,
            $outputText !== '' ? $outputText : '(empty)'
        ));

        if ($code !== 0 || !preg_match('/^STAGING_CLONE_OK/m', $outputText)) {
            $this->Input->setErrors([
                'staging' => [
                    'failed' => Language::_('FalconWpTemplates.!error.staging.failed', true, $outputText)
                ]
            ]);
            return false;
        }

        return $parsed ?: ['status' => 'created'];
    }

    /**
     * @param string $staging_domain
     * @return bool
     */
    public function stagingSiteReady($staging_domain)
    {
        $domain = strtolower(trim((string) $staging_domain));
        if ($domain === '') {
            return false;
        }

        $docroot = '/home/falcon.codes/' . $domain . '/wp-config.php';
        $cmd = sprintf('test -f %s', escapeshellarg($docroot));
        exec($cmd, $unused, $code);

        return $code === 0;
    }

    /**
     * @param string $staging_domain
     * @return array|null
     */
    public function getStagingCredentials($staging_domain)
    {
        $domain = strtolower(trim((string) $staging_domain));
        if ($domain === '' || !preg_match('/\.falcon\.codes$/', $domain)) {
            return null;
        }

        $sub = preg_replace('/\.falcon\.codes$/', '', $domain);
        $secretFile = self::STAGING_SECRETS_DIR . '/staging-' . $sub . '.env';
        if (!is_readable($secretFile)) {
            return null;
        }

        $creds = [];
        foreach (file($secretFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $creds[$key] = $value;
        }

        return $creds ?: null;
    }

    /**
     * @param string $outputText
     * @return array
     */
    private function parseStagingCloneOutput($outputText)
    {
        $parsed = [];
        foreach (explode("\n", $outputText) as $line) {
            if (strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $parsed[$key] = $value;
        }

        return $parsed;
    }

    /**
     * Run snapshot_capture.sh and update last_captured.
     *
     * @param int $template_id
     * @return array|false Output lines on success
     */
    public function capture($template_id)
    {
        if (!($template = $this->get($template_id))) {
            $this->Input->setErrors(['template' => ['missing' => Language::_('FalconWpTemplates.!error.template.missing', true)]]);
            return false;
        }

        $cmd = sprintf(
            'sudo %s --domain %s --slug %s --name %s --description %s %s 2>&1',
            escapeshellarg(self::CAPTURE_BIN),
            escapeshellarg($template->staging_domain),
            escapeshellarg($template->slug),
            escapeshellarg($template->name),
            escapeshellarg($template->description ?? ''),
            $template->thumbnail_url
                ? '--thumbnail-url ' . escapeshellarg($template->thumbnail_url)
                : ''
        );

        $started = gmdate('Y-m-d H:i:s');
        $this->logCapture(sprintf(
            '[start] template_id=%d slug=%s staging=%s cmd=%s',
            (int) $template_id,
            $template->slug,
            $template->staging_domain,
            $cmd
        ));

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $outputText = implode("\n", $output);

        $this->logCapture(sprintf(
            '[finish] template_id=%d slug=%s exit=%d started=%s output=%s',
            (int) $template_id,
            $template->slug,
            $code,
            $started,
            $outputText !== '' ? $outputText : '(empty)'
        ));

        if ($code !== 0 || !preg_match('/^CAPTURE_OK/m', $outputText)) {
            $this->Input->setErrors([
                'capture' => [
                    'failed' => Language::_('FalconWpTemplates.!error.capture.failed', true, $outputText)
                ]
            ]);
            return false;
        }

        $this->Record->where('id', '=', $template_id)->update('falcon_wp_templates', [
            'last_captured' => $this->mysqlNow(),
            'date_updated' => $this->mysqlNow(),
        ]);

        $this->syncMetaActive($template->slug, (bool) $template->active, $template);

        return $output;
    }

    /**
     * Append a line to the falcon-provisioner log (best-effort).
     *
     * @param string $line
     */
    private function logProvision($line)
    {
        $entry = sprintf("[%s] [blesta_admin_test] %s\n", gmdate('Y-m-d H:i:s'), $line);
        @file_put_contents(self::PROVISION_LOG, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Append a line to the VPS capture log (best-effort).
     *
     * @param string $line
     */
    private function logCapture($line)
    {
        $entry = sprintf("[%s] [blesta_capture] %s\n", gmdate('Y-m-d H:i:s'), $line);
        @file_put_contents(self::CAPTURE_LOG, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param string $slug
     * @param bool $active
     * @param stdClass|null $template
     */
    public function syncMetaActive($slug, $active, $template = null)
    {
        $metaPath = self::STORAGE_ROOT . DS . $slug . DS . 'meta.json';
        if (!is_readable($metaPath)) {
            return;
        }

        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($meta)) {
            return;
        }

        $patch = ['active' => $active];
        if ($template) {
            $patch['name'] = $template->name;
            $patch['description'] = $template->description;
            $patch['thumbnail_url'] = $template->thumbnail_url;
            $patch['staging_domain'] = $template->staging_domain;
            $patch['sort_order'] = (int) $template->sort_order;
        }

        $json = json_encode($patch, JSON_UNESCAPED_SLASHES);
        $cmd = sprintf(
            'sudo %s %s %s 2>&1',
            escapeshellarg(self::SYNC_META_BIN),
            escapeshellarg($slug),
            escapeshellarg($json)
        );
        exec($cmd, $unused, $code);
        if ($code !== 0) {
            $this->logProvision(sprintf('[sync_meta_failed] slug=%s exit=%d', $slug, $code));
        }
    }

    /**
     * @param int $template_id
     * @return bool
     */
    public function delete($template_id)
    {
        if (!($template = $this->get($template_id))) {
            return false;
        }

        $slug = $template->slug;
        $this->Record->from('falcon_wp_templates')->where('id', '=', $template_id)->delete();
        $this->removeStorageDir($slug);

        return true;
    }

    /**
     * Remove captured template files when the catalog row is deleted.
     *
     * @param string $slug
     * @return bool
     */
    private function removeStorageDir($slug)
    {
        $dir = self::STORAGE_ROOT . DS . $slug;
        if (!is_dir($dir)) {
            return true;
        }

        $cmd = sprintf('sudo rm -rf %s 2>&1', escapeshellarg($dir));
        exec($cmd, $output, $code);

        return $code === 0;
    }

    /**
     * @param array $vars
     * @param bool $edit
     * @param int|null $template_id
     * @return array
     */
    private function getRules(array $vars, $edit = false, $template_id = null)
    {
        $rules = [
            'company_id' => [
                'valid' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'companies'],
                    'message' => Language::_('FalconWpTemplates.!error.company_id.valid', true)
                ]
            ],
            'slug' => [
                'format' => [
                    'rule' => ['matches', '/^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/'],
                    'message' => Language::_('FalconWpTemplates.!error.slug.format', true)
                ]
            ],
            'name' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('FalconWpTemplates.!error.name.empty', true)
                ]
            ],
            'staging_domain' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('FalconWpTemplates.!error.staging_domain.empty', true)
                ]
            ],
        ];

        if ($edit) {
            unset($rules['company_id'], $rules['slug']);
            $rules = array_intersect_key($rules, $vars);
        }

        return $rules;
    }

    /**
     * @param array $vars
     * @param bool $edit
     * @return array
     */
    private function normalizeVars(array $vars, $edit = false)
    {
        if (!$edit && isset($vars['slug'])) {
            $vars['slug'] = strtolower(trim((string) $vars['slug']));
        }

        if (isset($vars['staging_domain'])) {
            $vars['staging_domain'] = strtolower(trim((string) $vars['staging_domain']));
        }

        foreach (['description', 'thumbnail_url'] as $nullable) {
            if (array_key_exists($nullable, $vars) && $vars[$nullable] === '') {
                $vars[$nullable] = null;
            }
        }

        return $vars;
    }
}
