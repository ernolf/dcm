<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/layout.php';
require_once 'inc/dnsmasq_directives.php';
require_once 'inc/dnsmasq_manpage.php';
require_once 'inc/dropins.php';
require_once 'inc/dropin_form.php';

require_auth();

$dirs = dnsmasq_directives();
$man  = dnsmasq_manpage();
// The Configuration page owns the phase-1 groups; upstream lives on its own page.
$groups = array_filter(dnsmasq_groups(), fn($g) => ($g['phase'] ?? 1) === 1);

$save    = dropin_form_save($dirs, 1, 'dnsconf.php');
$desired = $save['desired'];
$msg     = $save['msg'];

$merged  = dropins_merge(DNSMASQ_D);
$postErr = $msg && $msg[0] === 'err';

page_start('Configuration', __FILE__, 'narrow');
if ($msg) alert($msg[0], $msg[1]);
?>
<form method="post">
<input type="hidden" name="dropin_form" value="1">
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
  <button class="btn btn-primary js-save">Save changes</button>
  <span class="text-muted" style="font-size:.8rem">
    Each editable setting writes one <code>&lt;key&gt;.conf</code> drop-in in <code><?= h(DNSMASQ_D) ?></code>;
    Default removes it. Managed/locked rows are read-only.
  </span>
</div>
<?php dropin_form_render($dirs, $groups, $man, $merged, $desired, $postErr, 1); ?>
<div style="margin-bottom:2rem">
  <button class="btn btn-primary js-save">Save changes</button>
</div>
</form>
<?php dropin_form_scripts(); ?>
<?php page_end(); ?>
