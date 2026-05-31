<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

function render_nav(): array {
    return [
        ['file' => 'dashboard.php',  'label' => 'Dashboard',         'icon' => '◈'],
        ['file' => 'hosts.php',      'label' => 'Hosts',              'icon' => '⊞'],
        ['file' => 'vms.php',        'label' => 'Virtual Machines',   'icon' => '⬡'],
        ['file' => 'block.php',      'label' => 'Block List',         'icon' => '⊘'],
        ['file' => 'upstream.php',   'label' => 'Upstream DNS',       'icon' => '↑'],
        ['file' => 'dnsconf.php',    'label' => 'Configuration',      'icon' => '⚙'],
        ['file' => 'live.php',       'label' => 'Live Log',           'icon' => '▶'],
        ['file' => 'analytics.php',  'label' => 'Analytics',          'icon' => '≡'],
    ];
}

function page_start(string $title, string $current): void {
    $nav  = render_nav();
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
    <button class="theme-toggle" id="theme-btn" onclick="toggleTheme()">🌙</button>
  </div>
</header>

<!-- Nav overlay backdrop -->
<div class="nav-backdrop" id="nav-backdrop" onclick="closeNav()"></div>

<!-- Slide-in nav panel -->
<nav class="nav-panel" id="nav-panel">
  <?php foreach ($nav as $item): ?>
  <a href="<?= $item['file'] ?>"
     class="nav-link<?= $self === $item['file'] ? ' active' : '' ?>"
     onclick="closeNav()">
    <i class="nav-icon"><?= $item['icon'] ?></i><?= h($item['label']) ?>
  </a>
  <?php endforeach; ?>
</nav>

<!-- Page content -->
<div class="page-body">
  <div class="content">

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
