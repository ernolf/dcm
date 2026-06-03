<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/layout.php';

require_auth();

$nodes  = array_values(array_filter(
    array_map('trim', file(NODES_FILE, FILE_IGNORE_NEW_LINES) ?: []),
    fn($l) => $l !== '' && !str_starts_with($l, '#')
));
$self   = trim((string)shell_exec('hostname -s'));
$remote = '';
foreach ($nodes as $n) { if ($n !== $self) { $remote = $n; break; } }

page_start('Live Log', __FILE__);
?>
<style>
.live-toolbar { display:flex; align-items:center; gap:.5rem; margin-bottom:.75rem; flex-wrap:wrap; }
.live-wrap.side  { max-width: calc(var(--content-width) * 2); margin-inline: auto; }
.live-wrap.stack { max-width: var(--content-width);           margin-inline: auto; }
.live-container { display:grid; gap:.75rem; height:calc(100vh - 156px); min-height:300px; }
.live-wrap.side  .live-container { grid-template-columns:1fr 1fr; grid-template-rows:1fr; }
.live-wrap.stack .live-container { grid-template-columns:1fr; grid-template-rows:1fr 1fr; }
.live-panel { display:flex; flex-direction:column; border-radius:.5rem; overflow:hidden; border:1px solid #334155; min-height:0; }
.live-panel-header { padding:.45rem .9rem; background:#1e293b; color:#e2e8f0; font-size:.8rem; display:flex; align-items:center; gap:.5rem; flex-shrink:0; border-bottom:1px solid #334155; }
.live-panel-header .panel-title { font-weight:600; }
.live-panel-header .panel-btns { margin-left:auto; display:flex; gap:.35rem; }
.live-log { flex:1; overflow-y:auto; background:#0f172a; color:#e2e8f0; padding:.6rem .75rem; margin:0; font-family:monospace; font-size:.75rem; line-height:1.55; min-height:0; white-space:pre-wrap; word-break:break-all; }
</style>

<div class="live-wrap side" id="live-wrap">
<div class="live-toolbar">
  <span style="font-size:.8rem;color:var(--text-muted)">Layout:</span>
  <button class="btn btn-primary   btn-sm" id="btn-side"  onclick="setLayout('side')" >⬜⬜ Side by side</button>
  <button class="btn btn-secondary btn-sm" id="btn-stack" onclick="setLayout('stack')">⬛<br>⬛ Stacked</button>
  <div style="margin-left:auto;display:flex;gap:.35rem">
    <button class="btn btn-secondary btn-sm" onclick="clearAll()">✕ Clear all</button>
  </div>
</div>

<div class="live-container" id="live-container">
<?php foreach (['local' => $self, 'remote' => $remote ?: 'remote'] as $key => $label): ?>
  <div class="live-panel" id="panel-<?= $key ?>">
    <div class="live-panel-header">
      <span class="dot dot-grey" id="dot-<?= $key ?>"></span>
      <span class="panel-title"><?= h($label) ?></span>
      <div class="panel-btns">
        <button class="btn btn-secondary btn-sm" id="btn-pause-<?= $key ?>"
                onclick="togglePause('<?= $key ?>')">⏸</button>
        <button class="btn btn-secondary btn-sm" onclick="clearLog('<?= $key ?>')">✕</button>
      </div>
    </div>
    <pre class="live-log" id="log-<?= $key ?>"></pre>
  </div>
<?php endforeach; ?>
</div>
</div>

<script>
const COLORS = {
    A:      '#60a5fa',
    AAAA:   '#a78bfa',
    HTTPS:  '#34d399',
    PTR:    '#93c5fd',
    other:  '#7dd3fc',
    fwd:    '#fbbf24',
    cached: '#4ade80',
    nx:     '#f87171',
    nodata: '#fb923c',
    serv:   '#ef4444',
    local:  '#64748b',
    def:    '#94a3b8',
};

function colorLine(line) {
    let c = COLORS.def;
    if      (/: query\[AAAA\]/.test(line))     c = COLORS.AAAA;
    else if (/: query\[A\]/.test(line))         c = COLORS.A;
    else if (/: query\[HTTPS\]/.test(line))     c = COLORS.HTTPS;
    else if (/: query\[PTR\]/.test(line))       c = COLORS.PTR;
    else if (/: query\[/.test(line))            c = COLORS.other;
    else if (/: forwarded /.test(line))         c = COLORS.fwd;
    else if (/: cached /.test(line))            c = COLORS.cached;
    else if (/NXDOMAIN/.test(line))             c = COLORS.nx;
    else if (/SERVFAIL|REFUSED/.test(line))     c = COLORS.serv;
    else if (/NODATA/.test(line))               c = COLORS.nodata;
    else if (/: config |\/etc\/dnsmasq/.test(line)) c = COLORS.local;
    const esc = line.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    return `<span style="color:${c}">${esc}</span>`;
}

const MAX_LINES = 600;
const paused    = { local: false, remote: false };
const sources   = {};

function appendLine(srv, text) {
    if (paused[srv]) return;
    const el  = document.getElementById('log-' + srv);
    const bot = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
    el.innerHTML += colorLine(text) + '\n';
    const lines = el.innerHTML.split('\n');
    if (lines.length > MAX_LINES + 50) el.innerHTML = lines.slice(-MAX_LINES).join('\n');
    if (bot) el.scrollTop = el.scrollHeight;
}

function setDot(srv, state) {
    document.getElementById('dot-' + srv).className =
        'dot ' + (state === 'ok' ? 'dot-green' : state === 'err' ? 'dot-red' : 'dot-grey');
}

function clearLog(srv)  { document.getElementById('log-' + srv).innerHTML = ''; }
function clearAll()     { clearLog('local'); clearLog('remote'); }

function togglePause(srv) {
    paused[srv] = !paused[srv];
    document.getElementById('btn-pause-' + srv).textContent = paused[srv] ? '▶' : '⏸';
}

function setLayout(mode) {
    document.getElementById('live-wrap').className = 'live-wrap ' + mode;
    document.getElementById('btn-side' ).className  = 'btn btn-sm ' + (mode === 'side'  ? 'btn-primary' : 'btn-secondary');
    document.getElementById('btn-stack').className  = 'btn btn-sm ' + (mode === 'stack' ? 'btn-primary' : 'btn-secondary');
}

function startStream(srv) {
    if (sources[srv]) sources[srv].close();
    setDot(srv, 'grey');
    const es = new EventSource('live_stream.php?server=' + srv);
    sources[srv] = es;

    es.onmessage = (e) => {
        setDot(srv, 'ok');
        const line = JSON.parse(e.data);
        if (!line.startsWith(':')) appendLine(srv, line);
    };
    es.onerror = () => {
        setDot(srv, 'err');
        es.close();
        setTimeout(() => startStream(srv), 5000);
    };
}

startStream('local');
startStream('remote');
</script>
<?php page_end(); ?>
