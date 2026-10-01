<?php

/**
 * The GDPR add-on's cookie banner (Klaro): this add-on's quissly_uid cookie, which recognises
 * a returning guest in Quissly's search analytics, is a performance cookie the shopper can
 * decline (Shopper::guestAllowed() reads the answer).
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['services']['quissly_search'] = [
    'purposes' => ['performance'],
    'name' => 'quissly_search',
    'translations' => [
        'zz' => [
            'title' => 'quissly_search.klaro_cookies_title',
            'description' => 'quissly_search.klaro_cookies_description',
        ],
    ],
];

return $schema;
