<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

// Derive all dnsmasq paths from dnsmasq's own config (single source of truth).
// /etc/default/dnsmasq -> conf-file path (DNSMASQ_OPTS) and config dir (CONFIG_DIR)
$_dconf = '/etc/dnsmasq.conf.mine';
$_ddir  = '/etc/dnsmasq.d';
foreach (file('/etc/default/dnsmasq', FILE_IGNORE_NEW_LINES) ?: [] as $_l) {
    if (str_starts_with(ltrim($_l), '#')) continue;
    if (preg_match('/DNSMASQ_OPTS.*--conf-file=([^ "\']+)/', $_l, $_m)) {
        $_dconf = $_m[1];
    } elseif (preg_match('/^\s*CONFIG_DIR=([^,\s"\']+)/', $_l, $_m)) {
        $_ddir = $_m[1];  // strip the trailing ,.dpkg-* exclusion suffixes
    }
}

// conf-file -> hosts dir (addn-hosts) and log file (log-facility)
$_hosts = $_ddir . '/hosts';
$_log   = '/var/log/dnsmasq/dnsmasq.log';
foreach (file($_dconf, FILE_IGNORE_NEW_LINES) ?: [] as $_l) {
    if (str_starts_with(ltrim($_l), '#')) continue;
    if (preg_match('/^\s*addn-hosts\s*=\s*(\S+)/', $_l, $_m)) {
        $_hosts = $_m[1];
    } elseif (preg_match('/^\s*log-facility\s*=\s*(\S+)/', $_l, $_m)) {
        $_log = $_m[1];
    }
}

define('DNSMASQ_CONF',  $_dconf);
define('DNSMASQ_D',     $_ddir);
define('HOSTS_DIR',     $_hosts);
define('UPSTREAM_CONF', DNSMASQ_D . '/upstream.conf');
define('NODES_FILE',    '/etc/dcm/nodes');
define('CLI',           '/usr/local/sbin/dcm-cli');
define('LOG_FILE',      $_log);

unset($_dconf, $_ddir, $_hosts, $_log, $_l, $_m);
