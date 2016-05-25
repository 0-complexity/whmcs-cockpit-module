<?php
/**
 * This page is contains the VDC Control Panel. An optional parameter vdc_id may be set.
 * If vdc_id is set, it will open the control panel on this vdc's page.
 */

define("CLIENTAREA", true);
//define("FORCESSL", true); // Uncomment to force the page to use https://

require("init.php");

$ca = new WHMCS_ClientArea();

$ca->setPageTitle("VDC Control Panel");

$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('vdc.php', 'VDC Control Panel');

$ca->initPage();

$ca->requireLogin();

# Check login status
if ($ca->isLoggedIn()) {
	if (isset($_GET['vdc_id'])) {
		$ca->assign('vdc_id', $_GET['vdc_id']);
	}
}

# Define the template filename to be used without the .tpl extension
$ca->setTemplate('vdc');

$ca->output();
