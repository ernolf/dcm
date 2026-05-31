<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/layout.php';

require_auth();

$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = $_POST['content'] ?? '';
    // Basic sanity: only allow server= lines and comments
    $lines = explode("\n", str_replace("\r\n", "\n", $content));
    $clean = [];
    foreach ($lines as $l) {
        $t = trim($l);
        if ($t === '' || str_starts_with($t, '#') || str_starts_with($t, 'server=')) {
            $clean[] = $l;
        }
    }
    $clean_content = implode("\n", $clean);
    if (!str_ends_with($clean_content, "\n")) $clean_content .= "\n";
    if (file_put_contents(UPSTREAM_CONF, $clean_content) !== false) {
        $msg = ['ok', 'upstream.conf saved.'];
    } else {
        $msg = ['err', 'Write failed — check file permissions.'];
    }
}

$raw = is_readable(UPSTREAM_CONF) ? file_get_contents(UPSTREAM_CONF) : '# File not found: ' . UPSTREAM_CONF;

page_start('Upstream DNS', __FILE__);
if ($msg) alert($msg[0], $msg[1]);
?>
<div class="card">
  <div class="card-header">
    Upstream Server Configuration
    <span class="text-muted" style="font-weight:400;margin-left:auto;font-size:.75rem"><?= UPSTREAM_CONF ?></span>
  </div>
  <div class="card-body">
    <p class="text-muted mb-2" style="font-size:.825rem">
      Only <code>server=</code> lines and comments are allowed. Lines with other directives are stripped on save.
    </p>
    <form method="post">
      <textarea name="content" rows="24"><?= h($raw) ?></textarea>
      <div style="margin-top:.75rem">
        <button class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
