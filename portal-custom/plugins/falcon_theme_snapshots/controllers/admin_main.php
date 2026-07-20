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

            $template = $this->FalconWpTemplates->add($vars);

            if ($template === false || (($errors = $this->FalconWpTemplates->errors()) && !isset($errors['staging']))) {
                $this->setMessage('error', $this->FalconWpTemplates->errors(), false, null, false);
                $this->set('vars', (object) $this->post);
            } else {
                $this->flashStagingProvisionMessage($template);
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

        $template_id = (int) $this->post['template_id'];
        $output = $this->FalconWpTemplates->capture($template_id);

        if ($output === false) {
            $errors = $this->FalconWpTemplates->errors();
            $message = $errors['capture']['failed'] ?? Language::_('AdminMain.!error.capture.failed', true);
            $this->flashMessage('error', $message, null, false);
            $this->outputAsJson([
                'success' => false,
                'message' => $message,
            ]);
            return false;
        }

        $message = Language::_('AdminMain.!success.capture', true);
        $this->flashMessage('message', $message, null, false);
        $this->outputAsJson([
            'success' => true,
            'message' => $message,
            'output' => $output,
        ]);
        return false;
    }

    /**
     * Update an existing staging template.
     */
    public function edit()
    {
        if (!isset($this->get[0]) || !($template = $this->FalconWpTemplates->get((int) $this->get[0]))) {
            $this->redirect($this->base_uri . 'plugin/falcon_theme_snapshots/admin_main/');
        }

        if ((int) $template->company_id !== (int) $this->company_id) {
            $this->redirect($this->base_uri . 'plugin/falcon_theme_snapshots/admin_main/');
        }

        if (!empty($this->post)) {
            $this->FalconWpTemplates->edit($template->id, $this->post);

            $updated = $this->FalconWpTemplates->edit($template->id, $this->post);

            if ($updated === false || (($errors = $this->FalconWpTemplates->errors()) && !isset($errors['staging']))) {
                $this->setMessage('error', $this->FalconWpTemplates->errors(), false, null, false);
                $this->set('vars', (object) array_merge((array) $template, $this->post));
            } else {
                $this->flashStagingProvisionMessage($updated ?: $template);
                $this->flashMessage('message', Language::_('AdminMain.!success.template_updated', true), null, false);
                $this->redirect($this->base_uri . 'plugin/falcon_theme_snapshots/admin_main/');
            }
        } else {
            $this->set('vars', $template);
        }

        $this->set('template', $template);
    }

    /**
     * Remove a template from the catalog and optional storage.
     */
    public function delete()
    {
        if (!isset($this->get[0]) || !($template = $this->FalconWpTemplates->get((int) $this->get[0]))) {
            $this->redirect($this->base_uri . 'plugin/falcon_theme_snapshots/admin_main/');
        }

        if ((int) $template->company_id !== (int) $this->company_id) {
            $this->redirect($this->base_uri . 'plugin/falcon_theme_snapshots/admin_main/');
        }

        if (!$this->FalconWpTemplates->delete($template->id)) {
            $this->flashMessage('error', Language::_('AdminMain.!error.delete.failed', true), null, false);
        } else {
            $this->flashMessage('message', Language::_('AdminMain.!success.template_deleted', true), null, false);
        }

        $this->redirect($this->base_uri . 'plugin/falcon_theme_snapshots/admin_main/');
    }

    /**
     * Toggle catalog visibility.
     */
    public function activate()
    {
        if (!isset($this->get[0]) || !($template = $this->FalconWpTemplates->get((int) $this->get[0]))) {
            $this->redirect($this->base_uri . 'plugin/falcon_theme_snapshots/admin_main/');
        }

        if ((int) $template->company_id !== (int) $this->company_id) {
            $this->redirect($this->base_uri . 'plugin/falcon_theme_snapshots/admin_main/');
        }

        $active = (isset($this->get[1]) && $this->get[1] === '1') ? 1 : 0;
        $updated = $this->FalconWpTemplates->setActive($template->id, $active);

        if ($updated === false || ($errors = $this->FalconWpTemplates->errors())) {
            $this->flashMessage(
                'error',
                $errors['active']['failed'] ?? Language::_('AdminMain.!error.activate.failed', true),
                null,
                false
            );
        } else {
            $this->flashMessage(
                'message',
                $active
                    ? Language::_('AdminMain.!success.activated', true)
                    : Language::_('AdminMain.!success.deactivated', true),
                null,
                false
            );
        }

        $this->redirect($this->base_uri . 'plugin/falcon_theme_snapshots/admin_main/');
    }

    /**
     * AJAX: clone golden staging-base to template staging domain.
     */
    public function provision_staging()
    {
        $this->requireAjax();

        if (!isset($this->post['template_id'])) {
            $this->outputAsJson(['success' => false, 'message' => 'missing template_id']);
            return false;
        }

        $template = $this->FalconWpTemplates->get((int) $this->post['template_id']);
        if (!$template || (int) $template->company_id !== (int) $this->company_id) {
            $this->outputAsJson(['success' => false, 'message' => Language::_('AdminMain.!error.staging.not_found', true)]);
            return false;
        }

        $result = $this->FalconWpTemplates->provisionStaging($template);
        if ($result === false) {
            $errors = $this->FalconWpTemplates->errors();
            $message = $errors['staging']['failed'] ?? Language::_('AdminMain.!error.staging.failed', true);
            $this->outputAsJson(['success' => false, 'message' => $message]);
            return false;
        }

        $message = Language::_('AdminMain.!success.staging', true);
        if (!empty($result['wp_admin_url'])) {
            $message = Language::_('AdminMain.!success.staging_with_url', true, $result['wp_admin_url']);
        }

        $this->flashMessage('message', $message, null, false);
        $this->outputAsJson([
            'success' => true,
            'message' => $message,
            'result' => $result,
        ]);
        return false;
    }

    /**
     * @param stdClass|false|null $template
     */
    private function flashStagingProvisionMessage($template)
    {
        if (!$template) {
            return;
        }

        $errors = $this->FalconWpTemplates->errors();
        if (!empty($errors['staging']['failed'])) {
            $this->flashMessage('error', $errors['staging']['failed'], null, false);
            return;
        }

        if (!$this->FalconWpTemplates->stagingSiteReady($template->staging_domain)) {
            return;
        }

        $creds = $this->FalconWpTemplates->getStagingCredentials($template->staging_domain);
        $adminUrl = $creds['WP_ADMIN_URL'] ?? ('https://' . $template->staging_domain . '/wp-admin/');
        $this->flashMessage(
            'message',
            Language::_('AdminMain.!success.staging_ready', true, $adminUrl),
            null,
            false
        );
    }

    /**
     * Staff guide: workflow, field reference, and admin test provision.
     */
    public function guide()
    {
        $prefill_theme = '';
        if (isset($this->get['theme'])) {
            $prefill_theme = strtolower(trim((string) $this->get['theme']));
        } elseif (isset($this->get[0]) && $this->get[0] !== '') {
            $prefill_theme = strtolower(trim((string) $this->get[0]));
        }

        $this->set('templates', $this->FalconWpTemplates->getAllForSelect($this->company_id));
        $this->set('prefill_theme', $prefill_theme);
        $this->set('catalog_url', 'https://falcon.codes/api/themes/catalog.php');
    }

    /**
     * Admin-only AJAX: provision a test trial site for a theme slug.
     */
    public function test_provision()
    {
        $this->requireAjax();

        $subdomain = isset($this->post['subdomain'])
            ? strtolower(trim((string) $this->post['subdomain']))
            : '';
        $theme_slug = isset($this->post['theme_slug'])
            ? strtolower(trim((string) $this->post['theme_slug']))
            : '';

        $result = $this->FalconWpTemplates->adminTestProvision(
            $this->company_id,
            $subdomain,
            $theme_slug
        );

        if ($result === false) {
            $errors = $this->FalconWpTemplates->errors();
            $message = $errors['test']['failed']
                ?? $errors['test']['invalid']
                ?? Language::_('AdminMain.!error.test.failed', true);
            $this->outputAsJson([
                'success' => false,
                'message' => $message,
            ]);
            return false;
        }

        $result['blesta_client_url'] = $this->base_uri
            . ltrim($result['blesta_service_url'] ?? '', '/');

        $this->outputAsJson([
            'success' => true,
            'message' => Language::_('AdminMain.!success.test', true),
            'result' => $result,
        ]);
        return false;
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
