<!--
SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
SPDX-License-Identifier: GPL-3.0-or-later
-->

<!-- Project header -->
<p>
  <img src="www/assets/logo.svg" alt="dcm — dnsmasq cluster manager" width="290" align="left">
  <h3>Web frontend + CLI for a two-node dnsmasq cluster</h3>
</p>
<p>
  <a href="https://api.reuse.software/info/github.com/ernolf/dcm"><img alt="REUSE status" src="https://api.reuse.software/badge/github.com/ernolf/dcm"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-GPL--3.0--or--later-blue"></a>
  <img alt="PHP" src="https://img.shields.io/badge/PHP-777BB4">
  <img alt="Bash" src="https://img.shields.io/badge/shell-bash-4EAA25">
</p>
<hr>

# dcm — dnsmasq cluster manager

Run two or more dnsmasq nodes as one resolver and manage them from a single place. Every node holds the identical configuration except for its own listen address; you edit hosts, VM records and dnsmasq options in the browser, see exactly what differs between the nodes, then sync and restart them from there. The web frontend is plain PHP without a database and drives a single privileged CLI backend (`dcm-cli`) over `sudo`.

> **📖 The documentation lives in the [wiki](https://github.com/ernolf/dcm/wiki).** This page is the short version.

> **Early development.** dcm is under active, heavy development and far from feature-complete. Several planned features — DHCP and PXE/TFTP boot, ad-blocking lists, multi-network / split-horizon DNS, and real authentication — are **not implemented yet**, and existing behaviour may still change.

## Features

* **One configuration for the whole cluster** — `dcm-cli` syncs the drop-ins, the host files, the node list and its own binary over `rsync` + SSH; only each node's `listen.conf` stays local, generated from the node entries in `hosts/local`
* **Per-directive Configuration page** — every dnsmasq option is its own drop-in (present = active, absent = dnsmasq's default), with the help text parsed from the dnsmasq manual page installed next to the running binary
* **Never writes a config the cluster cannot start with** — each node reports its version, its compile time options and the options its binary accepts; the UI offers only what the weakest node understands and shows the rest read-only with the reason
* **Hosts, VMs and isolated hosts** — add, edit, enable/disable and delete records; VM records relocate to another subnet in one click
* **Live log and analytics** — both nodes' query logs streamed side by side, colour-coded, with per-server analytics over five time ranges
* **Live notifications** — a bell polling `dcm-cli health`: sync pending, restart pending, version mismatch, feature mismatch, unknown directive. Derived from live state, so there is nothing to go stale
* **No duplicated settings** — the config dir, the hosts dir and the log file are read from dnsmasq's own config; the only dcm-owned paths are the binary, the node list and the build cache

## Requirements

* Two or more Debian/Ubuntu nodes running **dnsmasq**, all of them the **same version**: one identical configuration goes to every node, and dnsmasq refuses to start on an option it does not know
* On the node that serves the UI: a web server (Apache2 in the wiki's example) and **PHP-FPM 8.x**
* `rsync` and passwordless **root** SSH from the UI node to every other node
* `addn-hosts` set to a **directory**, not a single file — dcm keeps its host files there

## Quick start

1. On the **UI** node, get the source: `git clone https://github.com/ernolf/dcm.git && cd dcm`, or unpack an archive of the same tree. The paths in step 3 are relative to that directory; there is no build step.
2. On **every** node: free port 53 (`DNSStubListener=no` in `/etc/systemd/resolved.conf`) and point `/etc/resolv.conf` at `127.0.0.1`.
3. On the **UI** node: point dnsmasq at the drop-in directory only, install `sbin/dcm-cli` to `/usr/local/sbin/`, list every node in `/etc/dcm/nodes`, allow `www-data` to call the binary through `sudo`, and deploy `www/` to the document root.
4. `sudo dcm-cli sync` then `sudo dcm-cli restart all` — the launch config, the drop-ins, the host files, the node list and the binary land on every other node.

Full walkthrough, including the PHP-FPM override and the file ownership: **[Installation](https://github.com/ernolf/dcm/wiki/Installation)**.

> **⚠️ Authentication is intentionally left out** — `inc/auth.php` is a no-op stub. Keep the UI node off the internet and behind HTTP Basic auth, a VPN, or a trusted network.

## Documentation

| | |
|---|---|
| [Installation](https://github.com/ernolf/dcm/wiki/Installation) | the whole setup from freeing port 53 to the first sync, plus every path and its owner |
| [The web interface](https://github.com/ernolf/dcm/wiki/The-web-interface) | what the nine pages do, and what the bell is telling you |
| [Hosts files](https://github.com/ernolf/dcm/wiki/Hosts-files) | `local`, `vms` and `isolated`, and the one-click subnet relocation |
| [Directive catalog](https://github.com/ernolf/dcm/wiki/Directive-catalog) | every dnsmasq option the Configuration page exposes, with defaults and conflicts |
| [CLI reference](https://github.com/ernolf/dcm/wiki/CLI-reference) | the `dcm-cli` commands and what each one touches |
| [Architecture](https://github.com/ernolf/dcm/wiki/Architecture) | the design: sync flow, cluster build detection, SSE live log, analytics pipeline |
| [Encrypted upstream DNS](https://github.com/ernolf/dcm/wiki/Encrypted-upstream-DNS) | DoH/DoT/DoQ and DNSSEC for a dcm cluster — a planning note |
| [Roadmap](https://github.com/ernolf/dcm/wiki/Roadmap) | what is planned, and what Phase 1 deliberately leaves out |

## License

[GPL-3.0-or-later](LICENSE) © 2026 [ernolf] Raphael Gradenwitz
