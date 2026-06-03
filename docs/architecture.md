<!--
SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
SPDX-License-Identifier: GPL-3.0-or-later
-->

# dnsmasq cluster manager: Architecture & Setup Reference

This document describes the complete architecture of the `dcm` - dnsmasq cluster management system as deployed in a multi-site home network. It serves as a reference for future development.

---

## 1. Network Overview

Four Fritzbox networks are interconnected via full-mesh VPN tunnels. All four share the same two dnsmasq servers as their primary DNS. Clients get their DNS server address via DHCP from their local Fritzbox, which forwards queries to the dnsmasq cluster.

| Site | Router | Network |
|---|---|---|
| Frankenthal | Fritzbox 7580 | 192.168.188.0/23 |
| Berlin 1 | Fritzbox 7690 | 192.168.78.0/24 |
| Zürich | Fritzbox 5530 Fiber | 192.168.118.0/24 |
| Berlin 2 | Fritzbox 7430 | 10.1.10.0/24 |

```mermaid
graph TD
    FB0["Fritzbox 7580\n192.168.188.1\nFrankenthal — gateway"]
    FB1["Fritzbox 7690\n192.168.78.1\nBerlin 1"]
    FB2["Fritzbox 5530\n192.168.118.1\nZürich"]
    FB3["Fritzbox 7430\n10.1.10.1\nBerlin 2"]

    DNS1["optiplex-380-1\n192.168.189.1\nDNS Master\nApache2 + PHP-FPM + Docker"]
    DNS2["optiplex-380-0\n192.168.189.101\nDNS Replica\nNextcloud + Apache2"]

    GG["8.8.8.8 / 8.8.4.4\nGoogle DNS (upstream)"]

    FB0 <-->|VPN tunnel| FB1
    FB0 <-->|VPN tunnel| FB2
    FB0 <-->|VPN tunnel| FB3
    FB1 <-->|VPN tunnel| FB2
    FB1 <-->|VPN tunnel| FB3
    FB2 <-->|VPN tunnel| FB3

    FB0 -->|DNS queries| DNS1
    FB0 -->|DNS queries| DNS2
    FB1 -->|DNS queries| DNS1
    FB1 -->|DNS queries| DNS2
    FB2 -->|DNS queries| DNS1
    FB2 -->|DNS queries| DNS2
    FB3 -->|DNS queries| DNS1
    FB3 -->|DNS queries| DNS2

    DNS1 <-->|"dcm-cli sync (rsync+SSH)"| DNS2
    DNS1 -->|"domain-specific upstream"| GG
    DNS2 -->|"domain-specific upstream"| GG
```

The dnsmasq servers live in the home network (`192.168.189.x`), which is a /23 subnet of the home Fritzbox. All four Fritzboxen forward DNS queries to both servers. Clients always query through their local Fritzbox.

---

## 2. dnsmasq Configuration

Both servers run **identical configuration** except for `listen.conf` (server-specific listen address).

### `/etc/default/dnsmasq`
```
ENABLED=1
DNSMASQ_OPTS="--conf-file=/dev/null"
CONFIG_DIR=/etc/dnsmasq.d,.dpkg-dist,.dpkg-old,.dpkg-new
IGNORE_RESOLVCONF=yes
```

### Drop-in configuration

