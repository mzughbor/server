<?php
/**
 * Falcon Theme Snapshots parent model
 *
 * @package blesta
 * @subpackage plugins.falconthemesnapshots
 * @copyright Copyright (c) 2026, Falcon of Codes
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link https://falcon.codes/
 */
class FalconThemeSnapshotsModel extends AppModel
{
    /**
     * Client template files we track for branding customizations.
     * Phase 2 capture logic uses this list as the default scope.
     */
    public const CUSTOMIZED_FILES = [
        'config.json',
        'css/overrides.css',
        'structure.pdt',
        'client_login.pdt',
    ];

    public function __construct()
    {
        Loader::loadComponents($this, ['Input', 'Record']);
        Loader::loadHelpers($this, ['Date']);
    }

    /**
     * MySQL datetime column value (Y-m-d H:i:s).
     *
     * @return string
     */
    protected function mysqlNow()
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * @return array|false
     */
    public function errors()
    {
        return $this->Input->errors();
    }
}
