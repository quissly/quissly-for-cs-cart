<?php

declare(strict_types=1);

namespace Tygh\UpgradeCenter\Connectors\QuisslySearch;

use Quissly\Search\Update\Release;
use Quissly\Search\Update\Updater;
use Quissly\Search\Update\UpgradeCenter;
use Tygh\Addons\SchemesManager;
use Tygh\Registry;
use Tygh\UpgradeCenter\Connectors\BaseAddonConnector;

/**
 * Quissly's releases in CS-Cart's Upgrade center (Add-ons > Upgrades): the work is in
 * lib/Update/UpgradeCenter. CS-Cart looks for `Tygh\UpgradeCenter\Connectors\<Camelized add-on
 * id>\Connector` for every installed add-on (UpgradeCenter\App::getConnectors) and autoloads it
 * from the add-on's folder (PSR-0) - the same on 4.11 and 4.21.
 */
class Connector extends BaseAddonConnector
{
    public function __construct()
    {
        parent::__construct();

        $this->addon_id = 'quissly_search';
        $scheme = SchemesManager::getScheme('quissly_search');
        $this->product_name = $scheme ? (string) $scheme->getName() : 'Quissly Search';
        $this->product_version = UpgradeCenter::installedVersion();
        $this->notification_key = 'upgrade_center:quissly_search';
    }

    /**
     * GitHub's CDN copy of the add-on's addon.xml (no rate limit): only a newer version there asks
     * GitHub's API for the release - as the automatic update checks.
     */
    public function getConnectionData()
    {
        return [
            'method'  => 'get',
            'url'     => Updater::hintUrl(),
            'data'    => [],
            'headers' => ['User-Agent: quissly-for-cs-cart'],
        ];
    }

    /**
     * The release on offer as the Upgrade center's schema (empty: none). What keeps a newer one
     * from being offered is shown on the Upgrades page.
     */
    public function processServerResponse($response, $show_upgrade_notice)
    {
        $problem = '';
        $release = UpgradeCenter::offer(is_string($response) ? Release::versionIn($response) : null, $problem);
        if ($problem !== '' && Registry::get('runtime.controller') === 'upgrade_center') {
            fn_set_notification('W', __('warning'), $problem, '', 'quissly_search_upgrade_center');
        }
        if ($release === null) {
            return [];
        }
        if ($show_upgrade_notice) {
            fn_set_notification('W', __('notice'), __('text_upgrade_available', [
                '[product]' => $this->product_name,
                '[link]'    => fn_url('upgrade_center.manage'),
            ]), 'S', $this->notification_key);
        }
        return [
            // CS-Cart 4.21 shows the add-on's name and to_version as the title; 4.11 shows this.
            'name'          => $this->product_name . ' ' . $release['version'],
            'description'   => htmlspecialchars((string) __('quissly_search.uc_description')),
            'file'          => 'quissly_search_' . $release['version'] . '.zip',
            'from_version'  => $this->product_version,
            'to_version'    => $release['version'],
            'timestamp'     => $release['time'],
            'size'          => $release['size'],
            'package_id'    => 'quissly_search_' . $release['version'],
            'package_url'   => $release['package'],
            'signature_url' => $release['signature'],
        ];
    }

    /** The release as an Upgrade center package (lib/Update/UpgradeCenter::build). */
    public function downloadPackage($schema, $package_path)
    {
        $error = UpgradeCenter::build((array) $schema, (string) $package_path);

        return $error === '' ? [true, ''] : [false, $error];
    }
}
