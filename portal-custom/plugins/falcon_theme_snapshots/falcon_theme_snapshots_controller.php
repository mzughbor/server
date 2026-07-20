<?php
/**
 * Falcon Theme Snapshots parent controller
 *
 * @package blesta
 * @subpackage plugins.falconthemesnapshots
 * @copyright Copyright (c) 2026, Falcon of Codes
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link https://falcon.codes/
 */
class FalconThemeSnapshotsController extends AppController
{
    public function preAction()
    {
        $this->structure->setDefaultView(APPDIR);
        parent::preAction();

        Language::loadLang(
            [Loader::fromCamelCase(get_class($this))],
            null,
            dirname(__FILE__) . DS . 'language' . DS
        );

        $this->view->view = 'default';
        $this->orig_structure_view = $this->structure->view;
        $this->structure->view = 'default';

        // Restore admin portal structure (header, nav, sidebar widgets)
        $this->structure->setDefaultView(APPDIR);
        $this->structure->setView(null, $this->orig_structure_view);

        $this->company_id = Configure::get('Blesta.company_id');
    }

    /**
     * Reject non-AJAX access to JSON endpoints (Blesta uses isAjax(), not requireAjax()).
     *
     * @return bool True when the request may continue
     */
    protected function requireJsonAjax()
    {
        if (!$this->isAjax()) {
            $this->outputAsJson([
                'success' => false,
                'message' => 'Invalid request.',
            ]);
            return false;
        }

        return true;
    }
}
