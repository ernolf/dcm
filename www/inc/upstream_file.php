<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

// Edits the server= lines in upstream.conf the same way HostsFile edits a hosts
// file: each server entry can be toggled (commented/uncommented), edited, added
// or deleted. Non-server lines (plain comments) are preserved untouched.
class UpstreamFile {
    private array  $lines;
    private string $path;

    public function __construct(string $path) {
        $this->path  = $path;
        $this->lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    /** All server= entries with their line index, enabled flag and spec value. */
    public function entries(): array {
        $result = [];
        foreach ($this->lines as $idx => $line) {
            $t       = trim($line);
            $enabled = true;
            if (str_starts_with($t, '#')) {
                $enabled = false;
                $t       = ltrim(substr($t, 1));
            }
            if (!preg_match('/^server\s*=\s*(.+)$/', $t, $m)) continue;
            $result[] = ['idx' => $idx, 'enabled' => $enabled, 'value' => trim($m[1])];
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

    public function add(string $value): void {
        $this->lines[] = 'server = ' . $value;
    }

    public function update(int $idx, string $value): void {
        $was_disabled = str_starts_with(trim($this->lines[$idx]), '#');
        $new = 'server = ' . $value;
        $this->lines[$idx] = $was_disabled ? '# ' . $new : $new;
    }

    public function save(): bool {
        $content = implode("\n", $this->lines);
        if ($content !== '' && !str_ends_with($content, "\n")) $content .= "\n";
        return file_put_contents($this->path, $content) !== false;
    }
}
