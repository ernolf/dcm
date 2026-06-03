<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

// Derive all dnsmasq paths from dnsmasq's own config (single source of truth).
// /etc/default/dnsmasq -> conf-file path (DNSMASQ_OPTS) and config dir (CONFIG_DIR)
$_dconf = '/etc/dnsmasq.conf';
$_ddir  = '/etc/dnsmasq.d';
foreach (file('/etc/default/dnsmasq', FILE_IGNORE_NEW_LINES) ?: [] as $_l) {
    if (str_starts_with(ltrim($_l), '#')) continue;
    if (preg_match('/DNSMASQ_OPTS.*--conf-file=([^ "\']+)/', $_l, $_m)) {
        $_dconf = $_m[1];
    } elseif (preg_match('/^\s*CONFIG_DIR=([^,\s"\']+)/', $_l, $_m)) {
        $_ddir = $_m[1];  // strip the trailing ,.dpkg-* exclusion suffixes
    }
}

// dnsmasq merges the conf-file with every snippet in the conf-dir, so dcm reads
// the same set: the conf-file (if still a real file) plus all *.conf snippets,
// with snippets taking precedence. addn-hosts -> hosts dir, log-facility -> log.
$_hosts = $_ddir . '/hosts';
$_log   = '/var/log/dnsmasq/dnsmasq.log';
$_sources = [];
if (is_file($_dconf)) $_sources[] = $_dconf;   // /dev/null is not is_file(), so skipped
$_sources = array_merge($_sources, glob($_ddir . '/*.conf') ?: []);
foreach ($_sources as $_cf) {
    foreach (file($_cf, FILE_IGNORE_NEW_LINES) ?: [] as $_l) {
        if (str_starts_with(ltrim($_l), '#')) continue;
        if (preg_match('/^\s*addn-hosts\s*=\s*(\S+)/', $_l, $_m)) {
            $_hosts = $_m[1];
        } elseif (preg_match('/^\s*log-facility\s*=\s*(\S+)/', $_l, $_m)) {
            $_log = $_m[1];
        }
    }
}

define('DNSMASQ_CONF',  $_dconf);
define('DNSMASQ_D',     $_ddir);
define('HOSTS_DIR',     $_hosts);
define('UPSTREAM_CONF', DNSMASQ_D . '/upstream.conf');
define('NODES_FILE',    '/etc/dcm/nodes');
define('CLI',           '/usr/local/sbin/dcm-cli');
define('LOG_FILE',      $_log);

unset($_dconf, $_ddir, $_hosts, $_log, $_l, $_m, $_sources, $_cf);
