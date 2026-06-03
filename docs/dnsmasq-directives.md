<!--
SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
SPDX-License-Identifier: GPL-3.0-or-later
-->

# dnsmasq directive catalog

This is the canonical catalog of dnsmasq directives that the dcm **Configuration** page exposes. It is the source of truth from which the UI schema (`inc/dnsmasq_directives.php`) is derived. Every directive the UI offers must appear here with its type, default and conflict metadata.

> **Scope: Phase 1 — DNS resolver / forwarder.** DHCP, DHCPv6, TFTP/PXE boot and ad-blocking lists are deliberately out of scope here; they get their own catalog sections when those features are built.

## Verified build

Defaults and availability below are verified against the dnsmasq actually deployed on the cluster:

```
Dnsmasq version 2.90
Compile time options: IPv6 GNU-getopt DBus no-UBus i18n IDN2 DHCP DHCPv6
no-Lua TFTP conntrack ipset no-nftset auth cryptohash DNSSEC loop-detect
inotify dumpfile
```

Consequences for the catalog:

- **Available and relevant:** DNSSEC, DHCP/DHCPv6, TFTP, `ipset`, conntrack, authoritative mode, loop detection, inotify, IDN2.
- **Not available:** Lua scripting (`no-Lua`), UBus (`no-UBus`), **nftset** (`no-nftset`) — so `nftset=` is unusable on this build; use `ipset=` if set integration is ever needed.
- `filter-AAAA` / `filter-A` are core options, not gated by a compile flag, so they work.

When the build changes, re-verify with `dnsmasq --version` and update this section.

## The three-state model

Every setting is rendered from its **default**, which decides what the three UI states mean:

1. **Unset (default)** — nothing is written; the control is **greyed out** and shows what dnsmasq does on its own.
2. **Explicitly ON** — the enable directive is written.
3. **Explicitly OFF** — only meaningful where the default behaviour is *on*. Turning it off then requires writing a **separate negation directive** (e.g. `no-resolv`, `no-hosts`, `no-negcache`), not merely removing a line.

For a plain default-off flag, states 1 and 3 collapse: "off" simply means the directive is absent. The **Disable form** column makes this explicit — an empty cell means "off = directive removed"; a named directive means a real three-state setting.

## Column legend

| Column | Meaning |
|---|---|
| **Type** | `flag` (boolean), `int`, `path`, `value` (single string), `list` (repeatable) |
| **Default** | behaviour when the directive is unset (the greyed-out state) |
| **Enable** | what is written for the ON state |
| **Disable** | what is written for an explicit OFF (empty = off means "directive removed") |
| **Conflicts / Requires** | mutual exclusions and dependencies enforced live in the UI |
| **dcm** | dcm-specific handling (managed, cascades, locks) |

---

## A — Logging & diagnostics

| Directive | Type | Default | Enable | Disable | Conflicts / Requires | dcm |
|---|---|---|---|---|---|---|
| `log-queries` | flag | off (no query log) | `log-queries` | removed | — | **Toggle.** OFF greys out `log-facility` and removes **live.php / analytics.php** from the nav |
| `log-facility` | path | syslog (DAEMON) | `log-facility=<path>` | removed → syslog | — | **managed drop-in** (`log-facility.conf`); otherwise locked |
| `log-async` | int/flag | off (synchronous) | `log-async[=N]` (default 5, max 100) | removed | — | optional |

## B — Name / domain handling

| Directive | Type | Default | Enable | Disable | Conflicts / Requires | dcm |
|---|---|---|---|---|---|---|
| `domain-needed` | flag | off | `domain-needed` | removed | — | recommended on |
| `bogus-priv` | flag | off | `bogus-priv` | removed | — | recommended on |
| `expand-hosts` | flag | off | `expand-hosts` | removed | sensible with `domain=` | — |
| `domain` | value | none | `domain=<dom>[,<subnet>]` | removed | — | — |
| `local` | list | none | `local=/dom/` | removed | — | — |
| `filterwin2k` | flag | off | `filterwin2k` | removed | — | — |
| `filter-AAAA` | flag | off | `filter-AAAA` | removed | — | core feature, available |
| `filter-A` | flag | off | `filter-A` | removed | — | available since 2.86 |
| `no-hosts` | flag | **/etc/hosts is read (on)** | removed | `no-hosts` | — | true three-state |
| `localise-queries` | flag | off | `localise-queries` | removed | — | — |

## C — Caching & TTL

