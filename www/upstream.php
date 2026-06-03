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
require_once 'inc/upstream_file.php';

require_auth();

$dirs = dnsmasq_directives();
$man  = dnsmasq_manpage();
// The Upstream DNS page owns the phase-2 (upstream) directives.
$groups = array_filter(dnsmasq_groups(), fn($g) => ($g['phase'] ?? 1) === 2);

// --- Server table actions (hosts-style, on upstream.conf) ---
$tmsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['srv_action'])) {
    $file   = new UpstreamFile(UPSTREAM_CONF);
    $action = $_POST['srv_action'];
    $value  = trim(str_replace(["\r", "\n"], '', $_POST['value'] ?? ''));

    if ($action === 'toggle') {
        $file->toggle((int) $_POST['idx']);
        $file->save();
    } elseif ($action === 'delete') {
        $file->delete((int) $_POST['idx']);
        $file->save();
    } elseif ($action === 'add') {
        if ($value === '') $tmsg = ['err', 'Server spec required.'];
        else { $file->add($value); $file->save(); }
    } elseif ($action === 'update') {
        if ($value === '') $tmsg = ['err', 'Server spec required.'];
        else { $file->update((int) $_POST['idx'], $value); $file->save(); }
    }
    if (!$tmsg) { header('Location: upstream.php?saved=1'); exit; }
}

// --- Schema form (the other upstream directives) ---
$save    = dropin_form_save($dirs, 2, 'upstream.php');
$desired = $save['desired'];
$msg     = $save['msg'];
if (isset($_GET['saved'])) $msg = ['ok', saved_hint()];

$merged  = dropins_merge(DNSMASQ_D);
$postErr = $msg && $msg[0] === 'err';

$edit    = isset($_GET['edit']) ? (int) $_GET['edit'] : -1;
$entries = (new UpstreamFile(UPSTREAM_CONF))->entries();

page_start('Upstream DNS', __FILE__, 'narrow');
if ($msg)  alert($msg[0],  $msg[1]);
if ($tmsg) alert($tmsg[0], $tmsg[1]);
?>
<form method="post">
<input type="hidden" name="dropin_form" value="1">
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
  <button class="btn btn-primary js-save">Save changes</button>
  <span class="text-muted" style="font-size:.8rem">
    Where dnsmasq forwards queries upstream. Each setting writes a drop-in in <code><?= h(DNSMASQ_D) ?></code>; Default removes it.
  </span>
</div>
<?php dropin_form_render($dirs, $groups, $man, $merged, $desired, $postErr, 2); ?>
<div style="margin-bottom:1.25rem">
  <button class="btn btn-primary js-save">Save changes</button>
</div>
</form>

<div class="card">
  <div class="card-header">
    Upstream servers
    <span class="text-muted" style="font-weight:400;margin-left:auto;font-size:.75rem"><?= UPSTREAM_CONF ?></span>
  </div>
  <p class="text-muted" style="font-size:.8rem;padding:.6rem 1.25rem 0">
    Routes a domain (or all queries) to a specific server, e.g. <code>/example.com/1.1.1.1</code> or just <code>9.9.9.9</code>.
  </p>
  <div class="table-wrap">
  <table>
    <tr><th>Server</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($entries as $e):
        $is_edit = $edit === $e['idx'];
    ?>
    <tr class="<?= $e['enabled'] ? '' : 'row-disabled' ?>">
      <?php if ($is_edit): ?>
      <td>
        <form method="post" style="display:flex;gap:.5rem;align-items:center">
          <input type="hidden" name="srv_action" value="update">
          <input type="hidden" name="idx"        value="<?= $e['idx'] ?>">
          <input type="text" class="inp-hosts" name="value" value="<?= h($e['value']) ?>" style="min-width:280px">
          <button class="btn btn-primary btn-sm">Save</button>
          <a href="upstream.php" class="btn btn-secondary btn-sm">Cancel</a>
        </form>
      </td>
      <td></td><td></td>
      <?php else: ?>
      <td class="ip-cell"><?= h($e['value']) ?></td>
      <td><?= $e['enabled'] ? '<span style="color:var(--green)">active</span>' : '<span class="text-muted">disabled</span>' ?></td>
      <td>
        <div class="td-actions">
          <a href="?edit=<?= $e['idx'] ?>" class="btn btn-secondary btn-sm">Edit</a>
          <form method="post" style="display:inline">
            <input type="hidden" name="srv_action" value="toggle">
            <input type="hidden" name="idx"        value="<?= $e['idx'] ?>">
            <button class="btn btn-secondary btn-sm"><?= $e['enabled'] ? 'Disable' : 'Enable' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this server?')">
            <input type="hidden" name="srv_action" value="delete">
            <input type="hidden" name="idx"        value="<?= $e['idx'] ?>">
            <button class="btn btn-danger btn-sm">Delete</button>
          </form>
        </div>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($entries)): ?>
    <tr><td colspan="3" class="text-muted">No upstream servers — dnsmasq falls back to resolv-file.</td></tr>
    <?php endif; ?>
  </table>
  </div>
  <div class="card-footer">
    <form method="post">
      <input type="hidden" name="srv_action" value="add">
      <div class="form-row">
        <div class="form-group" style="flex:1">
          <label>Server spec</label>
          <input type="text" class="inp-hosts" name="value" placeholder="/example.com/1.1.1.1  or  9.9.9.9">
        </div>
        <div class="form-group" style="justify-content:flex-end">
          <label>&nbsp;</label>
          <button class="btn btn-primary">Add server</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php dropin_form_scripts(); ?>
<?php page_end(); ?>
