<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/layout.php';
require_once 'inc/hosts_file.php';

require_auth();

$file    = new HostsFile(HOSTS_DIR . '/block');
$entries = $file->entries();

// Group by IP for display
$by_ip = [];
foreach ($entries as $e) {
    $by_ip[$e['ip']][] = $e;
}

page_start('Block List', __FILE__, 'narrow');
?>
<div class="card">
  <div class="card-header">
    Blocked Domains
    <span class="text-muted" style="font-weight:400;margin-left:auto;font-size:.75rem"><?= HOSTS_DIR . '/block' ?> — read-only</span>
  </div>
  <div class="table-wrap">
  <table>
    <tr><th>Redirect IP</th><th>Domains</th><th>Count</th></tr>
    <?php foreach ($by_ip as $ip => $group): ?>
    <tr>
      <td class="ip-cell"><?= h($ip) ?></td>
      <td class="hosts-cell">
        <?php foreach ($group as $e): foreach ($e['hostnames'] as $hn): ?>
        <span><?= h($hn) ?></span>
        <?php endforeach; endforeach; ?>
      </td>
      <td class="text-muted"><?= array_sum(array_map(fn($e) => count($e['hostnames']), $group)) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <div class="card-footer text-muted" style="font-size:.8rem">
    <?= count($entries) ?> entries total.
    To edit this file, modify <?= HOSTS_DIR ?>/block directly.
  </div>
</div>
<?php page_end(); ?>
