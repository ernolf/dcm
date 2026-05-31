<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

require_once 'inc/config.php';
require_once 'inc/auth.php';
require_once 'inc/layout.php';
require_once 'inc/cli.php';

require_auth();

// GET params override cookie; cookie is fallback for direct page loads
$period  = $_GET['period'] ?? ($_COOKIE['analytics_period'] ?? 'today');
if (!in_array($period, ['1h','today','24h','7d','all'], true)) $period = 'today';

$servers = $_GET['srv'] ?? ($_COOKIE['analytics_srv'] ?? 'local');
if (!in_array($servers, ['local','remote','both'], true)) $servers = 'local';

// Persist selection
setcookie('analytics_period', $period,  time() + 86400 * 30, '/');
setcookie('analytics_srv',    $servers, time() + 86400 * 30, '/');

$period_labels = ['1h' => 'Last hour', 'today' => 'Today', '24h' => 'Last 24h', '7d' => 'Last 7 days', 'all' => 'All logs'];

// Parse dcm-cli stats output into arrays
function parse_stats(string $raw): array {
    $s = [
        'total_queries' => 0, 'forwarded' => 0, 'cached' => 0,
        'cached_nxdomain' => 0, 'cached_nodata' => 0,
        'local_config' => 0, 'local_hosts' => 0, 'blocked' => 0,
        'nxdomain' => 0, 'nodata' => 0, 'nodata_ipv6' => 0,
        'servfail' => 0, 'refused' => 0, 'cname' => 0, 'resolved' => 0,
        'qtypes' => [], 'upstreams' => [], 'domains' => [], 'clients' => [], 'hours' => [],
    ];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (str_contains($line, "\t")) {
            [$type, $key, $val] = array_pad(explode("\t", $line, 3), 3, '0');
            $map = ['qtype' => 'qtypes', 'upstream' => 'upstreams', 'domain' => 'domains', 'client' => 'clients', 'hour' => 'hours'];
            if (isset($map[$type])) $s[$map[$type]][$key] = (int)$val;
        } elseif (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            if (isset($s[$k])) $s[$k] = (int)$v;
        }
    }
    arsort($s['upstreams']); arsort($s['domains']); arsort($s['clients']);
    ksort($s['hours']);
    return $s;
}

function fetch_stats(string $node, string $period): array {
    $r = cli_run('stats', $node, $period);
    return parse_stats($r['output']);
}

$data = [];
if ($servers === 'both') {
    $data['local']  = fetch_stats('local',  $period);
    $data['remote'] = fetch_stats('remote', $period);
} else {
    $data[$servers] = fetch_stats($servers, $period);
}

function pct(int $part, int $total): string {
    return $total > 0 ? round($part / $total * 100, 1) . '%' : '0%';
}

function bar_pct(int $part, int $total): int {
    return $total > 0 ? (int)round($part / $total * 100) : 0;
}

