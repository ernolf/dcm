<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dropins.php';
require_once __DIR__ . '/dnsmasq_directives.php';

// Pages whose driving directive is currently off (schema 'cascade_off') are
// hidden from the nav. Today: query logging off -> Live Log and Analytics, which
// have no log to read, disappear.
function nav_hidden_pages(): array {
    if (!defined('DNSMASQ_D')) return [];
    $merged = dropins_merge(DNSMASQ_D);
    $hidden = [];
    foreach (dnsmasq_directives() as $key => $entry) {
        if (empty($entry['cascade_off'])) continue;
        if (dropin_state($key, $entry, $merged)['state'] === 'default') {
            array_push($hidden, ...$entry['cascade_off']);
        }
    }
    return $hidden;
}

function render_nav(): array {
    return [
        ['file' => 'dashboard.php',  'label' => 'Dashboard',         'icon' => '◈'],
        ['file' => 'dnsconf.php',    'label' => 'Configuration',      'icon' => '⚙'],
        ['file' => 'hosts.php',      'label' => 'Hosts',              'icon' => '⊞'],
        ['file' => 'vms.php',        'label' => 'Virtual Machines',   'icon' => '⬡'],
        ['file' => 'block.php',      'label' => 'Block List',         'icon' => '⊘'],
        ['file' => 'upstream.php',   'label' => 'Upstream DNS',       'icon' => '↑'],
        ['file' => 'live.php',       'label' => 'Live Log',           'icon' => '▶'],
        ['file' => 'analytics.php',  'label' => 'Analytics',          'icon' => '≡'],
    ];
}

function page_start(string $title, string $current, string $width = ''): void {
    $nav    = render_nav();
    $hidden = nav_hidden_pages();
    if ($hidden) {
        $nav = array_values(array_filter($nav, fn($i) => !in_array($i['file'], $hidden, true)));
    }
    $self = basename($current);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> — dns-admin</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/svg+xml" href="assets/icon.svg">
<script>
(function() {
    const t = localStorage.getItem('dns-admin-theme') || 'light';
    if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
})();
</script>
</head>
<body>

<!-- Fixed top bar -->
<header class="topbar">
  <button class="waffle-btn" id="nav-toggle" onclick="toggleNav()" aria-label="Navigation">
    <span class="waffle-icon">
      <b></b><b></b><b></b><b></b><b></b><b></b><b></b><b></b><b></b>
    </span>
    <span class="waffle-open">
      <span class="waffle-open-lines"><b></b><b></b><b></b></span>
      <span class="waffle-open-chevron">‹</span>
    </span>
  </button>
  <div class="topbar-brand">
    <img src="assets/logo.svg" class="topbar-logo" alt="dnsmasq cluster manager">
  </div>
  <div class="topbar-title"><?= h($title) ?></div>
  <div class="topbar-actions">
    <button class="bell-btn" id="bell-btn" onclick="toggleBell(event)" aria-label="Notifications">🔔</button>
    <button class="theme-toggle" id="theme-btn" onclick="toggleTheme()">🌙</button>
  </div>
</header>

<!-- Nav overlay backdrop -->
<div class="nav-backdrop" id="nav-backdrop" onclick="closeNav()"></div>

<!-- Notifications: dropdown panel + transient toasts -->
<div class="bell-panel" id="bell-panel" onclick="event.stopPropagation()">
  <div class="bell-panel-head">Notifications</div>
  <div class="bell-panel-body" id="bell-list"><div class="bell-empty">No notifications.</div></div>
</div>
<div class="toast-wrap" id="toast-wrap"></div>

<!-- Slide-in nav panel -->
<nav class="nav-panel" id="nav-panel">
  <?php foreach ($nav as $item): ?>
  <a href="<?= $item['file'] ?>"
     class="nav-link<?= $self === $item['file'] ? ' active' : '' ?>"
     onclick="closeNav()">
    <i class="nav-icon"><?= $item['icon'] ?></i><?= h($item['label']) ?>
  </a>
  <?php endforeach; ?>
  <a class="nav-repo" href="https://github.com/ernolf/dcm" target="_blank" rel="noopener noreferrer">
    <svg class="nav-repo-icon" viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
    <span class="nav-repo-url">github.com/ernolf/dcm</span>
  </a>
</nav>

<!-- Page content -->
<div class="page-body">
  <div class="content<?= $width !== '' ? ' ' . $width : '' ?>">

<script>
function openNav() {
    document.getElementById('nav-panel').classList.add('open');
    document.getElementById('nav-backdrop').classList.add('open');
    document.body.classList.add('nav-open');
}
function closeNav() {
    document.getElementById('nav-panel').classList.remove('open');
    document.getElementById('nav-backdrop').classList.remove('open');
    document.body.classList.remove('nav-open');
}
function toggleNav() {
    document.body.classList.contains('nav-open') ? closeNav() : openNav();
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeNav();
});

function toggleTheme() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const next = isDark ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('dns-admin-theme', next);
    document.getElementById('theme-btn').textContent = next === 'dark' ? '☀' : '🌙';
}

document.addEventListener('DOMContentLoaded', function() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    document.getElementById('theme-btn').textContent = isDark ? '☀' : '🌙';
});
</script>

