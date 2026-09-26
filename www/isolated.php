<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/layout.php';
require_once 'inc/hosts_file.php';

require_auth();

// Isolating a name takes both families: a client that gets 127.0.0.1 for A but a
// real address for AAAA still reaches the endpoint. So a name is entered once and
// written to both addresses, which keeps the file a hosts file that can be copied
// into a system hosts file as it is. The first address drives the display order.
const ISOLATED_IPS = ['127.0.0.1', '::1'];

$path     = HOSTS_DIR . '/isolated';
$writable = is_writable($path);
$file     = new HostsFile($path);
$msg      = null;
$edit     = $_GET['edit'] ?? '';

// Identity of an entry across both families and across a reload: the name set
// itself. A line number is not enough here — one action rewrites two lines, and
// the file arrives from other nodes by sync.
function entry_key(array $hostnames): string {
    $names = array_map('strtolower', $hostnames);
    sort($names);
    return implode(' ', $names);
}

function is_hostname(string $s): bool {
    return (bool) preg_match('/^(?=.{1,253}$)[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?(?:\.[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?)*$/i', $s);
}

/**
 * Reads a pasted list: bare names, or lines copied straight out of a hosts file.
 * A leading redirect IP and a trailing comment are dropped, so both
 * "0.0.0.0 telemetry.example.com # tracker" and "telemetry.example.com" yield one
 * name set; a commented-out line is skipped instead of being imported disabled.
 * Returns the name sets and the number of lines that held no valid name.
 */
function parse_names(string $text): array {
    $sets = [];
    $bad  = 0;
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim(explode('#', $line, 2)[0]);
        if ($line === '') continue;

        $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
        if (filter_var($parts[0], FILTER_VALIDATE_IP)) array_shift($parts);

        $names = [];
        foreach ($parts as $p) {
            $p = rtrim($p, '.');
            if (is_hostname($p)) $names[] = $p;
        }
        if ($names) $sets[] = $names;
        else        $bad++;
    }
    return [$sets, $bad];
}

/** Every name the file already carries — a hosts file must not answer one name twice. */
function known_names(array $raw): array {
    $known = [];
    foreach ($raw as $e) {
        foreach ($e['hostnames'] as $n) $known[strtolower($n)] = true;
    }
    return $known;
}

function make_entry(array $hostnames, array $lines): array {
    $states = array_column($lines, 'enabled');
    // Half-isolated, from a hand-edited file: one family missing, or the two
    // lines disagreeing. Saving or toggling the entry resolves it.
    $note = '';
    if (count($lines) < count(ISOLATED_IPS))    $note = 'only ' . $lines[0]['ip'];
    elseif (count(array_unique($states)) !== 1) $note = 'families differ';

    return [
        'key'       => entry_key($hostnames),
        'hostnames' => $hostnames,
        'lines'     => $lines,
        'enabled'   => !in_array(false, $states, true),
        'note'      => $note,
    ];
}

/**
 * Pairs each 127.0.0.1 line with its ::1 twin, so one row is one name set.
 * Returns [entries, foreign]; a line redirecting elsewhere is not ours to
 * rewrite and stays read-only.
 */
