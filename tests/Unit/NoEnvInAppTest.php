<?php

namespace Tests\Unit;

test('no env() calls exist in app/', function () {
    $appPath = dirname(__DIR__, 2).'/app';
    $offenders = [];

    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($appPath, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $lines = file($file->getPathname());

        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/\benv\(/', $line)) {
                $offenders[] = $file->getPathname().':'.($lineNumber + 1);
            }
        }
    }

    expect($offenders)->toBe([], "env() must only be called from config/, found in:\n".implode("\n", $offenders));
});
