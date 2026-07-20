<?php
/**
 * Falcon Theme Snapshots plugin handler
 *
 * @package blesta
 * @subpackage plugins.falconthemesnapshots
 * @copyright Copyright (c) 2026, Falcon of Codes
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link https://falcon.codes/
 */
class FalconThemeSnapshotsPlugin extends Plugin
{
    public function __construct()
    {
        Language::loadLang('falcon_theme_snapshots_plugin', null, dirname(__FILE__) . DS . 'language' . DS);
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        Loader::loadComponents($this, ['Input', 'Record']);
    }

    /**
     * @param int $plugin_id The ID of the plugin being installed
     */
    public function install($plugin_id)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        try {
            $this->installBlestaThemeTables();
            $this->installWpTemplateTables();
        } catch (Exception $e) {
            $this->Input->setErrors(['db' => ['create' => $e->getMessage()]]);
            return;
        }
    }

    /**
     * @param int $plugin_id
     * @param bool $last_instance
     */
    public function uninstall($plugin_id, $last_instance)
    {
        if (!$last_instance) {
            return;
        }

        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        try {
            $this->Record->drop('falcon_theme_snapshot_files');
            $this->Record->drop('falcon_theme_snapshots');
            $this->Record->drop('falcon_wp_templates');
        } catch (Exception $e) {
            $this->Input->setErrors(['db' => ['drop' => $e->getMessage()]]);
            return;
        }
    }

    /**
     * @param string $current_version
     * @param int $plugin_id
     */
    public function upgrade($current_version, $plugin_id)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        if (version_compare($this->getVersion(), $current_version, '>')) {
            if (version_compare($current_version, '2.0.0', '<')) {
                try {
                    $this->installWpTemplateTables();
                } catch (Exception $e) {
                    $this->Input->setErrors(['db' => ['upgrade' => $e->getMessage()]]);
                    return;
                }
            }
        }
    }

    /**
     * Phase 1 — Blesta client theme file snapshots (secondary).
     */
    private function installBlestaThemeTables()
    {
        $this->Record->
            setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])->
            setField('company_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])->
            setField('label', ['type' => 'varchar', 'size' => 255])->
            setField('template_dir', ['type' => 'varchar', 'size' => 64, 'default' => 'falcon'])->
            setField('blesta_version', ['type' => 'varchar', 'size' => 32, 'is_null' => true, 'default' => null])->
            setField('staff_id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])->
            setField('notes', ['type' => 'text', 'is_null' => true, 'default' => null])->
            setField('file_count', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0])->
            setField('total_bytes', ['type' => 'bigint', 'size' => 20, 'unsigned' => true, 'default' => 0])->
            setField('status', ['type' => 'enum', 'size' => "'pending','complete','failed'", 'default' => 'pending'])->
            setField('date_created', ['type' => 'datetime'])->
            setField('date_updated', ['type' => 'datetime', 'is_null' => true, 'default' => null])->
            setKey(['id'], 'primary')->
            setKey(['company_id'], 'index')->
            setKey(['date_created'], 'index')->
            create('falcon_theme_snapshots', true);

        $this->Record->
            setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])->
            setField('snapshot_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])->
            setField('relative_path', ['type' => 'varchar', 'size' => 512])->
            setField('content_hash', ['type' => 'char', 'size' => 64])->
            setField('content', ['type' => 'mediumtext'])->
            setField('file_size', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0])->
            setField('is_customized', ['type' => 'tinyint', 'size' => 1, 'default' => 0])->
            setField('date_created', ['type' => 'datetime'])->
            setKey(['id'], 'primary')->
            setKey(['snapshot_id', 'relative_path'], 'unique')->
            create('falcon_theme_snapshot_files', true);
    }

    /**
     * Phase 2 — WordPress template catalog (primary).
     */
    private function installWpTemplateTables()
    {
        $this->Record->
            setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])->
            setField('company_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])->
            setField('slug', ['type' => 'varchar', 'size' => 64])->
            setField('name', ['type' => 'varchar', 'size' => 255])->
            setField('description', ['type' => 'text', 'is_null' => true, 'default' => null])->
            setField('thumbnail_url', ['type' => 'varchar', 'size' => 512, 'is_null' => true, 'default' => null])->
            setField('staging_domain', ['type' => 'varchar', 'size' => 255])->
            setField('storage_dir', ['type' => 'varchar', 'size' => 512])->
            setField('active', ['type' => 'tinyint', 'size' => 1, 'default' => 0])->
            setField('sort_order', ['type' => 'int', 'size' => 10, 'default' => 0])->
            setField('last_captured', ['type' => 'datetime', 'is_null' => true, 'default' => null])->
            setField('date_created', ['type' => 'datetime'])->
            setField('date_updated', ['type' => 'datetime', 'is_null' => true, 'default' => null])->
            setKey(['id'], 'primary')->
            setKey(['company_id', 'slug'], 'unique')->
            create('falcon_wp_templates', true);
    }

    /**
     * @return array
     */
    public function getActions()
    {
        return [
            [
                'action' => 'nav_secondary_staff',
                'uri' => 'plugin/falcon_theme_snapshots/admin_main/',
                'name' => 'FalconThemeSnapshotsPlugin.admin_main',
                'options' => ['parent' => 'tools/']
            ]
        ];
    }

    /**
     * @return array
     */
    public function getPermissions()
    {
        return [
            [
                'group_alias' => 'admin_tools',
                'name' => Language::_('FalconThemeSnapshotsPlugin.name', true),
                'alias' => 'falcon_theme_snapshots.admin_main',
                'action' => '*'
            ]
        ];
    }
}
