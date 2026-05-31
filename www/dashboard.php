<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/layout.php';
require_once 'inc/cli.php';

require_auth();

$nodes = array_values(array_filter(
    array_map('trim', file(NODES_FILE, FILE_IGNORE_NEW_LINES) ?: []),
    fn($l) => $l !== '' && !str_starts_with($l, '#')
));
$self = trim((string)shell_exec('hostname -s'));

page_start('Dashboard', __FILE__);
?>
<div class="grid-2">
<?php foreach ($nodes as $node):
    $is_local = $node === $self;
?>
  <div class="card">
    <div class="card-header">
      <span class="dot dot-grey" id="dot-<?= h($node) ?>"></span>
      <?= h($node) ?>
      <?php if ($is_local): ?><span class="text-muted" style="font-weight:400;font-size:.75rem">(this node)</span><?php endif; ?>
    </div>
    <div class="card-body">
      <p id="status-<?= h($node) ?>" class="text-muted" style="font-size:.825rem">Loading…</p>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header">Controls</div>
  <div class="card-body">
    <div class="btn-group">
      <button class="btn btn-primary"  onclick="run('sync')">↻ Sync</button>
      <button class="btn btn-success"  onclick="run('restart','local')">⟳ Restart local</button>
      <button class="btn btn-warning"  onclick="run('restart','remote')">⟳ Restart remote</button>
      <button class="btn btn-danger"   onclick="run('restart','all')">⟳ Restart all</button>
    </div>
  </div>
</div>

<div class="card" id="out-card" style="display:none">
  <div class="card-header">Output</div>
  <div class="card-body"><pre class="output" id="out"></pre></div>
</div>

<script>
const SELF = <?= json_encode($self) ?>;
const NODES = <?= json_encode($nodes) ?>;

async function post(data) {
    const r = await fetch('action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data).toString()
    });
    return r.json();
}

async function loadStatus(node) {
    const target = node === SELF ? 'local' : 'remote';
    const d = await post({action: 'status', target});
    const active = d.output.includes('active (running)');
    const dot = document.getElementById('dot-' + node);
    dot.className = 'dot ' + (active ? 'dot-green' : 'dot-red');
    const line = d.output.split('\n').find(l => l.trim().startsWith('Active:')) || '';
    document.getElementById('status-' + node).textContent = line.trim() || '—';
}

async function run(action, target) {
    document.getElementById('out-card').style.display = '';
    document.getElementById('out').textContent = 'Running…';
    const data = {action};
    if (target) data.target = target;
    const d = await post(data);
    document.getElementById('out').textContent = d.output;
    if (action === 'restart') {
        setTimeout(() => NODES.forEach(loadStatus), 2000);
    }
}

NODES.forEach(loadStatus);
</script>
<?php page_end(); ?>
