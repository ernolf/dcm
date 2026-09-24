<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/layout.php';
require_once 'inc/directive_file.php';

require_auth();

$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['addr_action'])) {
    $file   = new DirectiveFile(ADDRESS_CONF, 'address');
    $action = $_POST['addr_action'];
    $value  = trim(str_replace(["\r", "\n"], '', $_POST['value'] ?? ''));
    // Anchor to jump back to, so acting on a row does not scroll the page away.
    $anchor = '#addresses';

    if ($action === 'toggle') {
        $file->toggle((int) $_POST['idx']);
        $file->save();
        $anchor = '#row-' . (int) $_POST['idx'];
    } elseif ($action === 'delete') {
        $file->delete((int) $_POST['idx']);
        $file->save();
    } elseif ($action === 'add') {
        if ($value === '') $msg = ['err', 'Address spec required.'];
        else { $anchor = '#row-' . $file->add($value); $file->save(); }
    } elseif ($action === 'update') {
        if ($value === '') $msg = ['err', 'Address spec required.'];
        else { $file->update((int) $_POST['idx'], $value); $file->save(); $anchor = '#row-' . (int) $_POST['idx']; }
    }
    if (!$msg) { header('Location: address.php?saved=1' . $anchor); exit; }
}

$edit    = isset($_GET['edit']) ? (int) $_GET['edit'] : -1;
$entries = (new DirectiveFile(ADDRESS_CONF, 'address'))->entries();

page_start('Fixed Addresses', __FILE__, 'narrow');
if ($msg) alert($msg[0], $msg[1]);
?>
<div class="card" id="addresses">
  <div class="card-header">
    Fixed addresses
    <span class="text-muted" style="font-weight:400;margin-left:auto;font-size:.75rem"><?= ADDRESS_CONF ?></span>
  </div>
  <p class="text-muted" style="font-size:.8rem;padding:.6rem 1.25rem 0">
    Answers a whole domain from one address, e.g. <code>/example.com/192.168.1.2</code>;
    <code>/#/</code> matches every domain. Names listed in the hosts files keep their own address,
    an unknown name gets this one instead of NXDOMAIN. Since dnsmasq 2.86 only the query type of the
    address literal is answered — every other type is still forwarded, so pair this with
    <em>Authoritative local domains</em> (<code>local=</code>) to keep AAAA, HTTPS and MX at home.
  </p>
  <div class="table-wrap">
  <table>
    <tr><th>Address</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($entries as $e):
        $is_edit = $edit === $e['idx'];
    ?>
    <tr id="row-<?= $e['idx'] ?>" class="<?= $e['enabled'] ? '' : 'row-disabled' ?>">
      <?php if ($is_edit): ?>
      <td>
        <form method="post" style="display:flex;gap:.5rem;align-items:center">
          <input type="hidden" name="addr_action" value="update">
          <input type="hidden" name="idx"         value="<?= $e['idx'] ?>">
          <input type="text" class="inp-hosts" name="value" value="<?= h($e['value']) ?>" style="min-width:280px">
          <button class="btn btn-primary btn-sm">Save</button>
          <a href="address.php#row-<?= $e['idx'] ?>" class="btn btn-secondary btn-sm">Cancel</a>
        </form>
      </td>
      <td></td><td></td>
      <?php else: ?>
      <td class="ip-cell"><?= h($e['value']) ?></td>
      <td><?= $e['enabled'] ? '<span style="color:var(--green)">active</span>' : '<span class="text-muted">disabled</span>' ?></td>
      <td>
        <div class="td-actions">
          <a href="?edit=<?= $e['idx'] ?>#row-<?= $e['idx'] ?>" class="btn btn-secondary btn-sm">Edit</a>
          <form method="post" style="display:inline">
            <input type="hidden" name="addr_action" value="toggle">
            <input type="hidden" name="idx"         value="<?= $e['idx'] ?>">
            <button class="btn btn-secondary btn-sm"><?= $e['enabled'] ? 'Disable' : 'Enable' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this address?')">
            <input type="hidden" name="addr_action" value="delete">
            <input type="hidden" name="idx"         value="<?= $e['idx'] ?>">
            <button class="btn btn-danger btn-sm">Delete</button>
          </form>
        </div>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($entries)): ?>
    <tr><td colspan="3" class="text-muted">No fixed addresses — every domain is resolved upstream.</td></tr>
    <?php endif; ?>
  </table>
  </div>
  <div class="card-footer">
    <form method="post">
      <input type="hidden" name="addr_action" value="add">
      <div class="form-row">
        <div class="form-group" style="flex:1">
          <label>Address spec</label>
          <input type="text" class="inp-hosts" name="value" placeholder="/example.com/192.168.1.2">
        </div>
        <div class="form-group" style="justify-content:flex-end">
          <label>&nbsp;</label>
          <button class="btn btn-primary">Add address</button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
