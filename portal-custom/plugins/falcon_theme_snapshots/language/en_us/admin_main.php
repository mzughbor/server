<?php
$lang['AdminMain.index.page_title'] = 'Falcon Theme Snapshots';
$lang['AdminMain.index.tab_wp'] = 'WordPress Templates';
$lang['AdminMain.index.tab_blesta'] = 'Blesta Theme Files';
$lang['AdminMain.index.tab_guide'] = 'How It Works';
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
$lang['AdminMain.index.action_test'] = 'Test';
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
$lang['AdminMain.!error.test.failed'] = 'Test provision failed.';

$lang['AdminMain.guide.boxtitle'] = 'Theme Workflow Guide';
$lang['AdminMain.guide.intro'] = 'Use this workflow to publish a WordPress theme from a staging site into the customer catalog. Each step is safe to repeat except changing the slug after capture.';
$lang['AdminMain.guide.step1_title'] = 'Register template';
$lang['AdminMain.guide.step1_body'] = 'Add a staging template with slug, display name, and staging domain (e.g. preview-restaurant.falcon.codes). The slug becomes the storage path and catalog id.';
$lang['AdminMain.guide.step2_title'] = 'Customize staging WordPress';
$lang['AdminMain.guide.step2_body'] = 'Log into the staging site and finish design, content, plugins, and menus. Capture reads this site read-only — the live staging database is not modified.';
$lang['AdminMain.guide.step3_title'] = 'Capture';
$lang['AdminMain.guide.step3_body'] = 'Run Capture from the WordPress Templates tab. This exports the staging database and wp-content into /var/lib/falcon-provisioner/templates/{slug}/ and updates last captured.';
$lang['AdminMain.guide.step4_title'] = 'Activate';
$lang['AdminMain.guide.step4_body'] = 'Activate puts the template in the public catalog API (active = 1 and capture files present). Inactive templates stay in admin but are hidden from customers.';
$lang['AdminMain.guide.step5_title'] = 'Customer trial order';
$lang['AdminMain.guide.step5_body'] = 'Customers pick the theme on falcon.codes, complete a trial order, and the provisioner restores the captured snapshot to their subdomain. Credentials appear under Blesta Services → Options.';

$lang['AdminMain.guide.fields_boxtitle'] = 'Field Reference';
$lang['AdminMain.guide.field_name'] = 'Field';
$lang['AdminMain.guide.field_meaning'] = 'Meaning';
$lang['AdminMain.guide.field_slug'] = 'Lowercase catalog id and storage folder name (e.g. restaurant). Do not change after capture — paths and orders reference it.';
$lang['AdminMain.guide.field_staging'] = 'Existing WordPress child domain used as the design source (e.g. preview-restaurant.falcon.codes). Must be reachable before Capture.';
$lang['AdminMain.guide.field_thumbnail'] = 'Optional preview image URL shown in admin and the public theme picker.';
$lang['AdminMain.guide.action_capture'] = 'Capture';
$lang['AdminMain.guide.field_capture'] = 'Read-only snapshot: exports DB + wp-content from staging into template storage. Does not delete or alter the staging site.';
$lang['AdminMain.guide.action_activate'] = 'Activate';
$lang['AdminMain.guide.field_activate'] = 'Controls catalog visibility only. Does not provision customer sites. Deactivate to hide without deleting files.';

$lang['AdminMain.guide.creds_boxtitle'] = 'WordPress Admin Credentials';
$lang['AdminMain.guide.creds_body'] = 'For staging sites, use the WP admin login for that subdomain. For customer trial sites after provision, open Blesta Clients → select client → Services → the trial service → Options (site URL, WP admin URL, username, password, FTP). Passwords follow the same Blesta service options pattern — they are not shown on this admin page.';

$lang['AdminMain.guide.customer_boxtitle'] = 'What Customers See';
$lang['AdminMain.guide.customer_body'] = 'Active themes appear on the falcon.codes homepage picker and catalog API. After checkout, the poller provisions their subdomain from the captured template within a few minutes. They receive site URL and credentials in the client area and email.';

$lang['AdminMain.guide.safety_boxtitle'] = 'Safety Notes';
$lang['AdminMain.guide.safety_delete_template'] = 'Delete template removes the catalog row and captured files under /var/lib/falcon-provisioner/templates/{slug}/ — not the staging WordPress site.';
$lang['AdminMain.guide.safety_delete_wp'] = 'Deleting or rebuilding the staging WordPress site does not remove the catalog entry; re-capture when the staging site is ready again.';
$lang['AdminMain.guide.safety_slug'] = 'Never change the slug after capture. Existing orders, storage paths, and the catalog API key off the original slug.';
$lang['AdminMain.guide.safety_staging'] = 'Capture is read-only on staging, but avoid editing slug or storage paths manually on the server.';

$lang['AdminMain.guide.test_boxtitle'] = 'Quick Test Provision';
$lang['AdminMain.guide.test_intro'] = 'Creates a real Blesta trial service and runs the provisioner poller (same path as customer orders). Use a unique test subdomain. Results are logged to /var/log/falcon-provisioner.log.';
$lang['AdminMain.guide.test_subdomain'] = 'Test Subdomain';
$lang['AdminMain.guide.test_subdomain_hint'] = 'Lowercase letters, numbers, hyphens only (e.g. test-restaurant-01). Must not already be provisioned.';
$lang['AdminMain.guide.test_theme'] = 'Theme Slug';
$lang['AdminMain.guide.test_theme_select'] = '— Select captured theme —';
$lang['AdminMain.guide.test_theme_hint'] = 'Theme must have a successful capture before testing.';
$lang['AdminMain.guide.test_submit'] = 'Quick Test Provision';
$lang['AdminMain.guide.test_running'] = 'Provisioning… (up to 2 min)';
$lang['AdminMain.guide.test_success'] = 'Test site provisioned';
$lang['AdminMain.guide.test_blesta_link'] = 'Open service in Blesta';
$lang['AdminMain.guide.test_creds_hint'] = 'WP/FTP passwords are under Service Options';

$lang['AdminMain.!success.test'] = 'Test provision completed.';
