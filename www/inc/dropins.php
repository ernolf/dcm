<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

// Reads the dnsmasq conf-dir the way dnsmasq itself does: it merges every
// *.conf drop-in into one effective set of directives. The Configuration page
// derives each setting's state from this merge, not from filenames, so it
// reflects what dnsmasq actually sees regardless of how the drop-ins are split
// or hand-edited. The writer uses a one-file-per-directive convention, but the
// reader does not depend on it.

// Merge all *.conf drop-ins in $dir into a map of active directives.
// Returns [ 'directive' => ['value', ...] ]; a bare flag yields one empty
// string value. Comments (lines starting with #) and blank lines are skipped.
// Repeatable directives (server, addn-hosts, ...) collect all their values in
// file/line order.
function dropins_merge(string $dir): array {
    $merged = [];
    foreach (glob($dir . '/*.conf') ?: [] as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') continue;
            if (str_contains($line, '=')) {
                [$key, $val] = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
            } else {
                $key = $line;
                $val = '';
            }
            if ($key === '') continue;
            $merged[$key][] = $val;
        }
    }
    return $merged;
}

// Value part of an "on"/"off" template line, e.g. "cache-size=0" -> "0",
// "dnssec-check-unsigned=no" -> "no". A bare flag template -> null.
function dropin_template_value(?string $tpl): ?string {
    if ($tpl === null) return null;
    $pos = strpos($tpl, '=');
    return $pos === false ? null : trim(substr($tpl, $pos + 1));
}

// Resolve one schema directive against the merged drop-in set.
// Returns ['state' => 'default'|'on'|'off', 'value' => ?string, 'values' => array].
//   default = directive absent (dnsmasq's built-in default applies, greyed out)
//   off     = present and equal to the schema's explicit off-form value
//   on      = present with any other (active) value
function dropin_state(string $key, array $entry, array $merged): array {
    if (!array_key_exists($key, $merged)) {
        return ['state' => 'default', 'value' => null, 'values' => []];
    }
    $occ  = $merged[$key];
    $type = $entry['type'] ?? 'flag';

    if ($type === 'list') {
        return ['state' => 'on', 'value' => null, 'values' => $occ];
    }

    $val    = $occ[0] ?? '';
    $offVal = dropin_template_value($entry['off'] ?? null);
    if ($offVal !== null && $val === $offVal) {
        return ['state' => 'off', 'value' => $val, 'values' => $occ];
    }
    return ['state' => 'on', 'value' => $val, 'values' => $occ];
}

// Editable on the Configuration page: phase-1 directives that dcm does not
// own. managed (log-facility) and locked (addn-hosts, interface, port) rows are
// read-only here; phase-2 (upstream) is edited on the Upstream page.
function dropin_editable(array $entry, int $phase = 1): bool {
    return ($entry['phase'] ?? 1) === $phase && empty($entry['locked']) && empty($entry['managed']);
}

// Lines to write into <key>.conf for a desired state. Empty array => the file
// is removed (state 'default', or an "on" value type left blank).
//   $value: string for scalar types, array of strings for list types.
function dropin_lines(array $entry, string $state, $value): array {
    if ($state === 'default') return [];
    if ($state === 'off') {
        $off = $entry['off'] ?? null;
        return $off !== null ? [$off] : [];
    }
    // state 'on'
    $tpl = $entry['on'] ?? null;
    if ($tpl === null)                  return [];   // "on" is dnsmasq's default
    if (strpos($tpl, '%s') === false) {              // flag, optionally with =value
        $v = is_array($value) ? '' : trim((string) $value);
        return (!empty($entry['optval']) && $v !== '') ? ["$tpl=$v"] : [$tpl];
    }
    if (($entry['type'] ?? '') === 'list') {
        $lines = [];
        foreach ((array) $value as $v) {
            $v = trim((string) $v);
            if ($v !== '') $lines[] = sprintf($tpl, $v);
        }
        return $lines;
    }
    $v = trim((string) $value);
    return $v === '' ? [] : [sprintf($tpl, $v)];
}

// Validate desired states against the schema's conflicts/requires and value
// rules. $desired: [key => ['state' => ..., 'value' => string|array]].
// Returns a list of human-readable error strings (empty = valid).
function dropins_validate(array $dirs, array $desired): array {
    $errors = [];
    $active = fn($k) => ($desired[$k]['state'] ?? 'default') !== 'default';

    foreach ($dirs as $key => $entry) {
        if (!$active($key)) continue;

        // conflicts — report each pair once
        foreach (($entry['conflicts'] ?? []) as $c) {
            if ($active($c) && $key < $c) {
                $errors[] = sprintf('%s conflicts with %s', $key, $c);
            }
        }
        // requires
        foreach (($entry['requires'] ?? []) as $r) {
            if (!$active($r)) {
                $errors[] = sprintf('%s requires %s', $key, $r);
            }
        }
        // value rules for "on"
        if (($desired[$key]['state'] ?? '') === 'on') {
            $type = $entry['type'] ?? 'flag';
            if (in_array($type, ['int', 'value', 'path', 'list'], true)) {
                $v = $desired[$key]['value'] ?? null;
                $has = is_array($v)
                    ? count(array_filter(array_map('trim', $v), fn($x) => $x !== '')) > 0
                    : trim((string) $v) !== '';
                if (!$has) $errors[] = sprintf('%s is enabled but has no value', $key);
            }
            // numeric + range check (int fields and optional-value flags)
            if ($type === 'int' || !empty($entry['optval'])) {
                $v = trim((string) ($desired[$key]['value'] ?? ''));
                if ($v !== '') {
                    if (!preg_match('/^\d+$/', $v)) {
                        $errors[] = sprintf('%s must be a whole number', $key);
                    } elseif (isset($entry['min']) && (int) $v < $entry['min']) {
                        $errors[] = sprintf('%s must be %s or more', $key, $entry['min']);
                    } elseif (isset($entry['max']) && (int) $v > $entry['max']) {
                        $errors[] = sprintf('%s must be %s or less', $key, $entry['max']);
                    }
                }
            }
        }
    }
    return array_values(array_unique($errors));
}

// Reconcile every editable directive's <key>.conf with the desired state:
// write the line(s), or remove the file. Returns ['ok' => bool, 'errors' => []].
function dropins_apply(string $dir, array $dirs, array $desired, int $editPhase = 1): array {
    $errors = [];
    foreach ($dirs as $key => $entry) {
        if (!dropin_editable($entry, $editPhase) || !empty($entry['custom'])) continue;
        $d     = $desired[$key] ?? ['state' => 'default', 'value' => null];
        $lines = dropin_lines($entry, $d['state'] ?? 'default', $d['value'] ?? null);
        $name  = $entry['file'] ?? "$key.conf";   // server keeps its established upstream.conf
        $file  = $dir . '/' . $name;

        if (empty($lines)) {
            if (is_file($file) && !@unlink($file)) {
                $errors[] = sprintf('could not remove %s', $name);
            }
        } else {
            if (@file_put_contents($file, implode("\n", $lines) . "\n") === false) {
                $errors[] = sprintf('could not write %s', $name);
            }
        }
    }
    return ['ok' => empty($errors), 'errors' => $errors];
}
