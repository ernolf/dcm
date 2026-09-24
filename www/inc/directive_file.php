<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

// Edits the lines of one repeatable directive in its drop-in the same way
// HostsFile edits a hosts file: each entry can be toggled (commented/
// uncommented), edited, added or deleted. Lines of other directives and plain
// comments are preserved untouched, so a hand-written header survives.
class DirectiveFile {
    private array  $lines;
    private string $path;
    private string $key;

    public function __construct(string $path, string $key) {
        $this->path  = $path;
        $this->key   = $key;
        $this->lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    /** All entries of this directive with their line index, enabled flag and value. */
    public function entries(): array {
        $pattern = '/^' . preg_quote($this->key, '/') . '\s*=\s*(.+)$/';
        $result  = [];
        foreach ($this->lines as $idx => $line) {
            $t       = trim($line);
            $enabled = true;
            if (str_starts_with($t, '#')) {
                $enabled = false;
                $t       = ltrim(substr($t, 1));
            }
            if (!preg_match($pattern, $t, $m)) continue;
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

    /** Appends an entry and returns its line index, so the caller can jump to it. */
    public function add(string $value): int {
        $this->lines[] = dnsmasq_format($this->key . '=' . $value);
        return array_key_last($this->lines);
    }

    public function update(int $idx, string $value): void {
        $was_disabled = str_starts_with(trim($this->lines[$idx]), '#');
        $new = dnsmasq_format($this->key . '=' . $value);
        $this->lines[$idx] = $was_disabled ? '# ' . $new : $new;
    }

    public function save(): bool {
        $content = implode("\n", $this->lines);
        if ($content !== '' && !str_ends_with($content, "\n")) $content .= "\n";
        return write_if_changed($this->path, $content);
    }
}
