<?php
/**
 * Quissly for CS-Cart — add-on bootstrap.
 *
 * Registers a minimal PSR-4 autoloader for the `Quissly\Search\` namespace
 * (CS-Cart does not auto-load an add-on's composer.json) and wires the single
 * interception hook.
 */

defined('BOOTSTRAP') or die('Access denied');

// Self-contained PSR-4 autoloader (no Composer needed). Shared with func.php so
// the classes resolve during installation too, when init.php has not yet loaded.
require_once __DIR__ . '/autoload.php';

/*
 * Hooks:
 *  - `get_products_before_select` — the single interception point. Fires inside
 *    fn_get_products AFTER the native keyword LIKE has been built into $condition
 *    (so we can blank it) but BEFORE the product-id IN() filter and db_sort() —
 *    and it exposes $total and $items_per_page BY REFERENCE, which is exactly what
 *    the pager override needs. See lib/SearchInterceptor.php.
 *  - `products_sorting` — registers the synthetic `quissly_relevance` sort option
 *    so the storefront "Sort by" dropdown can render it on intercepted searches.
 *  - `update_product_post`, `delete_product_post`, `update_product_amount`,
 *    `tools_change_status` — queue a product for catalog sync when it is saved,
 *    deleted, flips in/out of stock, or has its status toggled in a list
 *    (lib/Sync/Sync.php). Queue only: Quissly is called by "Sync now" and cron.
 *  - `product_bundle_service_update_bundle`, `..._update_links`, `..._delete_bundle_pre`
 *    (the Product bundles add-on's own hooks; `tools_change_status` covers its on/off
 *    toggle) — re-queue a bundle's members, which carry it in their metadata.
 *  - `change_company_status_pre`, `update_company` (and `tools_change_status` on the
 *    companies table) — a Multi-Vendor vendor suspended or reactivated: its products
 *    are queued, and the worker removes them from Quissly or sends them again.
 *  - `variation_group_save_group` (the Product variations add-on) — generated
 *    variations are written without fn_update_product, so the group's products (and
 *    any product taken out of it) are queued here.
 */
fn_register_hooks(
    'get_products_before_select',
    'products_sorting',
    'update_product_post',
    'delete_product_pre',
    'delete_product_post',
    'update_product_amount',
    'tools_change_status',
    'product_bundle_service_update_bundle',
    'product_bundle_service_update_links',
    'product_bundle_service_delete_bundle_pre',
    'variation_group_save_group',
    'change_company_status_pre',
    'update_company',
    'dispatch_before_display'
);
