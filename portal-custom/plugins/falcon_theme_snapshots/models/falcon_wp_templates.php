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
        return $this->get($this->Record->lastInsertId());
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
     * @param int $company_id
     * @param int $page
     * @param array $order_by
     * @return array
     */
    public function getList($company_id, $page = 1, array $order_by = ['sort_order' => 'asc'])
    {
        return $this->Record->select()->from('falcon_wp_templates')->
            where('company_id', '=', $company_id)->
            order($order_by)->
            limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())->
            fetchAll();
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

        $meta['active'] = $active;
        if ($template) {
            $meta['name'] = $template->name;
            $meta['description'] = $template->description;
            $meta['thumbnail_url'] = $template->thumbnail_url;
            $meta['staging_domain'] = $template->staging_domain;
            $meta['sort_order'] = (int) $template->sort_order;
        }

        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
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
