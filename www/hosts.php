<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/layout.php';
require_once 'inc/hosts_file.php';

require_auth();

$file = new HostsFile(HOSTS_DIR . '/local');
$msg  = null;
$edit = isset($_GET['edit']) ? (int)$_GET['edit'] : -1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $file->toggle((int)$_POST['idx']);
        $file->save();
        $msg = ['ok', 'Entry toggled.'];

    } elseif ($action === 'delete') {
        $file->delete((int)$_POST['idx']);
        $file->save();
        $msg = ['ok', 'Entry deleted.'];

    } elseif ($action === 'add') {
        $ip    = trim($_POST['ip'] ?? '');
        $hosts = preg_split('/\s+/', trim($_POST['hostnames'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $msg = ['err', 'Invalid IP address.'];
        } elseif (empty($hosts)) {
            $msg = ['err', 'At least one hostname required.'];
        } else {
            $file->add($ip, $hosts);
            $file->save();
            $msg = ['ok', 'Entry added.'];
        }

    } elseif ($action === 'update') {
        $ip    = trim($_POST['ip'] ?? '');
        $hosts = preg_split('/\s+/', trim($_POST['hostnames'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (!filter_var($ip, FILTER_VALIDATE_IP) || empty($hosts)) {
            $msg = ['err', 'Invalid IP or hostname.'];
        } else {
            $file->update((int)$_POST['idx'], $ip, $hosts);
            $file->save();
            $msg = ['ok', 'Entry updated.'];
        }
    }

    if (!$msg || $msg[0] === 'ok') {
        header('Location: hosts.php' . ($msg ? '?saved=1' : ''));
        exit;
    }
}

if (isset($_GET['saved'])) $msg = ['ok', saved_hint()];
$entries = $file->entries();

page_start('Hosts', __FILE__, 'narrow');
if ($msg) alert($msg[0], $msg[1]);
?>
<div class="card">
  <div class="card-header">
    Local Hosts
    <span class="text-muted" style="font-weight:400;margin-left:auto;font-size:.75rem"><?= HOSTS_DIR . '/local' ?></span>
  </div>
  <div class="table-wrap">
  <table>
    <tr><th>IP</th><th>Hostnames</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($entries as $e):
        $is_edit = $edit === $e['idx'];
    ?>
    <tr class="<?= $e['enabled'] ? '' : 'row-disabled' ?>">
      <?php if ($is_edit): ?>
      <td colspan="2">
        <form method="post" style="display:flex;gap:.5rem;align-items:center">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="idx"    value="<?= $e['idx'] ?>">
          <input type="text" class="inp-ip"    name="ip"        value="<?= h($e['ip']) ?>">
          <input type="text" class="inp-hosts" name="hostnames" value="<?= h(implode(' ', $e['hostnames'])) ?>">
          <button class="btn btn-primary btn-sm">Save</button>
          <a href="hosts.php" class="btn btn-secondary btn-sm">Cancel</a>
        </form>
      </td>
      <td></td><td></td>
      <?php else: ?>
      <td class="ip-cell"><?= h($e['ip']) ?></td>
      <td class="hosts-cell"><?php foreach ($e['hostnames'] as $hn): ?><span><?= h($hn) ?></span><?php endforeach; ?></td>
      <td><?= $e['enabled'] ? '<span style="color:var(--green)">active</span>' : '<span class="text-muted">disabled</span>' ?></td>
      <td>
        <div class="td-actions">
          <a href="?edit=<?= $e['idx'] ?>" class="btn btn-secondary btn-sm">Edit</a>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="idx"    value="<?= $e['idx'] ?>">
            <button class="btn btn-secondary btn-sm"><?= $e['enabled'] ? 'Disable' : 'Enable' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this entry?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="idx"    value="<?= $e['idx'] ?>">
            <button class="btn btn-danger btn-sm">Delete</button>
          </form>
        </div>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <div class="card-footer">
    <form method="post">
      <input type="hidden" name="action" value="add">
      <div class="form-row">
        <div class="form-group">
          <label>IP address</label>
          <input type="text" class="inp-ip" name="ip" placeholder="192.168.1.1">
        </div>
        <div class="form-group" style="flex:1">
          <label>Hostnames (space-separated)</label>
          <input type="text" class="inp-hosts" name="hostnames" placeholder="myhost myhost.fritz.box">
        </div>
        <div class="form-group" style="justify-content:flex-end">
          <label>&nbsp;</label>
          <button class="btn btn-primary">Add entry</button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
