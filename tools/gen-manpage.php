<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

// Extract per-directive help text from the dnsmasq(8) manual page and emit
// www/inc/dnsmasq_manpage.php as  key => description.
//
// Usage:
//   php tools/gen-manpage.php [<dnsmasq.8[.gz]>] [<dnsmasq_directives.php>]
//
// With no arguments it locates the manpage via `man -w dnsmasq` and filters to
// the keys declared in the sibling www/inc/dnsmasq_directives.php. The manpage
// text is licensed GPL (dnsmasq), compatible with this project.

$src = $argv[1] ?? trim((string) shell_exec('man -w dnsmasq 2>/dev/null'));
if ($src === '' || !is_file($src)) {
    fwrite(STDERR, "dnsmasq manpage not found (give its path as argv[1])\n");
    exit(1);
}
$raw = str_ends_with($src, '.gz')
    ? (string) file_get_contents('compress.zlib://' . $src)
    : (string) file_get_contents($src);

// Optional schema filter: keep only keys the Configuration page knows.
$keep = null;
$schema = $argv[2] ?? __DIR__ . '/../www/inc/dnsmasq_directives.php';
if (is_file($schema)) {
    require $schema;
    if (function_exists('dnsmasq_directives')) {
        $keep = [];
        foreach (array_keys(dnsmasq_directives()) as $k) $keep[strtolower($k)] = true;
    }
}

// Turn a block of troff description lines into plain text.
function clean_troff(array $lines): string {
    $out = [];
    foreach ($lines as $l) {
        $t = rtrim($l);
        if ($t === '')      { $out[] = ''; continue; }
        if ($t[0] === '.') {
            if (preg_match('/^\.(B|I|BR|IR|BI|IB|RB|RI)\s+(.*)$/', $t, $m)) {
                $out[] = $m[2];                 // inline emphasis: keep the words
            } elseif (preg_match('/^\.(br|PP|LP|sp|IP)\b/', $t)) {
                $out[] = '';                    // paragraph / line break
            }                                   // other macros (.RS .RE .nf .fi): drop
            continue;
        }
        $out[] = $t;
    }
    $text = implode("\n", $out);

    // Inline troff escapes.
    $text = preg_replace('/\\\\f(\([A-Za-z]{2}|[A-Za-z])/', '', $text);  // \fB \fP \f(CW
    $text = preg_replace('/\\\\\((aq|cq|oq)/', "'", $text);             // typographic quotes
    $text = preg_replace('/\\\\\(dq/', '"', $text);
    $text = str_replace(['\\-', '\\&', '\\ '], ['-', '', ' '], $text);
    $text = str_replace('\\', '', $text);                              // drop remaining escapes

    // Unwrap: the manpage hard-wraps lines within a paragraph (source layout,
    // not meaningful). Join those into one line; keep only real breaks (blank
    // lines, .br, .PP) as paragraph separators.
    $paras = preg_split('/\n[ \t]*\n/', $text);
    $paras = array_map(function ($p) {
        $p = preg_replace('/\s*\n\s*/', ' ', $p);   // newlines within a paragraph -> space
        return trim(preg_replace('/[ \t]+/', ' ', $p));
    }, $paras);
    $paras = array_values(array_filter($paras, fn($p) => $p !== ''));
    return implode("\n\n", $paras);
}

$lines = explode("\n", $raw);
$n = count($lines);
$i = 0;
$in_options = false;
$map = [];

while ($i < $n) {
    $line = $lines[$i];
    if (preg_match('/^\.SH\s+OPTIONS/', $line)) { $in_options = true; $i++; continue; }
    if ($in_options && preg_match('/^\.SH\s/', $line)) break;          // end of OPTIONS

    if ($in_options && rtrim($line) === '.TP') {
        $i++;
        while ($i < $n && trim($lines[$i]) === '') $i++;              // skip blanks
        $tag = $lines[$i] ?? '';
        $keys = [];
        if (preg_match('/^\.B\b(.*)$/', $tag, $m)
            && preg_match_all('/--([A-Za-z0-9][A-Za-z0-9-]*)/', $m[1], $mm)) {
            $keys = $mm[1];
        }
        $i++;
        $desc = [];
        while ($i < $n) {
            $l = $lines[$i];
            if (rtrim($l) === '.TP' || preg_match('/^\.SH\s/', $l)) break;
            $desc[] = $l;
            $i++;
        }
        $text = clean_troff($desc);
        foreach ($keys as $k) {
            $lk = strtolower($k);
            if ($keep !== null && !isset($keep[$lk])) continue;
            if ($text !== '' && !isset($map[$lk])) $map[$lk] = $text;
        }
        continue;
    }
    $i++;
}

// Manual overrides where the manpage co-documents an option inside another
// option's block (e.g. --local shares the --server block) and that shared text
// is a poor fit for the setting.
$overrides = [
    'local' => "Force a domain to be answered locally only: dnsmasq replies from /etc/hosts, DHCP or configuration and never forwards it upstream. It is a synonym for --server with no address (--server=/<domain>/) and also applies to reverse (in-addr.arpa) zones. Example: --local=/lan/",
];
foreach ($overrides as $k => $v) {
    $lk = strtolower($k);
    if ($keep === null || isset($keep[$lk])) $map[$lk] = $v;
}

ksort($map);
$ver = trim((string) shell_exec('dnsmasq --version 2>/dev/null | head -1'));

$php  = "<?php\n";
$php .= "// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz\n";
$php .= "// SPDX-License-Identifier: GPL-3.0-or-later\n\n";
$php .= "// Per-directive help text extracted from the dnsmasq(8) manual page\n";
$php .= "// (text licensed GPL, (c) Simon Kelley). Generated by tools/gen-manpage.php";
$php .= $ver !== '' ? "\n// from " . $ver . "." : ".";
$php .= "\n// Do not edit by hand; regenerate when the dnsmasq version changes.\n\n";
$php .= "function dnsmasq_manpage(): array {\n    return [\n";
foreach ($map as $k => $v) {
    $php .= '        ' . var_export($k, true) . ' => ' . var_export($v, true) . ",\n";
}
$php .= "    ];\n}\n";

echo $php;