There is no monolithic config file — `--conf-file=/dev/null` makes dnsmasq read **only** the drop-ins in `/etc/dnsmasq.d/`. Each setting is its own `<directive>.conf` (present = active, absent = dnsmasq's default), written by the Configuration page. Key drop-ins:
- `domain-needed.conf` / `bogus-priv.conf` — security
- `resolv-file.conf` — upstream from systemd-resolved (`resolv-file = /run/systemd/resolve/resolv.conf`)
- `addn-hosts.conf` (`addn-hosts = /etc/dnsmasq.d/hosts`) — loads the entire hosts directory
- `log-queries.conf` + `log-facility.conf` — full query logging

### `/etc/dnsmasq.d/` structure

| File | Synced | Purpose |
|---|---|---|
| `<directive>.conf` | Yes | one drop-in per dnsmasq option (Configuration page) |
| `listen.conf` | **No** | `listen-address = 127.0.0.1` + own IP — generated per node |
| `upstream.conf` | Yes | All `server =` directives |
| `hosts/local` | Yes | LAN hosts, swarm nodes, Fritzboxen |
| `hosts/vms` | Yes | VM entries — IPs change per connected network |
| `hosts/block` | Yes | Phone-home domains → 127.0.0.1 (Acronis, Adobe, Piriform) |

### DNS query routing logic

```mermaid
flowchart TD
    Client["Client device"] --> FB["Local Fritzbox\n(whichever site the client is on)"]
    FB --> DNS1["optiplex-380-1\n192.168.189.1"]
    FB --> DNS2["optiplex-380-0\n192.168.189.101"]
    DNS1 --> Cache{"Cached?"}
    DNS2 --> Cache
    Cache -->|Yes| CacheReply["Return from cache"]
    Cache -->|No| Hosts{"In hosts/local\nor hosts/vms?"}
    Hosts -->|Yes| LocalReply["Return configured IP"]
    Hosts -->|No| Block{"In hosts/block?"}
    Block -->|Yes| Blocked["Return 127.0.0.1 — silent drop"]
    Block -->|No| Domain{"Domain-specific upstream?"}
    Domain -->|"Google, YouTube etc."| Google["8.8.8.8 / 8.8.4.4"]
    Domain -->|"Reverse DNS 192.168.188-189.x"| Fritz["Fritzbox 192.168.188.1"]
    Domain -->|"All other"| Default["Default upstream\n(systemd-resolved)"]
```

---

## 3. The `dcm-cli` Tool

Location: `/usr/local/sbin/dcm-cli` — present on **both** nodes, synced automatically.

PHP calls it via `sudo` (sudoers: `www-data ALL=(root) NOPASSWD: /usr/local/sbin/dcm-cli *`).

### Key design decisions

- **Single source of truth for listen IP**: reads node IPs from `hosts/local`, never hardcodes them
- **Single source of truth for paths**: reads `CONFIG_DIR` from `/etc/default/dnsmasq`, then `addn-hosts` / `log-facility` from the merged drop-ins
- **Single source of truth for swarm members**: `/etc/dcm/nodes` (hostname list only)
- **`listen.conf` is never synced**: regenerated from `hosts/local` after every sync
- **`LC_ALL=C` for all `date` calls**: dnsmasq logs in English (`May 31`), system locale is German (`Mai 31`)

### Commands

```
sync                           Sync all config files + dcm-cli binary to all remote nodes
restart local|remote|all       systemctl restart dnsmasq
status  local|remote           systemctl status dnsmasq
logs    [N]                    tail -n N of log file
tail-f  local|remote           tail -F — streaming, used by SSE live log endpoint
stats   local|remote [period]  single-pass awk analytics (all|today|1h|24h|7d)
```

### Sync flow

```mermaid
sequenceDiagram
    participant UI as Web UI (browser)
    participant PHP as action.php (www-data)
    participant CLI as dcm-cli (root)
    participant L as optiplex-380-1
    participant R as optiplex-380-0

    UI->>PHP: POST action=sync
    PHP->>CLI: sudo dcm-cli sync
    CLI->>L: write listen.conf (127.0.0.1 + 192.168.189.1)
    CLI->>R: rsync /etc/default/dnsmasq
    CLI->>R: rsync /etc/dnsmasq.d/ (--exclude listen.conf)
    CLI->>R: rsync /etc/dcm/nodes
    CLI->>R: rsync /usr/local/sbin/dcm-cli
    CLI->>R: write listen.conf (127.0.0.1 + 192.168.189.101)
    CLI-->>PHP: output text
    PHP-->>UI: JSON {ok, output}
```

---

## 4. Web Frontend

URL: `https://dns.global-social.net/` (Apache2 on optiplex-380-1, port 443, wildcard TLS cert)
Also: `https://adblock.global-social.net/` (ad-server sink — served by same Apache, returns blocked page)

### Page map

| Page | File | Description |
|---|---|---|
| Dashboard | `dashboard.php` | Server status (incl. listen addresses + port), Sync/Restart controls with live output |
| Hosts | `hosts.php` | Edit `hosts/local` — add/remove/enable/disable entries |
| Virtual Machines | `vms.php` | Edit `hosts/vms` + one-click subnet relocation |
| Block List | `block.php` | View `hosts/block` grouped by redirect IP |
| Upstream DNS | `upstream.php` | Per-directive editor for the upstream group (no-resolv, resolv-file, server, …); servers go to `upstream.conf` |
| Configuration | `dnsconf.php` | Per-directive drop-in editor — schema-driven switches/selects with dnsmasq manual help |
| Live Log | `live.php` | Real-time SSE log viewer, two panels (local + remote), color-coded, layout toggle, dark mode |
| Analytics | `analytics.php` | Full log analysis — time range + server filter, persisted via cookie |

### Security architecture

```mermaid
graph LR
    Browser -->|HTTPS 443| Apache
    Apache -->|FastCGI| PHPFPM["PHP-FPM (www-data)\nProtectSystem=full +\nReadWritePaths override"]
    PHPFPM -->|"sudo (NOPASSWD)"| CLI["/usr/local/sbin/dcm-cli (root)"]
    CLI -->|"rsync + SSH"| Remote["optiplex-380-0 (root)"]
    CLI -->|"direct write"| EtcDnsmasq["/etc/dnsmasq.d/listen.conf\n/etc/dcm/nodes"]
    PHPFPM -->|"direct write (www-data owns)"| HostsFiles["/etc/dnsmasq.d/*.conf\n/etc/dnsmasq.d/hosts/local\n/etc/dnsmasq.d/hosts/vms"]
```

PHP-FPM runs with `ProtectSystem=full` (systemd sandboxing makes `/etc` read-only). Override in `/etc/systemd/system/php8.4-fpm.service.d/override.conf`:
```ini
[Service]
ReadWritePaths=/etc/dnsmasq.d /etc/dcm
```
This applies to all child processes including `sudo dcm-cli`. The Configuration page additionally needs `/etc/dnsmasq.d` group-writable by `www-data` (`chown root:www-data` + `chmod 2775`) so it can create and remove `<directive>.conf` drop-ins.

---

## 5. Live Log (SSE Architecture)

```mermaid
sequenceDiagram
    participant B as Browser
    participant LS as live_stream.php (www-data)
    participant CLI as dcm-cli (root)
    participant TailL as tail -F (local)
    participant TailR as ssh root@optiplex-380-0 tail -F (remote)

    B->>LS: GET live_stream.php?server=local (EventSource)
    LS->>CLI: sudo dcm-cli tail-f local
    CLI->>TailL: exec tail -F /var/log/dnsmasq/dnsmasq.log

    B->>LS: GET live_stream.php?server=remote (EventSource)
    LS->>CLI: sudo dcm-cli tail-f remote
    CLI->>TailR: exec ssh root@optiplex-380-0 tail -F /var/log/...

    loop Until browser disconnects
        TailL-->>LS: new line
        LS-->>B: data: "May 31 ..."\n\n
        TailR-->>LS: new line
        LS-->>B: data: "May 31 ..."\n\n
    end
```

The browser opens two separate `EventSource` connections (one per server). Lines are color-coded:
- Blue: `query[A]` · Purple: `query[AAAA]` · Teal: `query[HTTPS]` · Light blue: other query types
- Yellow: `forwarded` · Green: `cached`
- Red: `NXDOMAIN` · Orange: `NODATA` · Dark red: `SERVFAIL/REFUSED`
- Grey: `config` / hosts file responses

Layout toggles between side-by-side and stacked. Sidebar collapsible for full-width view.

---

## 6. Analytics Pipeline

```mermaid
flowchart LR
    LogFile["/var/log/dnsmasq/dnsmasq.log\n+ dnsmasq.log.1"] -->|"grep with LC_ALL=C date filter"| Filtered["Lines for selected period"]
    Filtered -->|"single awk pass"| Stats["key=value scalars\n+ TSV arrays"]
    Stats -->|"sudo dcm-cli stats"| PHP["analytics.php parse_stats()"]
    PHP --> UI["HTML: cards + bar chart\n+ top-N tables"]
```

Single awk pass collects: query types (A/AAAA/HTTPS/PTR/…), cache hits, forwarded, locally resolved, blocked, NXDOMAIN/NODATA/SERVFAIL/REFUSED/CNAME, per-hour counts, top 15 upstreams, top 20 domains, top 15 clients.

Filter periods: `1h` · `today` · `24h` · `7d` · `all` — filter selection persisted via 30-day cookie.

---

## 7. VM Subnet Relocation

VMs in `hosts/vms` keep a fixed last octet across all networks. When the laptop connects to a different network, one click in `vms.php` replaces all IP prefixes while preserving last octets.

```
192.168.78.40  freetz        →   10.1.10.40  freetz
192.168.78.50  freetz-linux  →   10.1.10.50  freetz-linux
192.168.78.84  vm-ubuntu     →   10.1.10.84  vm-ubuntu
192.168.78.85  vm-2404       →   10.1.10.85  vm-2404
```

---

## 8. Open TODOs

- **Auth**: `inc/auth.php` is a stub — always passes. Add HTTP Basic Auth or session login when external access is needed.
- **Compressed logs**: `.log.2.gz` and older not yet analyzed — add `zcat` support for longer time ranges.
- **Block list**: read-only in UI. Editing requires `hosts/block` to be owned by `www-data`.