function render_stats(array $s, string $label): void {
    $tq   = $s['total_queries'];
    $res  = $s['forwarded'] + $s['cached'] + $s['local_config'] + $s['local_hosts'];
    $cache_rate = $res > 0 ? round(($s['cached'] / ($s['forwarded'] + $s['cached'] ?: 1)) * 100, 1) : 0;
    ?>
    <h3 style="font-size:.9rem;font-weight:600;margin-bottom:.75rem;color:var(--text-muted)"><?= h($label) ?></h3>

    <!-- Summary cards -->
    <div class="grid-3" style="margin-bottom:1rem">
      <div class="card"><div class="card-body" style="text-align:center">
        <div class="stat-value"><?= number_format($tq) ?></div>
        <div class="stat-label">Total Queries</div>
      </div></div>
      <div class="card"><div class="card-body" style="text-align:center">
        <div class="stat-value" style="color:var(--green)"><?= $cache_rate ?>%</div>
        <div class="stat-label">Cache Hit Rate</div>
      </div></div>
      <div class="card"><div class="card-body" style="text-align:center">
        <div class="stat-value" style="color:var(--red)"><?= number_format($s['blocked']) ?></div>
        <div class="stat-label">Blocked</div>
      </div></div>
    </div>

    <div class="grid-2" style="margin-bottom:1rem">

      <!-- Query types -->
      <div class="card">
        <div class="card-header">Query Types</div>
        <div class="card-body" style="padding:.75rem">
          <?php
          $total_qt = array_sum($s['qtypes']) ?: 1;
          arsort($s['qtypes']);
          foreach ($s['qtypes'] as $qt => $cnt): ?>
          <div style="margin-bottom:.4rem">
            <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:2px">
              <span class="text-mono"><?= h($qt) ?></span>
              <span><?= number_format($cnt) ?> (<?= pct($cnt, $total_qt) ?>)</span>
            </div>
            <div style="background:var(--border);border-radius:2px;height:6px">
              <div style="background:var(--blue);height:6px;border-radius:2px;width:<?= bar_pct($cnt, $total_qt) ?>%"></div>
            </div>
          </div>
          <?php endforeach; if (empty($s['qtypes'])): ?><p class="text-muted">No data</p><?php endif; ?>
        </div>
      </div>

      <!-- Reply outcomes -->
      <div class="card">
        <div class="card-header">Reply Outcomes</div>
        <div class="card-body" style="padding:.75rem">
          <?php
          $outcomes = [
              'Resolved (IP)'    => [$s['resolved'],    '#22c55e'],
              'From cache'       => [$s['cached'],       '#4ade80'],
              'CNAME'            => [$s['cname'],        '#60a5fa'],
              'Local (hosts)'    => [$s['local_hosts'] + $s['local_config'], '#94a3b8'],
              'NXDOMAIN'         => [$s['nxdomain'] + $s['cached_nxdomain'],  '#ef4444'],
              'NODATA'           => [$s['nodata'] + $s['cached_nodata'],       '#fb923c'],
              'NODATA-IPv6'      => [$s['nodata_ipv6'],  '#f59e0b'],
              'SERVFAIL'         => [$s['servfail'],     '#dc2626'],
              'REFUSED'          => [$s['refused'],      '#991b1b'],
          ];
          $total_out = array_sum(array_column($outcomes, 0)) ?: 1;
          foreach ($outcomes as $label2 => [$cnt, $color]):
              if ($cnt === 0) continue; ?>
          <div style="margin-bottom:.4rem">
            <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:2px">
              <span><?= h($label2) ?></span>
              <span><?= number_format($cnt) ?> (<?= pct($cnt, $total_out) ?>)</span>
            </div>
            <div style="background:var(--border);border-radius:2px;height:6px">
              <div style="background:<?= $color ?>;height:6px;border-radius:2px;width:<?= bar_pct($cnt, $total_out) ?>%"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Per-hour chart -->
    <?php if (!empty($s['hours'])): ?>
    <div class="card" style="margin-bottom:1rem">
      <div class="card-header">Queries per Hour</div>
      <div class="card-body" style="padding:.75rem">
        <?php
        $all_hours = [];
        for ($h = 0; $h < 24; $h++) $all_hours[str_pad($h, 2, '0', STR_PAD_LEFT)] = $s['hours'][str_pad($h, 2, '0', STR_PAD_LEFT)] ?? 0;
        $max_h = max($all_hours) ?: 1;
        ?>
        <div style="display:flex;align-items:flex-end;gap:3px;height:80px">
          <?php foreach ($all_hours as $hr => $cnt): ?>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px" title="<?= $hr ?>:00 — <?= number_format($cnt) ?> queries">
            <div style="width:100%;background:var(--blue);opacity:.8;border-radius:2px 2px 0 0;height:<?= round($cnt / $max_h * 72) ?>px;min-height:<?= $cnt > 0 ? 2 : 0 ?>px"></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:3px;margin-top:3px">
          <?php foreach ($all_hours as $hr => $_): ?>
          <div style="flex:1;text-align:center;font-size:.6rem;color:var(--text-muted)"><?= (int)$hr % 4 === 0 ? (int)$hr : '' ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="grid-3">
      <!-- Top Upstreams -->
      <div class="card">
        <div class="card-header">Top Upstream Servers</div>
        <div class="table-wrap">
        <table>
          <tr><th>Server</th><th>Queries</th></tr>
          <?php foreach (array_slice($s['upstreams'], 0, 10, true) as $srv => $cnt): ?>
          <tr><td class="text-mono"><?= h($srv) ?></td><td><?= number_format($cnt) ?></td></tr>
          <?php endforeach;
          if (empty($s['upstreams'])): ?><tr><td colspan="2" class="text-muted">No data</td></tr><?php endif; ?>
        </table>
        </div>
      </div>

      <!-- Top Domains -->
      <div class="card">
        <div class="card-header">Top Queried Domains</div>
        <div class="table-wrap">
        <table>
          <tr><th>Domain</th><th>Queries</th></tr>
          <?php foreach (array_slice($s['domains'], 0, 15, true) as $dom => $cnt): ?>
          <tr><td class="text-mono" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($dom) ?></td><td><?= number_format($cnt) ?></td></tr>
          <?php endforeach;
          if (empty($s['domains'])): ?><tr><td colspan="2" class="text-muted">No data</td></tr><?php endif; ?>
        </table>
        </div>
      </div>

      <!-- Top Clients -->
      <div class="card">
        <div class="card-header">Top Clients</div>
        <div class="table-wrap">
        <table>
          <tr><th>Client IP</th><th>Queries</th></tr>
          <?php foreach (array_slice($s['clients'], 0, 15, true) as $ip => $cnt): ?>
          <tr><td class="text-mono"><?= h($ip) ?></td><td><?= number_format($cnt) ?></td></tr>
          <?php endforeach;
          if (empty($s['clients'])): ?><tr><td colspan="2" class="text-muted">No data</td></tr><?php endif; ?>
        </table>
        </div>
      </div>
    </div>
    <?php
}

page_start('Analytics', __FILE__);
?>
<!-- Filters -->
<div class="card mb-2">
  <div class="card-body" style="padding:.75rem">
    <form method="get" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
      <div class="form-group">
        <label>Time period</label>
        <select name="period" onchange="this.form.submit()">
          <?php foreach ($period_labels as $val => $lbl): ?>
          <option value="<?= $val ?>"<?= $period === $val ? ' selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Server</label>
        <select name="srv" onchange="this.form.submit()">
          <option value="local"<?= $servers === 'local' ? ' selected' : '' ?>>Local</option>
          <option value="remote"<?= $servers === 'remote' ? ' selected' : '' ?>>Remote</option>
          <option value="both"<?= $servers === 'both' ? ' selected' : '' ?>>Both (side by side)</option>
        </select>
      </div>
      <div class="form-group" style="justify-content:flex-end">
        <label>&nbsp;</label>
        <button class="btn btn-primary">Refresh</button>
      </div>
      <div style="margin-left:auto;color:var(--text-muted);font-size:.8rem;align-self:flex-end">
        <?= h($period_labels[$period]) ?> · <?= h($servers) ?>
      </div>
    </form>
  </div>
</div>

<?php if ($servers === 'both'): ?>
<div class="grid-2">
  <div><?php render_stats($data['local'],  'Local'); ?></div>
  <div><?php render_stats($data['remote'], 'Remote'); ?></div>
</div>
<?php else: ?>
  <?php render_stats($data[$servers], $servers === 'local' ? 'Local' : 'Remote'); ?>
<?php endif; ?>

<?php page_end(); ?>
