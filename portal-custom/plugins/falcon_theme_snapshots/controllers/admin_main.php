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

        $this->uses([
            'FalconThemeSnapshots.FalconWpTemplates',
            'FalconThemeSnapshots.FalconThemeSnapshots',
        ]);

        $this->helpers(['Form', 'Html']);

        $this->structure->set(
            'page_title',
            Language::_('AdminMain.index.page_title', true)
        );
    }

    /**
     * WordPress template catalog (primary).
     */
    public function index()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);

        $this->set('templates', $this->FalconWpTemplates->getList($this->company_id, $page));
        $this->set('total_results', $this->FalconWpTemplates->getListCount($this->company_id));
        $this->set('page', $page);
        $this->set('catalog_url', 'https://falcon.codes/api/themes/catalog.php');
    }

    /**
     * Register a staging site for capture.
     */
    public function add()
    {
        if (!empty($this->post)) {
            $vars = $this->post;
            $vars['company_id'] = $this->company_id;

            $this->FalconWpTemplates->add($vars);

            if (($errors = $this->FalconWpTemplates->errors())) {
                $this->setMessage('error', $errors, false, null, false);
                $this->set('vars', (object) $this->post);
            } else {
                $this->flashMessage('message', Language::_('AdminMain.!success.template_added', true), null, false);
                $this->redirect($this->base_uri . 'plugin/falcon_theme_snapshots/admin_main/');
            }
        }
    }

    /**
     * Capture snapshot from staging domain.
     */
    public function capture()
    {
        $this->requireAjax();

        if (!isset($this->post['template_id'])) {
            $this->outputAsJson(['success' => false, 'message' => 'missing template_id']);
            return false;
        }

        $output = $this->FalconWpTemplates->capture((int) $this->post['template_id']);

        if ($output === false) {
            $errors = $this->FalconWpTemplates->errors();
            $this->outputAsJson([
                'success' => false,
                'message' => $errors['capture']['failed'] ?? Language::_('AdminMain.!error.capture.failed', true),
            ]);
            return false;
        }

        $this->outputAsJson([
            'success' => true,
            'message' => Language::_('AdminMain.!success.capture', true),
            'output' => $output,
        ]);
        return false;
    }

    /**
     * Toggle catalog visibility.
     */
    public function activate()
    {
        if (!isset($this->get[0]) || !($template = $this->FalconWpTemplates->get((int) $this->get[0]))) {
            $this->redirect($this->base_uri . 'plugin/falcon_theme_snapshots/admin_main/');
        }

        $active = (isset($this->get[1]) && $this->get[1] === '1') ? 1 : 0;
        $this->FalconWpTemplates->edit($template->id, ['active' => $active]);

        $this->flashMessage(
            'message',
            $active
                ? Language::_('AdminMain.!success.activated', true)
                : Language::_('AdminMain.!success.deactivated', true),
            null,
            false
        );
        $this->redirect($this->base_uri . 'plugin/falcon_theme_snapshots/admin_main/');
    }

    /**
     * Blesta client theme snapshots (secondary, Phase 1).
     */
    public function blesta()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = ($this->get['sort'] ?? 'date_created');
        $order = ($this->get['order'] ?? 'desc');

        $this->set('snapshots', $this->FalconThemeSnapshots->getList(
            $this->company_id,
            $page,
            [$sort => $order]
        ));
        $this->set('total_results', $this->FalconThemeSnapshots->getListCount($this->company_id));
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('page', $page);
    }
}
