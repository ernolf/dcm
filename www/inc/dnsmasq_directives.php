<?php
// SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
// SPDX-License-Identifier: GPL-3.0-or-later

// Directive schema for the Configuration page. Derived from
// docs/dnsmasq-directives.md and verified against dnsmasq 2.90 on the cluster.
// The UI renders its controls from this schema; the drop-in writer serialises
// the chosen states back into /etc/dnsmasq.d/. This file is data only.
//
// Entry shape:
//   group         group id (see dnsmasq_groups())
//   label         human-readable control label
//   type          'flag' | 'int' | 'path' | 'value' | 'list'
//   help          one-line explanation shown under the control
//   default       dnsmasq's behaviour when unset (the greyed-out state):
//                   flag -> 'off' | 'on'   (state of the feature)
//                   int  -> the numeric default
//                   path/value/list -> a short description string
//   on            line written for the ON / set state; %s is the value
//                 placeholder. null = nothing to write (ON is the default).
//   off           line written for an explicit OFF; null = OFF means
//                 "remove the line".
//   conflicts     directive ids that must not be active at the same time
//   requires      directive ids that must be active for this one to apply
//   recommended   true to surface as a recommended default
//   phase         1 = Configuration page now, 2 = Upstream page later
//   managed       true = a dcm-owned drop-in (e.g. addn-hosts.conf,
//                 log-facility.conf); shown but not freely editable
//   locked        true = shown read-only because it is owned elsewhere; the
//                 'reason' field explains where
//   cascade_off   UI pages to grey out when this flag is off
//
// The negation directives (no-resolv, no-hosts, no-negcache, no-poll) toggle
// behaviours that dnsmasq enables by default. The directive itself is the OFF
// switch and is absent by default, so it is modelled here as a plain
// default-absent flag whose 'on' writes the negation; the help text names the
// default-on behaviour it disables. The only genuine three-state settings (a
// non-trivial default plus an explicit off form) are cache-size and
// dnssec-check-unsigned.
//
// The drop-in file layout is one file per directive (<key>.conf); the writer
// in inc/dropins.php owns that convention, not this schema.

function dnsmasq_groups(): array {
    return [
        'logging'  => ['label' => 'Logging & diagnostics',             'phase' => 1],
        'name'     => ['label' => 'Name & domain handling',            'phase' => 1],
        'cache'    => ['label' => 'Caching & TTL',                     'phase' => 1],
        'rebind'   => ['label' => 'Rebind protection & loop detection', 'phase' => 1],
        'dnssec'   => ['label' => 'DNSSEC',                            'phase' => 1],
        'upstream' => ['label' => 'Upstream & forwarding',             'phase' => 2],
        'locked'   => ['label' => 'Locked / structural',               'phase' => 1],
    ];
}

