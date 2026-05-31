<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_auth();

$server = in_array($_GET['server'] ?? '', ['local', 'remote'], true) ? $_GET['server'] : 'local';

set_time_limit(0);
ignore_user_abort(false);
while (ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

$cmd = 'sudo ' . CLI . ' tail-f ' . escapeshellarg($server) . ' 2>&1';
$fp  = popen($cmd, 'r');

if (!$fp) {
    echo "data: " . json_encode('[error] could not start tail') . "\n\n";
    flush();
    exit;
}

// Send last 30 lines as initial burst so the panel is not empty
$init_cmd = 'sudo ' . CLI . ' logs 30 2>/dev/null';
$init = shell_exec($init_cmd);
if ($init) {
    foreach (explode("\n", rtrim($init)) as $line) {
        if ($line !== '') {
            echo "data: " . json_encode($line) . "\n\n";
        }
    }
    flush();
}

while (!connection_aborted()) {
    $line = fgets($fp, 8192);
    if ($line !== false) {
        $line = rtrim($line);
        if ($line !== '') {
            echo "data: " . json_encode($line) . "\n\n";
            flush();
        }
    } else {
        // Keep-alive comment every 15s so connection stays open
        echo ": keep-alive\n\n";
        flush();
        usleep(15000000);
    }
}

pclose($fp);
