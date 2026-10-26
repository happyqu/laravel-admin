<?php

/**
 * Scan first-party PHP/Blade/JS/CSS for legacy AdminLTE 2 / Bootstrap 3 usage.
 *
 * Usage:
 *   php tools/check-legacy-frontend.php            # report only, exit 0
 *   php tools/check-legacy-frontend.php --fail     # exit 1 when violations exist
 *   php tools/check-legacy-frontend.php --json     # machine-readable output
 */

$root = dirname(__DIR__);
$fail = in_array('--fail', $argv, true);
$json = in_array('--json', $argv, true);

$paths = [
    'resources/views',
    'src',
    'resources/assets/laravel-admin',
    'resources/assets/iframe-tabs',
    'resources/assets/global-navigation.js',
    'resources/assets/global-navigation.css',
    'resources/assets/actions.js',
    'resources/assets/custom.css',
    'resources/assets/iframe.css',
];

$exclude = [
    'resources/assets/AdminLTE',
    'resources/assets/iframe-tabs/layer',
    'resources/assets/bootstrap-duallistbox',
    'resources/assets/bootstrap-fileinput',
    'resources/assets/bootstrap-switch',
    'resources/assets/bootstrap3-editable',
    'resources/assets/eonasdan-bootstrap-datetimepicker',
    'resources/assets/flatpickr',
    'resources/assets/font-awesome',
    'resources/assets/fontawesome-iconpicker',
    'resources/assets/google-fonts',
    'resources/assets/moment',
    'resources/assets/nestable',
    'resources/assets/nprogress',
    'resources/assets/number-input',
    'resources/assets/pickr',
    'resources/assets/select2',
    'resources/assets/sweetalert2',
    'resources/assets/toastr',
];

$patterns = [
    'AdminLTE asset path'      => '/AdminLTE\/(?:dist|bootstrap|plugins)\//',
    'legacy data-toggle'       => '/data-toggle\s*=/',
    'legacy data-target'       => '/data-target\s*=/',
    'legacy data-dismiss'      => '/data-dismiss\s*=/',
    'legacy data-widget'       => '/data-widget\s*=/',
    'iCheck'                   => '/\biCheck\b|\bicheck\b/',
    'bootstrap3-editable'      => '/bootstrap3-editable|\beditable\s*\(/',
    'Eonasdan datetimepicker'  => '/datetimepicker/i',
    'bootstrap-switch'         => '/bootstrapSwitch|\bbootstrap-switch\b/',
    'bootstrap-duallistbox'    => '/bootstrapDualListbox|duallistbox/i',
    'bootstrap-colorpicker'    => '/\bcolorpicker\b/i',
    'slimScroll'               => '/\bslimScroll\b|slimscroll/i',
    'legacy .box'              => '/class\s*=\s*["\'][^"\']*\bbox\b/',
    'legacy .pull-*'           => '/class\s*=\s*["\'][^"\']*\bpull-(left|right)\b/',
    'legacy .btn-default'      => '/class\s*=\s*["\'][^"\']*\bbtn-default\b/',
    'legacy .form-group'       => '/class\s*=\s*["\'][^"\']*\bform-group\b/',
    'legacy control-label'     => '/class\s*=\s*["\'][^"\']*\bcontrol-label\b/',
    'legacy input-group-addon' => '/class\s*=\s*["\'][^"\']*\binput-group-addon\b/',
    'legacy has-error'         => '/class\s*=\s*["\'][^"\']*\bhas-error\b/',
    'legacy help-block'        => '/class\s*=\s*["\'][^"\']*\bhelp-block\b/',
    'legacy hidden-xs'         => '/class\s*=\s*["\'][^"\']*\bhidden-(xs|sm|md|lg)\b/',
    'legacy visible-*'         => '/class\s*=\s*["\'][^"\']*\bvisible-(xs|sm|md|lg)\b/',
    'jQuery .size()'           => '/\.size\s*\(\s*\)/',
    'jQuery .load(fn)'         => '/\.load\s*\(\s*function/',
];

$violations = [];
$scanned = 0;

foreach ($paths as $relative) {
    $absolute = $root.'/'.$relative;
    if (!file_exists($absolute)) {
        continue;
    }

    $iterator = is_dir($absolute)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS))
        : new ArrayIterator([new SplFileInfo($absolute)]);

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $relativePath = str_replace('\\', '/', substr($path, strlen(str_replace('\\', '/', $root)) + 1));

        foreach ($exclude as $prefix) {
            if (str_starts_with($relativePath, $prefix.'/') || $relativePath === $prefix) {
                continue 2;
            }
        }

        if (!preg_match('/\.(php|blade\.php|js|css)$/', $relativePath)) {
            continue;
        }

        $scanned++;
        $contents = file_get_contents($path);
        if ($contents === false) {
            continue;
        }

        foreach ($patterns as $label => $pattern) {
            if (!preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$match, $offset]) {
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $violations[] = [
                    'file' => $relativePath,
                    'line' => $line,
                    'rule' => $label,
                    'match' => trim(preg_replace('/\s+/', ' ', $match)),
                ];
            }
        }
    }
}

usort($violations, fn ($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

if ($json) {
    echo json_encode([
        'scanned' => $scanned,
        'violations' => $violations,
        'counts' => array_count_values(array_column($violations, 'rule')),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} else {
    $byRule = [];
    $byFile = [];
    foreach ($violations as $violation) {
        $byRule[$violation['rule']] = ($byRule[$violation['rule']] ?? 0) + 1;
        $byFile[$violation['file']] = ($byFile[$violation['file']] ?? 0) + 1;
    }

    echo "Scanned {$scanned} first-party files.", PHP_EOL;
    echo 'Found '.count($violations).' legacy reference(s).', PHP_EOL, PHP_EOL;

    if ($violations) {
        echo "By rule:", PHP_EOL;
        arsort($byRule);
        foreach ($byRule as $rule => $count) {
            printf("  %-28s %d\n", $rule, $count);
        }

        echo PHP_EOL.'Top files:', PHP_EOL;
        arsort($byFile);
        foreach (array_slice($byFile, 0, 15, true) as $file => $count) {
            printf("  %-60s %d\n", $file, $count);
        }

        echo PHP_EOL.'First 40 violations:', PHP_EOL;
        foreach (array_slice($violations, 0, 40) as $violation) {
            printf("  %s:%d  [%s]  %s\n", $violation['file'], $violation['line'], $violation['rule'], $violation['match']);
        }
    }
}

exit($fail && $violations ? 1 : 0);
