<?php

/**
 * Quissly for CS-Cart - Add-ons > Upgrades' Install of this add-on, checked before CS-Cart starts it
 * (lib/Update/UpgradeCenter::installRefusal): not a package that is gone, a version already installed,
 * nor a second install while one runs. A second press of Install during the first one's backup once
 * started CS-Cart on a deleted package, which closed the storefront and stopped with it closed.
 */

defined('BOOTSTRAP') or die('Access denied');

if ($mode === 'install' && ($_REQUEST['id'] ?? '') === 'quissly_search') {
    try {
        $quisslyRefusal = \Quissly\Search\Update\UpgradeCenter::installRefusal();
    } catch (\Throwable $e) {
        $quisslyRefusal = ''; // never in CS-Cart's way over our own failure
    }
    if ($quisslyRefusal !== '') {
        fn_set_notification('W', __('warning'), $quisslyRefusal);
        if (defined('AJAX_REQUEST')) {
            \Tygh\Tygh::$app['ajax']->assign('force_redirection', fn_url('upgrade_center.manage'));
            exit;
        }

        return [CONTROLLER_STATUS_REDIRECT, 'upgrade_center.manage'];
    }
}