function isolated_entries(array $raw): array {
    [$primary, $secondary] = ISOLATED_IPS;
    $pool    = [$primary => [], $secondary => []];
    $foreign = [];
    foreach ($raw as $e) {
        if (isset($pool[$e['ip']])) $pool[$e['ip']][] = $e;
        else                        $foreign[] = $e;
    }

    // Consumed one by one, so a name set listed twice keeps its two entries.
    $twins = [];
    foreach ($pool[$secondary] as $e) $twins[entry_key($e['hostnames'])][] = $e;

    $entries = [];
    foreach ($pool[$primary] as $e) {
        $key   = entry_key($e['hostnames']);
        $lines = [$e];
        if (!empty($twins[$key])) $lines[] = array_shift($twins[$key]);
        $entries[] = make_entry($e['hostnames'], $lines);
    }
    foreach ($twins as $rest) {
        foreach ($rest as $e) $entries[] = make_entry($e['hostnames'], [$e]);
    }
    return [$entries, $foreign];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // Anchor to jump back to, so acting on a row does not scroll the page away.
    $anchor = '#isolated';
    [$entries, ] = isolated_entries($file->entries());

    if (!$writable) {
        $msg = ['err', 'The web server cannot write this file.'];

    } elseif ($action === 'add') {
        [$sets, $bad] = parse_names($_POST['names'] ?? '');
        $known   = known_names($file->entries());
        $fresh   = [];
        $added   = 0;
        $present = 0;
        foreach ($sets as $names) {
            $take = [];
            foreach ($names as $n) {
                if (isset($known[strtolower($n)])) { $present++; continue; }
                $known[strtolower($n)] = true;
                $take[] = $n;
            }
            if ($take) {
                $fresh[] = $take;
                $added  += count($take);
            }
        }
        // Grouped by family, the way the file already reads.
        foreach (ISOLATED_IPS as $ip) {
            foreach ($fresh as $names) $file->add($ip, $names);
        }
        if ($added) $file->save();

        $report = [$added . ' added'];
        if ($present) $report[] = $present . ' already present';
        if ($bad)     $report[] = $bad . ' invalid';
        $msg = [$added ? 'ok' : 'err', 'Names: ' . implode(', ', $report) . '.'];

    } else {
        $key = entry_key(preg_split('/\s+/', trim($_POST['key'] ?? ''), -1, PREG_SPLIT_NO_EMPTY));
        $pos = null;
        foreach ($entries as $i => $e) {
            if ($e['key'] === $key) { $pos = $i; break; }
        }

        if ($pos === null) {
            $msg = ['err', 'Entry not found — the file changed in the meantime.'];

        } elseif ($action === 'toggle') {
            $target = !$entries[$pos]['enabled'];
            foreach ($entries[$pos]['lines'] as $l) {
                if ($l['enabled'] !== $target) $file->toggle($l['idx']);
            }
            $file->save();
            $anchor = '#row-' . $pos;

        } elseif ($action === 'delete') {
            foreach ($entries[$pos]['lines'] as $l) $file->delete($l['idx']);
            $file->save();

        } elseif ($action === 'update') {
            [$sets, ] = parse_names($_POST['names'] ?? '');
            $names = $sets ? array_merge(...$sets) : [];
            if (!$names) {
                $msg = ['err', 'At least one valid host name required.'];
            } else {
                // Both families come from the one input field, so a family that
                // was missing is written now and the entry is complete after it.
                $have = array_column($entries[$pos]['lines'], 'idx', 'ip');
                foreach (ISOLATED_IPS as $ip) {
                    if (isset($have[$ip])) $file->update($have[$ip], $ip, $names);
                    else                   $file->add($ip, $names);
                }
                $file->save();
                $anchor = '#row-' . $pos;
            }
        }
    }

    if (!$msg) {
        header('Location: isolated.php?saved=1' . $anchor);
        exit;
    }
}

[$entries, $foreign] = isolated_entries($file->entries());
$total = array_sum(array_map(fn($e) => count($e['hostnames']), $entries));