<script>
// Notifications: a transient toast plus a bell with a badge dot, fed by
// 'dcm-cli health' (live sync/restart state). dcmToast is global so editable
// pages can confirm a save without a sticky banner.
function dcmToast(msg, kind) {
    const wrap = document.getElementById('toast-wrap');
    if (!wrap) return;
    const t = document.createElement('div');
    t.className = 'toast' + (kind ? ' toast-' + kind : '');
    t.textContent = msg;
    wrap.appendChild(t);
    requestAnimationFrame(() => t.classList.add('show'));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 9000);
}
function toggleBell(ev) {
    ev.stopPropagation();
    document.getElementById('bell-panel').classList.toggle('open');
}
document.addEventListener('click', () => document.getElementById('bell-panel').classList.remove('open'));

(function () {
    const bell = document.getElementById('bell-btn');
    let toastSeen = new Set();   // in-memory: toast each health message once per page
    let first = true;
    let shakeTimer = null;       // periodic reminder shake while anything is pending
    let audioCtx = null;

    function shake() {
        if (!bell) return;
        bell.classList.remove('shake');
        void bell.offsetWidth;       // reflow so the animation can restart
        bell.classList.add('shake');
    }
    if (bell) bell.addEventListener('animationend', () => bell.classList.remove('shake'));

    function ring() {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const t0 = audioCtx.currentTime;
            [880, 1320].forEach((freq, i) => {        // short two-tone bell ding
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                const t = t0 + i * 0.06;
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.0001, t);
                gain.gain.exponentialRampToValueAtTime(0.22, t + 0.01);
                gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.6);
                osc.connect(gain).connect(audioCtx.destination);
                osc.start(t);
                osc.stop(t + 0.65);
            });
        } catch (e) { /* Web Audio unavailable — the shake still fires */ }
    }
    // Browsers block audio until a user gesture; prime/resume the context on any click.
    document.addEventListener('click', () => {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            if (audioCtx.state === 'suspended') audioCtx.resume();
        } catch (e) {}
    });

    // Ids already announced with a ring, kept across reloads so navigating while
    // something is pending does not ring again — only a newly appearing one does.
    function rungIds() {
        try { return new Set(JSON.parse(sessionStorage.getItem('dcm-rung') || '[]')); }
        catch (e) { return new Set(); }
    }
    function storeRung(ids) {
        try { sessionStorage.setItem('dcm-rung', JSON.stringify(ids)); } catch (e) {}
    }

    function messages(d) {
        const m = [];
        if (d.sync === 'stale') {
            const n = d['sync-nodes'] ? ' (' + d['sync-nodes'] + ')' : '';
            m.push({id: 'sync', text: 'Configuration differs from another node' + n + ' — run Sync on the Dashboard.'});
        }
        if (d.restart === 'needed') {
            const n = d['restart-nodes'] ? ' (' + d['restart-nodes'] + ')' : '';
            m.push({id: 'restart', text: 'Settings changed' + n + ' — restart dnsmasq on the Dashboard.'});
        }
        return m;
    }
    function render(m) {
        document.getElementById('bell-btn').classList.toggle('has-notif', m.length > 0);
        const list = document.getElementById('bell-list');
        if (!m.length) { list.innerHTML = '<div class="bell-empty">No notifications.</div>'; return; }
        list.innerHTML = '';
        m.forEach(x => {
            const a = document.createElement('a');
            a.className = 'bell-item';
            a.href = 'dashboard.php';
            a.textContent = x.text;
            list.appendChild(a);
        });
    }
    async function poll() {
        let d;
        try {
            const r = await fetch('action.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=health'
            });
            d = await r.json();
        } catch (e) { return; }
        const m = messages(d);
        render(m);

        if (!first) m.forEach(x => { if (!toastSeen.has(x.id)) dcmToast(x.text); });
        toastSeen = new Set(m.map(x => x.id));

        const ids  = m.map(x => x.id);
        const rung = rungIds();
        if (ids.some(id => !rung.has(id))) { shake(); ring(); }   // a new notification arrived
        storeRung(ids);

        if (ids.length && !shakeTimer)        shakeTimer = setInterval(shake, 15000);
        else if (!ids.length && shakeTimer) { clearInterval(shakeTimer); shakeTimer = null; }

        first = false;
    }
    window.dcmHealthPoll = poll;   // let the Dashboard re-check right after sync/restart
    poll();
    setInterval(poll, 60000);
})();

// Confirm a save with a transient toast instead of a sticky banner; the bell
// then carries the follow-up (sync/restart) as a live state. Drop the 'saved'
// query afterwards so a manual refresh does not toast again.
(function () {
    const sp = new URLSearchParams(location.search);
    if (!sp.has('saved')) return;
    dcmToast('Saved.');
    sp.delete('saved');
    const q = sp.toString();
    history.replaceState({}, '', location.pathname + (q ? '?' + q : ''));
})();
</script>
<?php
}

function page_end(): void {
    echo "  </div>\n</div>\n</body>\n</html>\n";
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function alert(string $type, string $msg): void {
    $cls = $type === 'ok' ? 'alert-ok' : 'alert-err';
    echo '<div class="alert ' . $cls . '">' . h($msg) . '</div>';
}
