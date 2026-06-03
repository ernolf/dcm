<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/layout.php';
require_once 'inc/hosts_file.php';

require_auth();

$file = new HostsFile(HOSTS_DIR . '/vms');
$msg  = null;
$edit = isset($_GET['edit']) ? (int)$_GET['edit'] : -1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $file->toggle((int)$_POST['idx']);
        $file->save();
    } elseif ($action === 'delete') {
        $file->delete((int)$_POST['idx']);
        $file->save();
    } elseif ($action === 'add') {
        $ip    = trim($_POST['ip'] ?? '');
        $hosts = preg_split('/\s+/', trim($_POST['hostnames'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (!filter_var($ip, FILTER_VALIDATE_IP) || empty($hosts)) {
            $msg = ['err', 'Invalid IP or hostname.'];
        } else {
            $file->add($ip, $hosts);
            $file->save();
        }
    } elseif ($action === 'update') {
        $ip    = trim($_POST['ip'] ?? '');
        $hosts = preg_split('/\s+/', trim($_POST['hostnames'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (!filter_var($ip, FILTER_VALIDATE_IP) || empty($hosts)) {
            $msg = ['err', 'Invalid IP or hostname.'];
        } else {
            $file->update((int)$_POST['idx'], $ip, $hosts);
            $file->save();
        }
    } elseif ($action === 'relocate') {
        $new_prefix = trim($_POST['new_prefix'] ?? '');
        // Validate: must look like "N.N.N."
        if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.$/', $new_prefix)) {
            $msg = ['err', 'Invalid prefix format. Use e.g. 10.1.10.'];
        } else {
            $entries = $file->entries();
            $errors  = 0;
            foreach ($entries as $e) {
                $parts  = explode('.', $e['ip']);
                $last   = end($parts);
                $new_ip = $new_prefix . $last;
                if (!filter_var($new_ip, FILTER_VALIDATE_IP)) { $errors++; continue; }
                $file->update($e['idx'], $new_ip, $e['hostnames']);
            }
            $file->save();
            $msg = $errors
                ? ['err', "Relocated with {$errors} skipped (invalid IP)."]
                : ['ok',  "All VMs relocated to {$new_prefix}0/24."];
        }
    }

    if (!$msg || $msg[0] === 'ok') {
        header('Location: vms.php' . ($msg ? '?saved=1' : ''));
        exit;
    }
}

if (isset($_GET['saved'])) $msg = ['ok', saved_hint()];

$entries       = $file->entries();
$current_prefix = detect_prefix($entries);

page_start('Virtual Machines', __FILE__, 'narrow');
if ($msg) alert($msg[0], $msg[1]);
?>
<div class="card mb-2">
  <div class="card-header">⬡ Network Relocation</div>
  <div class="card-body">
    <p class="text-muted mb-1" style="font-size:.825rem">
      Current prefix: <code class="text-mono"><?= $current_prefix ?: '(mixed or empty)' ?></code>
      — changes the subnet of all VM entries while preserving the last octet.
    </p>
    <form method="post">
      <input type="hidden" name="action" value="relocate">
      <div class="form-row">
        <div class="form-group">
          <label>New subnet prefix (e.g. 10.1.10.)</label>
          <input type="text" class="inp-ip" name="new_prefix" placeholder="10.1.10."
                 value="<?= h($current_prefix) ?>" style="width:160px">
        </div>
        <div class="form-group" style="justify-content:flex-end">
          <label>&nbsp;</label>
          <button class="btn btn-warning"
                  onclick="return confirm('Relocate all active VM entries to this subnet?')">
            Relocate all
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    VM Entries
    <span class="text-muted" style="font-weight:400;margin-left:auto;font-size:.75rem"><?= HOSTS_DIR . '/vms' ?></span>
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
          <a href="vms.php" class="btn btn-secondary btn-sm">Cancel</a>
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
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this VM entry?')">
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
          <input type="text" class="inp-ip" name="ip" placeholder="192.168.78.100">
        </div>
        <div class="form-group" style="flex:1">
          <label>Hostnames (space-separated)</label>
          <input type="text" class="inp-hosts" name="hostnames" placeholder="my-vm my-vm.fritz.box">
        </div>
        <div class="form-group" style="justify-content:flex-end">
          <label>&nbsp;</label>
          <button class="btn btn-primary">Add VM</button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