| Directive | Type | Default | Enable | Disable | Conflicts / Requires | dcm |
|---|---|---|---|---|---|---|
| `cache-size` | int | 150 | `cache-size=N` | `cache-size=0` | — | three-state (150 / N / 0) |
| `no-negcache` | flag | **negative caching on** | removed | `no-negcache` | — | true three-state |
| `neg-ttl` | int | 0 (use SOA) | `neg-ttl=N` | removed | — | — |
| `local-ttl` | int | 0 | `local-ttl=N` | removed | — | TTL for local/hosts answers |
| `min-cache-ttl` | int | 0 | `min-cache-ttl=N` | removed | dnsmasq caps at 3600 | — |
| `max-cache-ttl` | int | record TTL (no artificial cap) | `max-cache-ttl=N` | removed | — | — |

## D — Rebind protection & loop detection

| Directive | Type | Default | Enable | Disable | Conflicts / Requires | dcm |
|---|---|---|---|---|---|---|
| `stop-dns-rebind` | flag | off | `stop-dns-rebind` | removed | — | — |
| `rebind-localhost-ok` | flag | off | `rebind-localhost-ok` | removed | sensible with `stop-dns-rebind` | — |
| `rebind-domain-ok` | list | none | `rebind-domain-ok=/dom/` | removed | requires `stop-dns-rebind` | — |
| `dns-loop-detect` | flag | off | `dns-loop-detect` | removed | — | build supports it |

## E — DNSSEC

| Directive | Type | Default | Enable | Disable | Conflicts / Requires | dcm |
|---|---|---|---|---|---|---|
| `dnssec` | flag | off | `dnssec` | removed | **requires** trust anchors; upstream must pass the DO bit | see `encrypted-upstream-dns.md` |
| `dnssec-check-unsigned` | flag | **on (2.90)** | removed | `dnssec-check-unsigned=no` | only with `dnssec` | true three-state |

## F — Upstream / forwarding (Phase 2 — Upstream page)

These belong on the Upstream page, not the Configuration page. Listed here for completeness because they are also drop-ins.

| Directive | Type | Default | Enable | Disable | Conflicts / Requires | dcm |
|---|---|---|---|---|---|---|
| `no-resolv` | flag | **resolv-file is read (on)** | `no-resolv` | removed | pairs with `server=` | core of the systemd-resolved decoupling; true three-state |
| `resolv-file` | path | `/etc/resolv.conf` | `resolv-file=<path>` | via `no-resolv` | ignored when `no-resolv` | legacy drop-in |
| `no-poll` | flag | **polls resolv-file (on)** | `no-poll` | removed | — | — |
| `server` | list | from resolv-file | `server=<spec>` | removed | — | `upstream.conf` |
| `strict-order` | flag | off | `strict-order` | removed | **⇔ `all-servers`** | mutually exclusive |
| `all-servers` | flag | off | `all-servers` | removed | **⇔ `strict-order`** | mutually exclusive |
| `dns-forward-max` | int | 150 | `dns-forward-max=N` | removed | — | — |
| `bogus-nxdomain` | list | none | `bogus-nxdomain=<ip>` | removed | — | — |
| `add-subnet` / `add-mac` / `add-cpe-id` | flag/value | off | e.g. `add-mac` | removed | — | **leave off** — these attach client info to upstream queries (privacy) |

## G — Locked / structural (not freely editable in the UI)

| Directive | Reason |
|---|---|
| `addn-hosts` | dcm-managed drop-in (`addn-hosts.conf`) |
| `conf-dir` / `conf-file` | driven by `/etc/default/dnsmasq` (`--conf-file=/dev/null`, `CONFIG_DIR`), never edited in the UI |
| `interface` / `listen-address` / `bind-interfaces` / `bind-dynamic` | **`listen.conf`, generated per node** — editing here would collide; shown locked with a note |
| `port` | default 53; changing it breaks dcm's assumptions and the bind logic — at most shown read-only |

---

## Deferred (future catalog sections)

The build supports these, but the corresponding dcm features are not implemented yet:

- **DHCP / DHCPv6** — `dhcp-range`, `dhcp-host`, `dhcp-option`, … (build: DHCP, DHCPv6).
- **TFTP / PXE boot** — `enable-tftp`, `tftp-root`, `dhcp-boot`, … (build: TFTP).
- **Ad-block lists** — separate from the `block` hosts file; still planned.
- **Authoritative zones** — `auth-zone`, `auth-server`, … (build: auth).

`nftset=` is **not** available on this build (`no-nftset`); `ipset=` is.
