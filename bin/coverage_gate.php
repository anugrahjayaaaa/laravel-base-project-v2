#!/usr/bin/env php
<?php

/**
 * P15-E2 — per-layer line coverage from ONE clover run.
 *
 * `--min=85` is global only, so the layer bars below need this reader.
 * Usage: php bin/coverage_gate.php build/coverage/clover.xml
 * Exits non-zero under any bar and prints the three numbers it compared.
 */

$clover = $argv[1] ?? 'build/coverage/clover.xml';

$doc = @simplexml_load_file($clover);

if ($doc === false) {
    fwrite(STDERR, "cannot read clover file: {$clover}\n");

    exit(2);
}

$bars = [
    'app/Actions' => 95.0,
    'app/Policies' => 100.0,
    'app/Http/Requests' => 95.0,
];

$fail = false;

foreach ($bars as $dir => $bar) {
    $statements = 0;
    $covered = 0;
    $gaps = [];

    // XPath, not ->project->file: PHPUnit nests files under <package>
    // elements, and a direct-child walk finds none of them.
    foreach ($doc->xpath('//file') as $file) {
        $path = (string) $file['name'];

        if (! str_contains($path, "/{$dir}/")) {
            continue;
        }

        $s = (int) $file->metrics['statements'];
        $c = (int) $file->metrics['coveredstatements'];
        $statements += $s;
        $covered += $c;

        if ($s > 0 && $c < $s) {
            $gaps[basename($path)] = round(100 * $c / $s, 1);
        }
    }

    // A layer with no statements cannot be under its bar — an empty directory
    // is not a coverage hole.
    if ($statements === 0) {
        printf("%-20s no statements found — nothing to enforce\n", $dir);

        continue;
    }

    $percent = round(100 * $covered / $statements, 2);
    $ok = $percent >= $bar;
    $fail = $fail || ! $ok;

    printf("%-20s %6.2f%% (%d/%d statements) — bar %.1f%% — %s\n", $dir, $percent, $covered, $statements, $bar, $ok ? 'OK' : 'FAIL');

    if (! $ok) {
        // Gap list with owners: the files dragging the layer, worst first.
        asort($gaps);
        $worst = array_slice(array_keys($gaps), 0, 10);
        foreach ($worst as $name) {
            printf("  gap: %s — %.1f%%\n", $name, $gaps[$name]);
        }
    }
}

exit($fail ? 1 : 0);