function dnsmasq_directives(): array {
    return [

        // ── A — Logging & diagnostics ──────────────────────────────────────
        'log-queries' => [
            'group'       => 'logging',
            'label'       => 'Query logging',
            'type'        => 'flag',
            'help'        => 'Log every DNS query. Required for the Live Log and Analytics pages.',
            'default'     => 'off',
            'on'          => 'log-queries',
            'off'         => null,
            'cascade_off' => ['live.php', 'analytics.php'],
        ],
        'log-facility' => [
            'group'   => 'logging',
            'label'   => 'Log file',
            'type'    => 'path',
            'help'    => 'Where dnsmasq writes its log. Managed by dcm (single source of truth).',
            'default' => 'syslog (DAEMON facility)',
            'on'      => 'log-facility=%s',
            'off'     => null,
            'managed' => true,
        ],
        'log-async' => [
            'group'      => 'logging',
            'label'      => 'Asynchronous logging',
            'type'       => 'flag',
            'help'       => 'Queue log lines instead of blocking on each write. Optional queue length 0-100; default 5 when enabled.',
            'default'    => 'off',
            'on'         => 'log-async',
            'off'        => null,
            'optval'     => true,   // the flag may carry an optional =N value
            'valdefault' => 5,
            'min'        => 0,
            'max'        => 100,
        ],

        // ── B — Name & domain handling ─────────────────────────────────────
        'domain-needed' => [
            'group'       => 'name',
            'label'       => 'Require a domain in queries',
            'type'        => 'flag',
            'help'        => 'Never forward plain names without a dot or domain part upstream.',
            'default'     => 'off',
            'on'          => 'domain-needed',
            'off'         => null,
            'recommended' => 'on',
        ],
        'bogus-priv' => [
            'group'       => 'name',
            'label'       => 'Block reverse lookups for private ranges',
            'type'        => 'flag',
            'help'        => 'Never forward reverse lookups for private IP ranges that have no answer.',
            'default'     => 'off',
            'on'          => 'bogus-priv',
            'off'         => null,
            'recommended' => 'on',
        ],
        'expand-hosts' => [
            'group'   => 'name',
            'label'   => 'Expand simple hostnames with the domain',
            'type'    => 'flag',
            'help'    => 'Add the configured domain to simple names in hosts files. Needs a "domain" below.',
            'default' => 'off',
            'on'      => 'expand-hosts',
            'off'     => null,
            'requires'=> ['domain'],
        ],
        'domain' => [
            'group'   => 'name',
            'label'   => 'Local domain',
            'type'    => 'value',
            'help'    => 'The domain assigned to local hosts, e.g. "lan" or "example.net[,<subnet>]".',
            'default' => 'none',
            'on'      => 'domain=%s',
            'off'     => null,
        ],
        'local' => [
            'group'   => 'name',
            'label'   => 'Authoritative local domains',
            'type'    => 'list',
            'help'    => 'Domains answered only locally, never forwarded, e.g. /lan/.',
            'default' => 'none',
            'on'      => 'local=%s',
            'off'     => null,
        ],
        'filterwin2k' => [
            'group'   => 'name',
            'label'   => 'Filter Windows SRV/SOA noise',
            'type'    => 'flag',
            'help'    => 'Drop the periodic SRV/SOA lookups some Windows clients send upstream.',
            'default' => 'off',
            'on'      => 'filterwin2k',
            'off'     => null,
        ],
        'filter-AAAA' => [
            'group'   => 'name',
            'label'   => 'Suppress IPv6 (AAAA) answers',
            'type'    => 'flag',
            'help'    => 'Remove AAAA records from answers (forces IPv4-only resolution).',
            'default' => 'off',
            'on'      => 'filter-AAAA',
            'off'     => null,
        ],
        'filter-A' => [
            'group'   => 'name',
            'label'   => 'Suppress IPv4 (A) answers',
            'type'    => 'flag',
            'help'    => 'Remove A records from answers (forces IPv6-only resolution).',
            'default' => 'off',
            'on'      => 'filter-A',
            'off'     => null,
        ],
        'no-hosts' => [
            'group'   => 'name',
            'label'   => 'Ignore /etc/hosts',
            'type'    => 'flag',
            'help'    => 'dnsmasq reads /etc/hosts by default; enable this to stop it.',
            'default' => 'on',
            'on'      => 'no-hosts',
            'off'     => null,
        ],
        'localise-queries' => [
            'group'   => 'name',
            'label'   => 'Localise multi-homed answers',
            'type'    => 'flag',
            'help'    => 'Return the hosts-file address that matches the subnet the query arrived on.',
            'default' => 'off',
            'on'      => 'localise-queries',
            'off'     => null,
        ],

        // ── C — Caching & TTL ──────────────────────────────────────────────
        'cache-size' => [
            'group'   => 'cache',
            'label'   => 'Cache size',
            'type'    => 'int',
            'help'    => 'Number of names kept in the cache. 0 disables caching.',
            'default' => 150,
            'on'      => 'cache-size=%s',
            'off'     => 'cache-size=0',
            'min'     => 0,
        ],
        'no-negcache' => [
            'group'   => 'cache',
            'label'   => 'Disable negative caching',
            'type'    => 'flag',
            'help'    => 'Negative answers (NXDOMAIN) are cached by default; enable this to stop it.',
            'default' => 'on',
            'on'      => 'no-negcache',
            'off'     => null,
        ],
        'neg-ttl' => [
            'group'   => 'cache',
            'label'   => 'Negative answer TTL',
            'type'    => 'int',
            'help'    => 'TTL (seconds) for cached negative answers. 0 uses the SOA value.',
            'default' => 0,
            'on'      => 'neg-ttl=%s',
            'off'     => null,
            'min'     => 0,
        ],
        'local-ttl' => [
            'group'   => 'cache',
            'label'   => 'Local answer TTL',
            'type'    => 'int',
            'help'    => 'TTL (seconds) given to answers from hosts files and DHCP leases.',
            'default' => 0,
            'on'      => 'local-ttl=%s',
            'off'     => null,
            'min'     => 0,
        ],
        'min-cache-ttl' => [
            'group'   => 'cache',
            'label'   => 'Minimum cache TTL',
            'type'    => 'int',
            'help'    => 'Extend short upstream TTLs to at least this many seconds (dnsmasq caps at 3600).',
            'default' => 0,
            'on'      => 'min-cache-ttl=%s',
            'off'     => null,
            'min'     => 0,
            'max'     => 3600,
        ],
        'max-cache-ttl' => [
            'group'   => 'cache',
            'label'   => 'Maximum cache TTL',
            'type'    => 'int',
            'help'    => 'Clamp long upstream TTLs to at most this many seconds.',
            'default' => 'record TTL (no cap)',
            'on'      => 'max-cache-ttl=%s',
            'off'     => null,
            'min'     => 0,
        ],

        // ── D — Rebind protection & loop detection ─────────────────────────
        'stop-dns-rebind' => [
            'group'   => 'rebind',
            'label'   => 'Block DNS rebind responses',
            'type'    => 'flag',
            'help'    => 'Reject upstream answers that point into private IP ranges (anti-rebind).',
            'default' => 'off',
            'on'      => 'stop-dns-rebind',
            'off'     => null,
        ],
        'rebind-localhost-ok' => [
            'group'   => 'rebind',
            'label'   => 'Allow 127.0.0.0/8 in rebind protection',
            'type'    => 'flag',
            'help'    => 'Exempt loopback answers from rebind blocking (useful for some local services).',
            'default' => 'off',
            'on'      => 'rebind-localhost-ok',
            'off'     => null,
            'requires'=> ['stop-dns-rebind'],
        ],
        'rebind-domain-ok' => [
            'group'   => 'rebind',
            'label'   => 'Rebind exceptions',
            'type'    => 'list',
            'help'    => 'Domains exempt from rebind blocking, e.g. /example.com/.',
            'default' => 'none',
            'on'      => 'rebind-domain-ok=%s',
            'off'     => null,
            'requires'=> ['stop-dns-rebind'],
        ],
        'dns-loop-detect' => [
            'group'   => 'rebind',
            'label'   => 'Detect forwarding loops',
            'type'    => 'flag',
            'help'    => 'Automatically detect and break upstream forwarding loops.',
            'default' => 'off',
            'on'      => 'dns-loop-detect',
            'off'     => null,
        ],

        // ── E — DNSSEC ─────────────────────────────────────────────────────
        'dnssec' => [
            'group'   => 'dnssec',
            'label'   => 'Validate DNSSEC',
            'type'    => 'flag',
            'help'    => 'Validate answers against DNSSEC. Needs trust anchors and a DO-bit-passing upstream.',
            'default' => 'off',
            'on'      => 'dnssec',
            'off'     => null,
        ],
        'dnssec-check-unsigned' => [
            'group'   => 'dnssec',
            'label'   => 'Check that unsigned answers are legitimate',
            'type'    => 'flag',
            'help'    => 'On by default; disabling speeds things up but weakens DNSSEC guarantees.',
            'default' => 'on',
            'on'      => null,
            'off'     => 'dnssec-check-unsigned=no',
            'requires'=> ['dnssec'],
        ],

        // ── F — Upstream & forwarding (Phase 2, Upstream page) ─────────────
        'no-resolv' => [
            'group'   => 'upstream',
            'label'   => 'Ignore resolv-file',
            'type'    => 'flag',
            'help'    => 'dnsmasq reads resolv-file for upstreams by default; enable this to use only explicit servers.',
            'default' => 'on',
            'on'      => 'no-resolv',
            'off'     => null,
            'phase'   => 2,
        ],
        'resolv-file' => [
            'group'   => 'upstream',
            'label'   => 'resolv-file path',
            'type'    => 'path',
            'help'    => 'File dnsmasq reads upstream servers from. Ignored when "Ignore resolv-file" is on.',
            'default' => '/etc/resolv.conf',
            'on'      => 'resolv-file=%s',
            'off'     => null,
            'conflicts'=> ['no-resolv'],
            'phase'   => 2,
        ],
        'no-poll' => [
            'group'   => 'upstream',
            'label'   => 'Do not poll resolv-file',
            'type'    => 'flag',
            'help'    => 'resolv-file is polled for changes by default; enable this to read it once at start.',
            'default' => 'on',
            'on'      => 'no-poll',
            'off'     => null,
            'phase'   => 2,
        ],
        'server' => [
            'group'   => 'upstream',
            'label'   => 'Upstream servers',
            'type'    => 'list',
            'help'    => 'Explicit upstream or domain-routed servers, e.g. 1.1.1.1 or /example.net/192.168.1.1.',
            'default' => 'from resolv-file',
            'on'      => 'server=%s',
            'off'     => null,
            'phase'   => 2,
            'file'    => 'upstream.conf',   // keep the established, already-synced file
            'custom'  => true,              // edited via the dedicated server table, not the grid
        ],
        'strict-order' => [
            'group'    => 'upstream',
            'label'    => 'Strict server order',
            'type'     => 'flag',
            'help'     => 'Try upstream servers strictly in listed order instead of by responsiveness.',
            'default'  => 'off',
            'on'       => 'strict-order',
            'off'      => null,
            'conflicts'=> ['all-servers'],
            'phase'    => 2,
        ],
        'all-servers' => [
            'group'    => 'upstream',
            'label'    => 'Query all servers in parallel',
            'type'     => 'flag',
            'help'     => 'Send each query to every upstream and take the first answer.',
            'default'  => 'off',
            'on'       => 'all-servers',
            'off'      => null,
            'conflicts'=> ['strict-order'],
            'phase'    => 2,
        ],
        'dns-forward-max' => [
            'group'   => 'upstream',
            'label'   => 'Max concurrent forwarded queries',
            'type'    => 'int',
            'help'    => 'Upper bound on queries forwarded upstream at once.',
            'default' => 150,
            'on'      => 'dns-forward-max=%s',
            'off'     => null,
            'min'     => 1,
            'phase'   => 2,
        ],
        'bogus-nxdomain' => [
            'group'   => 'upstream',
            'label'   => 'Treat address as bogus NXDOMAIN',
            'type'    => 'list',
            'help'    => 'Map an ISP hijack address to NXDOMAIN, e.g. 64.94.110.11.',
            'default' => 'none',
            'on'      => 'bogus-nxdomain=%s',
            'off'     => null,
            'phase'   => 2,
        ],
        'add-mac' => [
            'group'   => 'upstream',
            'label'   => 'Attach client MAC to upstream queries',
            'type'    => 'flag',
            'help'    => 'Privacy-reducing: forwards the client MAC upstream. Leave off unless required.',
            'default' => 'off',
            'on'      => 'add-mac',
            'off'     => null,
            'phase'   => 2,
        ],
        'add-subnet' => [
            'group'   => 'upstream',
            'label'   => 'Attach client subnet to upstream queries',
            'type'    => 'flag',
            'help'    => 'Privacy-reducing: forwards EDNS client-subnet upstream. Leave off unless required.',
            'default' => 'off',
            'on'      => 'add-subnet',
            'off'     => null,
            'phase'   => 2,
        ],

        // ── G — Locked / structural (read-only in the UI) ──────────────────
        'addn-hosts' => [
            'group'   => 'locked',
            'label'   => 'Additional hosts directory',
            'type'    => 'path',
            'help'    => 'The hosts directory loaded by dnsmasq.',
            'default' => '/etc/dnsmasq.d/hosts',
            'on'      => 'addn-hosts=%s',
            'off'     => null,
            'managed' => true,
            'locked'  => true,
            'reason'  => 'dcm-managed drop-in (addn-hosts.conf)',
        ],
        // interface/listen-address and port are not edited here — listen.conf is
        // generated per node, and both are shown on the Dashboard status box.
    ];
}
