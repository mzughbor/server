<?php
$lang['AdminMain.index.page_title'] = 'Falcon Theme Snapshots';
$lang['AdminMain.index.tab_wp'] = 'WordPress Templates';
$lang['AdminMain.index.tab_blesta'] = 'Blesta Theme Files';
$lang['AdminMain.index.boxtitle_wp'] = 'WordPress Template Catalog';
$lang['AdminMain.index.catalog_hint'] = 'Public catalog API (active templates only):';
$lang['AdminMain.index.link_add'] = 'Add Staging Template';
$lang['AdminMain.index.heading_name'] = 'Name';
$lang['AdminMain.index.heading_slug'] = 'Slug';
$lang['AdminMain.index.heading_staging'] = 'Staging Domain';
$lang['AdminMain.index.heading_active'] = 'Catalog';
$lang['AdminMain.index.heading_captured'] = 'Last Capture';
$lang['AdminMain.index.heading_sort'] = 'Sort';
$lang['AdminMain.index.heading_thumb'] = 'Preview';
$lang['AdminMain.index.heading_options'] = 'Actions';
$lang['AdminMain.index.yes'] = 'Active';
$lang['AdminMain.index.no'] = 'Inactive';
$lang['AdminMain.index.action_capture'] = 'Capture';
$lang['AdminMain.index.capture_running'] = 'Capturing…';
$lang['AdminMain.index.action_activate'] = 'Activate';
$lang['AdminMain.index.action_deactivate'] = 'Deactivate';
$lang['AdminMain.index.action_edit'] = 'Edit';
$lang['AdminMain.index.action_delete'] = 'Delete';
$lang['AdminMain.index.confirm_delete'] = 'Delete "%1$s" from the catalog? This removes the database record and any captured files in storage.';
$lang['AdminMain.index.no_templates'] = 'No WordPress templates yet. Register a staging site, then run Capture to publish it to the catalog.';

$lang['AdminMain.add.boxtitle'] = 'Add WordPress Staging Template';
$lang['AdminMain.add.field_slug'] = 'Slug';
$lang['AdminMain.add.field_name'] = 'Display Name';
$lang['AdminMain.add.field_staging'] = 'Staging Domain';
$lang['AdminMain.add.field_description'] = 'Description';
$lang['AdminMain.add.field_thumbnail'] = 'Thumbnail URL';
$lang['AdminMain.add.field_sort'] = 'Sort Order';
$lang['AdminMain.add.hint_slug'] = 'Lowercase id used in storage path and catalog (e.g. restaurant).';
$lang['AdminMain.add.hint_staging'] = 'Existing WordPress child domain (e.g. preview-restaurant.falcon.codes).';
$lang['AdminMain.add.submit'] = 'Add Template';
$lang['AdminMain.add.cancel'] = 'Cancel';

$lang['AdminMain.edit.boxtitle'] = 'Edit WordPress Staging Template';
$lang['AdminMain.edit.hint_slug'] = 'Slug cannot be changed after creation (used in storage path and catalog).';
$lang['AdminMain.edit.submit'] = 'Save Changes';

$lang['AdminMain.blesta.boxtitle'] = 'Blesta Client Theme Snapshots';
$lang['AdminMain.blesta.phase_notice'] = 'Phase 1 placeholder: Blesta client theme file snapshots (overrides.css, structure.pdt, etc.) will be manageable here in a later release. Use the WordPress Templates tab for catalog capture today.';
$lang['AdminMain.blesta.heading_label'] = 'Label';
$lang['AdminMain.blesta.heading_template'] = 'Template';
$lang['AdminMain.blesta.heading_files'] = 'Files';
$lang['AdminMain.blesta.heading_date'] = 'Created';
$lang['AdminMain.blesta.no_snapshots'] = 'No Blesta theme snapshots yet.';

$lang['AdminMain.!success.template_added'] = 'Template registered. Run Capture when the staging site is ready.';
$lang['AdminMain.!success.template_updated'] = 'Template updated successfully.';
$lang['AdminMain.!success.template_deleted'] = 'Template deleted from the catalog.';
$lang['AdminMain.!success.capture'] = 'Snapshot captured successfully.';
$lang['AdminMain.!success.activated'] = 'Template is now active in the public catalog.';
$lang['AdminMain.!success.deactivated'] = 'Template removed from the public catalog.';
$lang['AdminMain.!error.capture.failed'] = 'Capture failed.';
$lang['AdminMain.!error.delete.failed'] = 'Unable to delete the template.';