page_start('Isolated Hosts', __FILE__, 'narrow');
if ($msg) alert($msg[0], $msg[1]);
?>
<div class="card" id="isolated">
  <div class="card-header">
    Isolated Hosts
    <span class="text-muted" style="font-weight:400;margin-left:auto;font-size:.75rem"><?= h($path) ?><?= $writable ? '' : ' — read-only' ?></span>
  </div>
  <?php if (!$writable): ?>
  <!-- .alert is a flex row, so prose with inline markup needs a block. -->
  <div class="alert alert-warn" style="display:block;margin:.6rem 1.25rem 0">
    This file is root-owned, so the web server cannot write it. Run <code>chown www-data:www-data <?= h($path) ?></code> on this node to edit it here.
  </div>
  <?php endif; ?>
  <div class="table-wrap">
  <table>
    <tr><th>Host names</th><th>Status</th><?php if ($writable): ?><th>Actions</th><?php endif; ?></tr>
    <?php foreach ($entries as $i => $e):
        $is_edit = $writable && $edit === $e['key'];
    ?>
    <tr id="row-<?= $i ?>" class="<?= $e['enabled'] ? '' : 'row-disabled' ?>">
      <?php if ($is_edit): ?>
      <td colspan="3">
        <form method="post" style="display:flex;gap:.5rem;align-items:center">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="key"    value="<?= h($e['key']) ?>">
          <input type="text" class="inp-hosts" name="names" value="<?= h(implode(' ', $e['hostnames'])) ?>">
          <button class="btn btn-primary btn-sm">Save</button>
          <a href="isolated.php#row-<?= $i ?>" class="btn btn-secondary btn-sm">Cancel</a>
        </form>
      </td>
      <?php else: ?>
      <td class="hosts-cell"><?php foreach ($e['hostnames'] as $hn): ?><span><?= h($hn) ?></span><?php endforeach; ?></td>
      <td>
        <?= $e['enabled'] ? '<span style="color:var(--green)">active</span>' : '<span class="text-muted">disabled</span>' ?>
        <?php if ($e['note'] !== ''): ?><span style="color:var(--orange)"><?= h($e['note']) ?></span><?php endif; ?>
      </td>
      <?php if ($writable): ?>
      <td>
        <div class="td-actions">
          <a href="?edit=<?= urlencode($e['key']) ?>#row-<?= $i ?>" class="btn btn-secondary btn-sm">Edit</a>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="key"    value="<?= h($e['key']) ?>">
            <button class="btn btn-secondary btn-sm"><?= $e['enabled'] ? 'Disable' : 'Enable' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this entry from both addresses?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="key"    value="<?= h($e['key']) ?>">
            <button class="btn btn-danger btn-sm">Delete</button>
          </form>
        </div>
      </td>
      <?php endif; ?>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <div class="card-footer">
    <?php if ($writable): ?>
    <form method="post">
      <input type="hidden" name="action" value="add">
      <div class="form-group">
        <label>Add host names — one per line, or paste lines from a hosts file (the redirect IP is ignored)</label>
        <textarea name="names" rows="4" placeholder="license.example.com&#10;0.0.0.0 telemetry.example.com"></textarea>
      </div>
      <div class="form-row" style="justify-content:flex-end;margin-top:.5rem">
        <button class="btn btn-primary">Add names</button>
      </div>
    </form>
    <?php endif; ?>
    <div class="text-muted" style="font-size:.8rem;margin-top:.75rem">
      <?= $total ?> names in <?= count($entries) ?> entries, each pinned to <?= h(implode(' and ', ISOLATED_IPS)) ?>.
    </div>
  </div>
</div>

<?php if ($foreign): ?>
<div class="card">
  <div class="card-header">
    Other redirect addresses
    <span class="text-muted" style="font-weight:400;margin-left:auto;font-size:.75rem">read-only</span>
  </div>
  <div class="table-wrap">
  <table>
    <tr><th>Redirect IP</th><th>Host names</th><th>Status</th></tr>
    <?php foreach ($foreign as $e): ?>
    <tr class="<?= $e['enabled'] ? '' : 'row-disabled' ?>">
      <td class="ip-cell"><?= h($e['ip']) ?></td>
      <td class="hosts-cell"><?php foreach ($e['hostnames'] as $hn): ?><span><?= h($hn) ?></span><?php endforeach; ?></td>
      <td><?= $e['enabled'] ? '<span style="color:var(--green)">active</span>' : '<span class="text-muted">disabled</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <div class="card-footer text-muted" style="font-size:.8rem">
    These lines redirect elsewhere than <?= h(implode(' and ', ISOLATED_IPS)) ?>. Edit them in <?= h($path) ?> directly.
  </div>
</div>
<?php endif; ?>
<?php page_end(); ?>
