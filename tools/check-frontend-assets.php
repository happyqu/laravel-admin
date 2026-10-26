<?php

/**
 * Verify that every first-party base asset referenced by Admin\HasAssets exists.
 *
 * Usage: php tools/check-frontend-assets.php
 */

require __DIR__.'/../vendor/autoload.php';

use Encore\Admin\Admin;

$root = dirname(__DIR__).'/resources/assets/';
$prefix = 'vendor/laravel-admin/';
$missing = [];
$checked = 0;

$assets = array_merge(
    Admin::baseCss(),
    Admin::baseJs(),
    [Admin::$jQuery]
);

foreach (array_unique($assets) as $asset) {
    if (!is_string($asset) || $asset === '') {
        continue;
    }

    if (preg_match('#^https?://#i', $asset)) {
        continue;
    }

    if (!str_starts_with($asset, $prefix)) {
        continue;
    }

    $relative = substr($asset, strlen($prefix));
    $absolute = $root.$relative;
    $checked++;

    if (!is_file($absolute)) {
        $missing[] = $asset.'  ->  resources/assets/'.$relative;
    }
}

if ($missing) {
    fwrite(STDERR, "Missing frontend assets:\n");
    foreach ($missing as $item) {
        fwrite(STDERR, '  '.$item."\n");
    }
    exit(1);
}

echo "Checked {$checked} base frontend assets; all present.\n";
