<?php
/**
 * Falcon Theme Snapshots admin controller
 *
 * @package blesta
 * @subpackage plugins.falconthemesnapshots
 * @copyright Copyright (c) 2026, Falcon of Codes
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link https://falcon.codes/
 */
class AdminMain extends FalconThemeSnapshotsController
{
    public function preAction()
    {
        parent::preAction();

        $this->requireLogin();

        $this->uses(['FalconThemeSnapshots.FalconThemeSnapshots']);

        $this->structure->set(
            'page_title',
            Language::_('AdminMain.index.page_title', true)
        );
    }

    /**
     * Lists saved snapshots (Phase 1 placeholder UI).
     */
    public function index()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = ($this->get['sort'] ?? 'date_created');
        $order = ($this->get['order'] ?? 'desc');

        $snapshots = $this->FalconThemeSnapshots->getList(
            $this->company_id,
            $page,
            [$sort => $order]
        );
        $total_results = $this->FalconThemeSnapshots->getListCount($this->company_id);

        $this->set('snapshots', $snapshots);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('page', $page);
        $this->set('total_results', $total_results);
    }
}
