<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

class HostsFile {
    private array  $lines;
    private string $path;

    public function __construct(string $path) {
        $this->path  = $path;
        $this->lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    }

    /** Returns all IP-based entries with their line index. */
    public function entries(): array {
        $result = [];
        foreach ($this->lines as $idx => $line) {
            $trimmed = trim($line);
            $enabled = true;
            $data    = $trimmed;

            if (str_starts_with($trimmed, '#')) {
                $enabled = false;
                $data    = ltrim(substr($trimmed, 1));
            }

            $parts = preg_split('/\s+/', trim($data), -1, PREG_SPLIT_NO_EMPTY);
            if (count($parts) < 2) continue;
            if (!filter_var($parts[0], FILTER_VALIDATE_IP)) continue;

            $result[] = [
                'idx'       => $idx,
                'enabled'   => $enabled,
                'ip'        => $parts[0],
                'hostnames' => array_slice($parts, 1),
            ];
        }
        return $result;
    }

    public function toggle(int $idx): void {
        $t = trim($this->lines[$idx]);
        $this->lines[$idx] = str_starts_with($t, '#')
            ? ltrim(substr($t, 1))
            : '# ' . $t;
    }

    public function delete(int $idx): void {
        unset($this->lines[$idx]);
    }

    public function add(string $ip, array $hostnames): void {
        $this->lines[] = $ip . "\t" . implode(' ', $hostnames);
    }

    public function update(int $idx, string $ip, array $hostnames): void {
        $was_disabled = str_starts_with(trim($this->lines[$idx]), '#');
        $new = $ip . "\t" . implode(' ', $hostnames);
        $this->lines[$idx] = $was_disabled ? '# ' . $new : $new;
    }

    public function save(): bool {
        $content = implode("\n", $this->lines);
        if (!str_ends_with($content, "\n")) $content .= "\n";
        return file_put_contents($this->path, $content) !== false;
    }

    /** Returns the raw file content for textarea editing. */
    public function raw(): string {
        return implode("\n", $this->lines);
    }
}

/** Detect common /24 prefix from a list of IPs (e.g. "192.168.78."). Returns '' if mixed. */
function detect_prefix(array $entries): string {
    $ips = array_column(array_filter($entries, fn($e) => $e['enabled']), 'ip');
    if (empty($ips)) return '';
    $first = explode('.', $ips[0]);
    foreach ($ips as $ip) {
        $p = explode('.', $ip);
        if ($p[0] !== $first[0] || $p[1] !== $first[1] || $p[2] !== $first[2]) return '';
    }
    return $first[0] . '.' . $first[1] . '.' . $first[2] . '.';
}
