<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/cli.php';

require_auth();
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'sync':
        echo json_encode(cli_run('sync'));
        break;

    case 'restart':
        $target = $_POST['target'] ?? '';
        if (!in_array($target, ['local', 'remote', 'all'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'output' => 'Invalid target']);
            break;
        }
        echo json_encode(cli_run('restart', $target));
        break;

    case 'status':
        $target = ($_POST['target'] ?? 'local') === 'remote' ? 'remote' : 'local';
        echo json_encode(cli_run('status', $target));
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'output' => 'Unknown action']);
}
