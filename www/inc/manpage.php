<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

// Per-directive help text, read from the dnsmasq(8) manual page installed on
// this machine — the one that belongs to the running binary, so the help
// describes the behaviour this node actually has. Manual pages are stripped on
// minimal installs, so inc/dnsmasq_manpage.php ships a generated copy as the
// fallback. Both paths use the same parser, which lives here.
//
// The manpage text is licensed GPL (dnsmasq, (c) Simon Kelley).

require_once __DIR__ . '/dnsmasq_directives.php';

/** Turns a block of troff description lines into plain text. */
function manpage_clean_troff(array $lines): string {
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

/**
 * Extracts the OPTIONS section of a dnsmasq(8) manpage into key => description.
 * $keep limits the result to those lower-cased keys; null keeps everything.
 */
function manpage_parse(string $raw, ?array $keep = null): array {
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
            while ($i < $n && trim($lines[$i]) === '') $i++;               // skip blanks
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
            $text = manpage_clean_troff($desc);
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
    // option's block (e.g. --local shares the --server block) and that shared
    // text is a poor fit for the setting.
    $overrides = [
        'local' => "Force a domain to be answered locally only: dnsmasq replies from /etc/hosts, DHCP or configuration and never forwards it upstream. It is a synonym for --server with no address (--server=/<domain>/) and also applies to reverse (in-addr.arpa) zones. Example: --local=/lan/",
    ];
    foreach ($overrides as $k => $v) {
        if ($keep === null || isset($keep[$k])) $map[$k] = $v;
    }

    ksort($map);
    return $map;
}

/** The installed dnsmasq(8) manpage, or null when the system carries none. */
function manpage_locate(): ?string {
    $src = trim((string) shell_exec('man -w dnsmasq 2>/dev/null'));
    if ($src !== '' && is_file($src)) return $src;
    $hit = glob('/usr/share/man/man8/dnsmasq.8*') ?: [];
    return $hit ? $hit[0] : null;
}

/** Reads a manpage file, transparently handling the gzipped form. */
function manpage_read(string $path): string {
    return (string) @file_get_contents(
        str_ends_with($path, '.gz') ? 'compress.zlib://' . $path : $path
    );
}

/**
 * Help text for the schema's directives, from the manpage of the dnsmasq that
 * is installed here; the generated copy fills in when none is present.
 */
function dnsmasq_manpage(): array {
    static $map = null;
    if ($map !== null) return $map;

    $src = manpage_locate();
    if ($src !== null) {
        $keep = [];
        foreach (array_keys(dnsmasq_directives()) as $k) $keep[strtolower($k)] = true;
        $map = manpage_parse(manpage_read($src), $keep);
        if ($map) return $map;
    }
    // Loaded only when it is really needed: it is the larger of the two paths,
    // and it keeps this file usable by tools/gen-manpage.php, which produces it.
    require_once __DIR__ . '/dnsmasq_manpage.php';
    return $map = dnsmasq_manpage_bundled();
}
