<?php
/**
 * Quissly for CS-Cart — "Add to cart" buttons on the search results page.
 *
 * The theme already renders an add-to-cart form for every product in a result list,
 * but its grid view hides the button unless the list asks for it (show_add_to_cart,
 * off by default in products_multicolumns). Search results ask for it, so a shopper
 * can buy straight from what they searched — Quissly's results or native ones alike.
 */

use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */

if ($mode === 'search') {
    Tygh::$app['view']->assign('show_add_to_cart', true);
}
