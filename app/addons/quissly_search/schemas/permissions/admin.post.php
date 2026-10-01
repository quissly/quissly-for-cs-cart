<?php
/**
 * Quissly for CS-Cart — the add-on's admin pages (the embedded panel, Test
 * connection) need the same privilege as the add-on's settings page.
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['quissly_search'] = [
    'permissions' => 'update_settings',
];

return $schema;
