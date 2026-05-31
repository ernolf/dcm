<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

// Derive paths from /etc/default/dnsmasq (single source of truth)
$_dconf = '/etc/dnsmasq.conf.mine';
$_ddir  = '/etc/dnsmasq.d';
foreach (file('/etc/default/dnsmasq', FILE_IGNORE_NEW_LINES) ?: [] as $_l) {
    if (str_starts_with(ltrim($_l), '#')) continue;
    if (preg_match('/DNSMASQ_OPTS.*--conf-file=([^ "\']+)/', $_l, $_m)) {
        $_dconf = $_m[1];
    } elseif (preg_match('/^\s*CONFIG_DIR=([^,\s"\']+)/', $_l, $_m)) {
        // strip the trailing ,.dpkg-* exclusion suffixes
        $_ddir = $_m[1];
    }
}

define('DNSMASQ_CONF',  $_dconf);
define('DNSMASQ_D',     $_ddir);
define('HOSTS_DIR',     DNSMASQ_D . '/hosts');
define('UPSTREAM_CONF', DNSMASQ_D . '/upstream.conf');
define('NODES_FILE',    '/etc/dcm/nodes');
define('CLI',           '/usr/local/sbin/dcm-cli');
define('LOG_FILE',      '/var/log/dnsmasq/dnsmasq.log');

unset($_dconf, $_ddir, $_l, $_m);
