<?php
/**
 * Falcon Theme Snapshots — per-file snapshot contents
 *
 * @package blesta
 * @subpackage plugins.falconthemesnapshots.models
 * @copyright Copyright (c) 2026, Falcon of Codes
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link https://falcon.codes/
 */
class FalconThemeSnapshotFiles extends FalconThemeSnapshotsModel
{
    public function __construct()
    {
        parent::__construct();

        Language::loadLang(
            'falcon_theme_snapshot_files',
            null,
            PLUGINDIR . 'falcon_theme_snapshots' . DS . 'language' . DS
        );
    }

    /**
     * Adds a file record to a snapshot.
     *
     * @param array $vars Input vars:
     *  - snapshot_id (required)
     *  - relative_path (required)
     *  - content (required)
     *  - is_customized (optional, default 0)
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
            'snapshot_id' => $vars['snapshot_id'],
            'relative_path' => $vars['relative_path'],
            'content_hash' => hash('sha256', $vars['content']),
            'content' => $vars['content'],
            'file_size' => strlen($vars['content']),
            'is_customized' => (int) ($vars['is_customized'] ?? 0),
            'date_created' => date('c'),
        ];

        $this->Record->insert('falcon_theme_snapshot_files', $fields);
        return $this->get($this->Record->lastInsertId());
    }

    /**
     * @param int $file_id
     * @return stdClass|false
     */
    public function get($file_id)
    {
        return $this->Record->select()->from('falcon_theme_snapshot_files')->
            where('id', '=', $file_id)->fetch();
    }

    /**
     * @param int $snapshot_id
     * @return array
     */
    public function getAllBySnapshot($snapshot_id)
    {
        return $this->Record->select()->from('falcon_theme_snapshot_files')->
            where('snapshot_id', '=', $snapshot_id)->
            order(['relative_path' => 'asc'])->
            fetchAll();
    }

    /**
     * @param int $snapshot_id
     * @param string $relative_path
     * @return stdClass|false
     */
    public function getByPath($snapshot_id, $relative_path)
    {
        return $this->Record->select()->from('falcon_theme_snapshot_files')->
            where('snapshot_id', '=', $snapshot_id)->
            where('relative_path', '=', $relative_path)->
            fetch();
    }

    /**
     * @param int $snapshot_id
     * @return bool
     */
    public function deleteBySnapshot($snapshot_id)
    {
        $this->Record->from('falcon_theme_snapshot_files')->
            where('snapshot_id', '=', $snapshot_id)->
            delete();

        return true;
    }

    /**
     * @param array $vars
     * @return array
     */
    private function normalizeVars(array $vars)
    {
        $vars['relative_path'] = isset($vars['relative_path'])
            ? ltrim(str_replace('\\', '/', $vars['relative_path']), '/')
            : '';

        return $vars;
    }

    /**
     * @param array $vars
     * @return array
     */
    private function getRules(array $vars)
    {
        return [
            'snapshot_id' => [
                'valid' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'falcon_theme_snapshots'],
                    'message' => Language::_('FalconThemeSnapshotFiles.!error.snapshot_id.valid', true)
                ]
            ],
            'relative_path' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('FalconThemeSnapshotFiles.!error.relative_path.empty', true)
                ],
                'format' => [
                    'rule' => ['matches', '/^(?!\\.\\.)[a-zA-Z0-9_\\-\\/\\.]+$/'],
                    'message' => Language::_('FalconThemeSnapshotFiles.!error.relative_path.format', true)
                ]
            ],
            'content' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('FalconThemeSnapshotFiles.!error.content.valid', true)
                ]
            ],
        ];
    }
}
