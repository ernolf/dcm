<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/layout.php';
require_once 'inc/cli.php';
require_once 'inc/hosts_file.php';
require_once 'inc/dnsmasq_build.php';

require_auth();

$nodes = array_values(array_filter(
    array_map('trim', file(NODES_FILE, FILE_IGNORE_NEW_LINES) ?: []),
    fn($l) => $l !== '' && !str_starts_with($l, '#')
));
$self = trim((string)shell_exec('hostname -s'));

// Listen address + port per node — shown as status. listen.conf is generated
// from each node's hosts/local IP (127.0.0.1 + that IP); port defaults to 53.
$node_ip = [];
foreach ((new HostsFile(HOSTS_DIR . '/local'))->entries() as $e) {
    if (!$e['enabled']) continue;
    foreach ($e['hostnames'] as $hn) {
        if (!isset($node_ip[$hn])) $node_ip[$hn] = $e['ip'];
    }
}
$port = dropins_merge(DNSMASQ_D)['port'][0] ?? '53';

// Running dnsmasq build per node, as of the last health run. The local node is
// asked directly so the page is never blank on a cold cache; the health poll
// refreshes all of them a moment later.
$builds = dnsmasq_build_cluster();
$builds[$self] = dnsmasq_build_local();

page_start('Dashboard', __FILE__, 'narrow');
?>
<div class="alert alert-err" id="build-warn" style="display:none"></div>

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
      <p class="text-muted" style="font-size:.8rem;margin-top:.4rem">
        dnsmasq <code id="build-<?= h($node) ?>"><?= h($builds[$node]['version'] ?? '—') ?></code>
      </p>
      <?php if (!empty($node_ip[$node])): ?>
      <p class="text-muted" style="font-size:.8rem;margin-top:.4rem">
        Listening on <code>127.0.0.1</code> and <code><?= h($node_ip[$node]) ?></code> on port <?= h((string) $port) ?>
      </p>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header">Controls</div>
  <div class="card-body">
    <div class="btn-group">
      <button class="btn btn-secondary" onclick="run('diff')">⤳ What differs?</button>
      <button class="btn btn-primary"   onclick="run('sync')">↻ Sync</button>
      <button class="btn btn-success"   onclick="run('restart','local')">⟳ Restart local</button>
      <button class="btn btn-warning"   onclick="run('restart','remote')">⟳ Restart remote</button>
      <button class="btn btn-danger"    onclick="run('restart','all')">⟳ Restart all</button>
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
        setTimeout(() => { NODES.forEach(loadStatus); if (window.dcmHealthPoll) dcmHealthPoll(); }, 2000);
    } else if (action === 'sync' && window.dcmHealthPoll) {
        dcmHealthPoll();
    }
}

// Same dnsmasq on every node is a hard requirement: dcm replicates one set of
// config files to all of them, and an option the older build does not know
// makes it fail to start on the next restart.
function showBuild(d) {
    (d['build-nodes'] || '').split(',').forEach(pair => {
        const i = pair.lastIndexOf(':');
        if (i < 1) return;
        const el = document.getElementById('build-' + pair.slice(0, i));
        if (el) el.textContent = pair.slice(i + 1) || '—';
    });
    const warn = document.getElementById('build-warn');
    const msg = [];
    if (d.build === 'mixed') {
        msg.push('The nodes run different dnsmasq versions (' + d['build-nodes'] + '). '
               + 'Update every node to ' + d['build-newest'] + ' — the same configuration does not behave the same on all versions.');
    }
    if (d['build-features'] === 'mixed') {
        msg.push('The dnsmasq builds differ in their compile time options, so a directive available on one node can be missing on another.');
    }
    if (d['build-unknown']) {
        msg.push('These configured directives are unknown to the node they are listed with: ' + d['build-unknown']
               + '. dnsmasq exits at startup on an option it does not know, so that node will not come back after the next restart.');
    }
    warn.textContent = msg.join(' ');
    warn.style.display = msg.length ? '' : 'none';
}
document.addEventListener('dcm-health', e => showBuild(e.detail));
if (window.dcmHealth) showBuild(window.dcmHealth);

NODES.forEach(loadStatus);
</script>
<?php page_end(); ?>
