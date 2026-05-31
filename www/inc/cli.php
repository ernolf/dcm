<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

function cli_run(string ...$args): array {
    $cmd = 'sudo ' . CLI;
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $lines = [];
    $exit  = 0;
    exec($cmd . ' 2>&1', $lines, $exit);
    return ['ok' => $exit === 0, 'output' => implode("\n", $lines)];
}
