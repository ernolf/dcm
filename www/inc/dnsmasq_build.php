<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

// What the installed dnsmasq can actually do. dcm is not built against one
// particular release: it asks the binary at runtime, so there is no table of
// which option appeared in which version to keep up to date. And since dcm
// replicates one identical config to every node, the UI has to offer what the
// *weakest* node in the cluster understands — a directive only the newest
// build knows would take the oldest node down on its next restart.

/** Per-node build, written by `dcm-cli health`: node, version, features, options. */
define('BUILD_CACHE', '/var/lib/dcm/cluster-build');

/** Splits a "Compile time options" list into ['ipset' => true, 'nftset' => false, …]. */
function dnsmasq_parse_features(string $list): array {
    $out = [];
    foreach (preg_split('/\s+/', trim($list), -1, PREG_SPLIT_NO_EMPTY) as $opt) {
        $on = !str_starts_with($opt, 'no-');
        $out[strtolower($on ? $opt : substr($opt, 3))] = $on;
    }
    return $out;
}

/** Turns a space-separated option list into a lookup map. Option names are case-sensitive. */
function dnsmasq_parse_options(string $list): array {
    return array_fill_keys(preg_split('/\s+/', trim($list), -1, PREG_SPLIT_NO_EMPTY), true);
}

/** Path of the dnsmasq binary, or null. www-data's PATH rarely holds the sbin dirs. */
function dnsmasq_binary(): ?string {
    foreach (['/usr/sbin/dnsmasq', '/usr/local/sbin/dnsmasq', '/sbin/dnsmasq'] as $p) {
        if (is_executable($p)) return $p;
    }
    $p = trim((string) shell_exec('command -v dnsmasq 2>/dev/null'));
    return $p !== '' && is_executable($p) ? $p : null;
}

/**
 * The build running on this node:
 *   ['version' => '2.91', 'features' => ['ipset' => true, …], 'options' => ['address' => true, …]]
 * LC_ALL=C because dnsmasq translates both outputs.
 */
function dnsmasq_build_local(): array {
    static $build = null;
    if ($build !== null) return $build;

    $build = ['version' => '', 'features' => [], 'options' => []];
    $bin   = dnsmasq_binary();
    if ($bin === null) return $build;

    $bin = 'LC_ALL=C ' . escapeshellarg($bin);
    foreach (explode("\n", (string) shell_exec($bin . ' --version 2>/dev/null')) as $line) {
        if (preg_match('/^dnsmasq version\s+(\S+)/i', $line, $m)) {
            $build['version'] = $m[1];
        } elseif (preg_match('/^Compile time options:\s*(.*)$/i', $line, $m)) {
            $build['features'] = dnsmasq_parse_features($m[1]);
        }
    }
    // --help lists every long option this build accepts, one per line, either
    // as "-x, --name" or indented as "--name".
    foreach (explode("\n", (string) shell_exec($bin . ' --help 2>/dev/null')) as $line) {
        if (preg_match('/^(?:-[^,]*, )?\s*--([a-zA-Z0-9-]+)/', $line, $m)) {
            $build['options'][$m[1]] = true;
        }
    }
    return $build;
}

/**
 * Every node's build as of the last health run:
 *   ['<node>' => ['version' => …, 'features' => …, 'options' => …], …]
 * Empty while the cache is cold — health writes it on its first run.
 */
function dnsmasq_build_cluster(): array {
    static $nodes = null;
    if ($nodes !== null) return $nodes;

    $nodes = [];
    foreach (@file(BUILD_CACHE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $f = explode("\t", $line);
        if (count($f) < 2 || $f[0] === '') continue;
        $nodes[$f[0]] = [
            'version'  => $f[1],
            'features' => dnsmasq_parse_features($f[2] ?? ''),
            'options'  => dnsmasq_parse_options($f[3] ?? ''),
        ];
    }
    return $nodes;
}

/**
 * The common denominator every node in the cluster can be held to: the oldest
 * version, and only those features and options all of them have. Falls back to
 * this node's own build while the cache is cold, which is the best that is
 * known then — health corrects it within one polling interval.
 */
function dnsmasq_build_floor(): array {
    static $floor = null;
    if ($floor !== null) return $floor;

    $cluster = dnsmasq_build_cluster();
    if (!$cluster) {
        return $floor = dnsmasq_build_local() + ['nodes' => 1, 'mixed' => false];
    }

    $version  = null;
    $features = null;
    $options  = null;
    foreach ($cluster as $b) {
        if ($version === null || ($b['version'] !== '' && version_compare($b['version'], $version, '<'))) {
            $version = $b['version'];
        }
        // A feature or option counts only when no node lacks it.
        if ($features === null) {
            $features = $b['features'];
            $options  = $b['options'];
        } else {
            foreach ($features as $k => $on) {
                if ($on && !($b['features'][$k] ?? false)) $features[$k] = false;
            }
            $options = array_intersect_key($options, $b['options']);
        }
    }

    return $floor = [
        'version'  => (string) $version,
        'features' => $features ?: [],
        'options'  => $options ?: [],
        'nodes'    => count($cluster),
        'mixed'    => count(array_unique(array_column($cluster, 'version'))) > 1,
    ];
}

/**
 * Why the cluster cannot run this directive, or null when it can. The option
 * itself is looked up in what the binaries report; 'needs' names a compile-time
 * feature, which --help does not cover — dnsmasq lists such options and then
 * refuses them at startup.
 */
function dnsmasq_directive_blocked(string $key, array $dir, array $floor): ?string {
    if ($floor['options'] && !isset($floor['options'][$key])) {
        return 'dnsmasq ' . ($floor['version'] ?: 'on this cluster') . ' does not know this option';
    }
    if (isset($dir['needs']) && $floor['features']
        && !($floor['features'][strtolower($dir['needs'])] ?? false)) {
        return 'this build has no ' . $dir['needs'] . ' support';
    }
    return null;
}
