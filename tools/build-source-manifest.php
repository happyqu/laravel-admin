<?php

/**
 * Build or verify source-manifest.json for the frontend assets.
 *
 * Usage:
 *   php tools/build-source-manifest.php
 *   php tools/build-source-manifest.php --check
 */

$root = dirname(__DIR__);
$assets = $root.'/resources/assets';
$manifestFile = $root.'/source-manifest.json';
$check = in_array('--check', $argv, true);

if (!is_dir($assets)) {
    fwrite(STDERR, "Missing resources/assets directory.\n");
    exit(1);
}

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($assets, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($assets) + 1));

    $files[] = [
        'path'   => $path,
        'sha256' => hash_file('sha256', $file->getPathname()),
        'bytes'  => $file->getSize(),
    ];
}

usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));

$manifest = [
    'generated_on' => date('Y-m-d'),
    'packages'     => [
        [
            'name'        => 'happyqu/laravel-admin',
            'version'     => '3.0.0',
            'source_path' => 'resources/assets',
            'files'       => $files,
        ],
    ],
];

$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

if ($check) {
    if (!is_file($manifestFile)) {
        fwrite(STDERR, "source-manifest.json is missing.\n");
        exit(1);
    }

    $existing = json_decode(file_get_contents($manifestFile), true);
    $existingFiles = $existing['packages'][0]['files'] ?? [];
    $existingMap = [];

    foreach ($existingFiles as $file) {
        $existingMap[$file['path']] = [$file['sha256'], $file['bytes']];
    }

    $errors = 0;
    foreach ($files as $file) {
        if (!isset($existingMap[$file['path']])) {
            fwrite(STDERR, "Missing from manifest: {$file['path']}\n");
            $errors++;
            continue;
        }
        if ($existingMap[$file['path']] !== [$file['sha256'], $file['bytes']]) {
            fwrite(STDERR, "Hash mismatch: {$file['path']}\n");
            $errors++;
        }
        unset($existingMap[$file['path']]);
    }

    foreach (array_keys($existingMap) as $path) {
        fwrite(STDERR, "Stale manifest entry: {$path}\n");
        $errors++;
    }

    if ($errors) {
        exit(1);
    }

    echo 'source-manifest.json is up to date ('.count($files)." files).\n";
    exit(0);
}

file_put_contents($manifestFile, $json);

echo 'Wrote '.count($files)." files to source-manifest.json.\n";
