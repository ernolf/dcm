<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/layout.php';
require_once 'inc/cli.php';

require_auth();

$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = $_POST['content'] ?? '';
    $content = str_replace("\r\n", "\n", $content);
    if (!str_ends_with($content, "\n")) $content .= "\n";
    if (file_put_contents(DNSMASQ_CONF, $content) !== false) {
        $msg = ['ok', 'dnsmasq.conf.mine saved. Restart dnsmasq to apply.'];
    } else {
        $msg = ['err', 'Write failed — check file permissions.'];
    }
}

$raw = is_readable(DNSMASQ_CONF) ? file_get_contents(DNSMASQ_CONF) : '# File not found: ' . DNSMASQ_CONF;

page_start('Configuration', __FILE__);
if ($msg) alert($msg[0], $msg[1]);
?>
<div class="card">
  <div class="card-header">
    dnsmasq configuration
    <span class="text-muted" style="font-weight:400;margin-left:auto;font-size:.75rem"><?= DNSMASQ_CONF ?></span>
  </div>
  <div class="card-body">
    <p class="text-muted mb-2" style="font-size:.825rem">
      Direct edit of the main config file.
      After saving, use <a href="dashboard.php">Dashboard → Restart</a> to apply changes.
    </p>
    <form method="post">
      <textarea name="content" rows="30"><?= h($raw) ?></textarea>
      <div style="margin-top:.75rem">
        <button class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
