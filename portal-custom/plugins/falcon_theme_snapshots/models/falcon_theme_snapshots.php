<?php
/**
 * Falcon Theme Snapshots — snapshot metadata
 *
 * @package blesta
 * @subpackage plugins.falconthemesnapshots.models
 * @copyright Copyright (c) 2026, Falcon of Codes
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link https://falcon.codes/
 */
class FalconThemeSnapshots extends FalconThemeSnapshotsModel
{
    public function __construct()
    {
        parent::__construct();

        Language::loadLang(
            'falcon_theme_snapshots',
            null,
            PLUGINDIR . 'falcon_theme_snapshots' . DS . 'language' . DS
        );
    }

    /**
     * Creates a snapshot row.
     *
     * @param array $vars Input vars:
     *  - company_id (required)
     *  - label (required)
     *  - template_dir (optional, default falcon)
     *  - blesta_version (optional)
     *  - staff_id (optional)
     *  - notes (optional)
     *  - status (optional, default pending)
     * @return stdClass|false The created snapshot or false on error
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
            'label' => $vars['label'],
            'template_dir' => $vars['template_dir'],
            'blesta_version' => $vars['blesta_version'],
            'staff_id' => $vars['staff_id'],
            'notes' => $vars['notes'],
            'file_count' => 0,
            'total_bytes' => 0,
            'status' => $vars['status'],
            'date_created' => $this->mysqlNow(),
        ];

        $this->Record->insert('falcon_theme_snapshots', $fields);
        return $this->get($this->Record->lastInsertId());
    }

    /**
     * Updates snapshot metadata and optional counters.
     *
     * @param int $snapshot_id
     * @param array $vars Fields to update
     * @return stdClass|false
     */
    public function edit($snapshot_id, array $vars)
    {
        if (!($snapshot = $this->get($snapshot_id))) {
            return false;
        }

        $vars = $this->normalizeVars($vars, false);
        $this->Input->setRules($this->getRules($vars, true));

        if (!$this->Input->validates($vars)) {
            return false;
        }

        $fields = array_intersect_key(
            $vars,
            array_flip(['label', 'template_dir', 'blesta_version', 'staff_id', 'notes', 'file_count', 'total_bytes', 'status'])
        );
        $fields['date_updated'] = $this->mysqlNow();

        $this->Record->where('id', '=', $snapshot_id)->update('falcon_theme_snapshots', $fields);
        return $this->get($snapshot_id);
    }

    /**
     * @param int $snapshot_id
     * @return stdClass|false
     */
    public function get($snapshot_id)
    {
        return $this->Record->select()->from('falcon_theme_snapshots')->
            where('id', '=', $snapshot_id)->fetch();
    }

    /**
     * @param int $company_id
     * @param int $page
     * @param array $order_by
     * @return array
     */
    public function getList($company_id, $page = 1, array $order_by = ['date_created' => 'desc'])
    {
        return $this->Record->select()->from('falcon_theme_snapshots')->
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
        return $this->Record->select(['id'])->from('falcon_theme_snapshots')->
            where('company_id', '=', $company_id)->
            numResults();
    }

    /**
     * Deletes a snapshot and its files (Phase 2 may offload file purge to the files model).
     *
     * @param int $snapshot_id
     * @return bool
     */
    public function delete($snapshot_id)
    {
        if (!($snapshot = $this->get($snapshot_id))) {
            return false;
        }

        $this->Record->begin();
        $this->Record->from('falcon_theme_snapshot_files')->where('snapshot_id', '=', $snapshot_id)->delete();
        $this->Record->from('falcon_theme_snapshots')->where('id', '=', $snapshot_id)->delete();
        $this->Record->commit();

        return true;
    }

    /**
     * @param array $vars
     * @param bool $edit
     * @return array
     */
    private function normalizeVars(array $vars, $edit = false)
    {
        if (!$edit) {
            $vars['template_dir'] = $vars['template_dir'] ?? 'falcon';
            $vars['status'] = $vars['status'] ?? 'pending';
        }

        foreach (['blesta_version', 'staff_id', 'notes'] as $nullable) {
            if (array_key_exists($nullable, $vars) && $vars[$nullable] === '') {
                $vars[$nullable] = null;
            }
        }

        return $vars;
    }

    /**
     * @param array $vars
     * @param bool $edit
     * @return array
     */
    private function getRules(array $vars, $edit = false)
    {
        $rules = [
            'company_id' => [
                'valid' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'companies'],
                    'message' => Language::_('FalconThemeSnapshots.!error.company_id.valid', true)
                ]
            ],
            'label' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('FalconThemeSnapshots.!error.label.empty', true)
                ]
            ],
            'template_dir' => [
                'format' => [
                    'rule' => ['matches', '/^[a-z0-9_\\-]+$/i'],
                    'message' => Language::_('FalconThemeSnapshots.!error.template_dir.format', true)
                ]
            ],
            'status' => [
                'valid' => [
                    'rule' => ['in_array', ['pending', 'complete', 'failed']],
                    'message' => Language::_('FalconThemeSnapshots.!error.status.valid', true)
                ]
            ],
        ];

        if ($edit) {
            unset($rules['company_id']);
        }

        return $rules;
    }
}
