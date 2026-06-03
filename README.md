<!--
SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
SPDX-License-Identifier: GPL-3.0-or-later
-->

<!-- Project header -->
<p>
  <img src="www/assets/logo.svg" alt="dcm — dnsmasq cluster manager" width="290" align="left">
  <h3>Web frontend + CLI for a two-node dnsmasq cluster</p>
</p>
<p>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-GPL--3.0--or--later-blue"></a>
  <img alt="PHP" src="https://img.shields.io/badge/PHP-777BB4">
  <img alt="Bash" src="https://img.shields.io/badge/shell-bash-4EAA25">
</p>
<hr>

# dcm — dnsmasq cluster manager

manages a two-node dnsmasq cluster from a single place:
- add, toggle and delete hosts
- relocate VM records between subnets
- edit dnsmasq options through a per-directive Configuration page
- sync the configuration to all nodes
- restart the service
- watch both servers' query logs live with per-server analytics.

The web frontend drives a single privileged CLI backend (`dcm-cli`) over `sudo`.

> **Early development.** dcm is under active, heavy development and far from feature-complete. Several planned features — DHCP and PXE/TFTP boot, ad-blocking lists, multi-network / split-horizon DNS, and real authentication — are **not implemented yet**, and existing behaviour may still change.

---

## How it works

Two (or more) dnsmasq nodes run an **identical configuration** except for `listen.conf` — each node listens on its own address. `dcm-cli` keeps the nodes in sync over `rsync` + SSH and is the single privileged entry point; the PHP frontend only ever calls `dcm-cli` through `sudo`.

dnsmasq is configured entirely through **drop-in files** in its config directory (`/etc/dnsmasq.d/`); there is no monolithic config file. Each setting on the Configuration page is one `<directive>.conf` drop-in — present means the option is active, absent means dnsmasq's built-in default.

dcm does not duplicate dnsmasq's settings — it **reads every path from dnsmasq's own config** (single source of truth), merging the drop-ins the same way dnsmasq does:

| dcm needs | read from |
|---|---|
| config dir | `CONFIG_DIR` in `/etc/default/dnsmasq` |
| hosts dir | `addn-hosts` in the drop-ins |
| log file | `log-facility` in the drop-ins |

The only dcm-owned paths are the binary (`/usr/local/sbin/dcm-cli`) and the node list (`/etc/dcm/nodes`).

See [`docs/architecture.md`](docs/architecture.md) for diagrams and the full design.

## Requirements

- Two or more Debian/Ubuntu nodes running `dnsmasq`.
- On the node that serves the UI: a web server (Apache2 in the example below) and PHP-FPM (8.x).
- `rsync` and passwordless **root** SSH from the UI node to every other node.
- `addn-hosts` set to a **directory** (not a single file) — dcm keeps its host files there. The setup below creates this drop-in; query logging is switched on later in the UI.

## Setup

Pick **one node to host the web UI** — the one that runs the web server and PHP-FPM. With a single exception you do the **entire setup on that node**; the first `sudo dcm-cli sync` then replicates everything — the `/etc/default/dnsmasq` launch config, the drop-ins, the host files, the node list and the `dcm-cli` binary — to every other node.

The exception is **step 1** (freeing port 53): it changes each node's own OS (`systemd-resolved` and `/etc/resolv.conf`), is not synced, and must be done **on every node**.

In the steps below `node-a` is the UI node and `node-b` a second node (placeholders for the short hostnames, `hostname -s`). Run every step as root.

**Every other node needs only:** `dnsmasq` installed and enabled, step 1 done locally, and passwordless root SSH reachable from the UI node (step 7). Its `/etc/default/dnsmasq`, drop-ins and the `dcm-cli` binary all arrive on the first sync. All remaining steps run **on the UI node**.

### 1. Free up port 53 (systemd-resolved)

On systemd-based distros `systemd-resolved` occupies port 53 on `127.0.0.53`, so dnsmasq cannot bind it. Disable only the **stub listener** — `systemd-resolved` keeps running as the upstream / per-link DNS manager.

`/etc/systemd/resolved.conf`:
```ini
[Resolve]
DNSStubListener=no
```
```sh
sudo systemctl restart systemd-resolved
```

Point the node's own resolver at dnsmasq:
```sh
rm -f /etc/resolv.conf
echo 'nameserver 127.0.0.1' > /etc/resolv.conf
```

> **Always required, regardless of upstream encryption.** A DoH/DoT/DoQ proxy (dnscrypt-proxy & co.) listens on a *different* port as dnsmasq's upstream and never touches port 53 — it does not replace this step. Until port 53 is free, dnsmasq cannot bind it and the service fails to start (`failed to create listening socket for port 53: Address already in use`).

<details>
<summary>Why not just keep systemd-resolved and bind dnsmasq to its own addresses instead?</summary>

- You could: `bind-interfaces` / `bind-dynamic` makes dnsmasq bind `127.0.0.1` and the LAN IP individually instead of the default wildcard `0.0.0.0:53`, so it no longer collides with the stub on `127.0.0.53:53`. But running both resolvers at once is messy and fragile:

  - **Two sources of truth.** Anything that still reaches `127.0.0.53` — apps using the NSS `resolve` module, or a `resolv.conf` that got repointed — bypasses dnsmasq entirely. Your `hosts` / `block` / split-horizon rules and dnsmasq's query log do not apply, so dcm's live log and analytics silently miss those lookups.
  - **resolv.conf churn.** `systemd-resolved` (together with NetworkManager / netplan) may rewrite `/etc/resolv.conf` back to `127.0.0.53` on a network change or reboot, switching the box off dnsmasq without warning.
  - **More moving parts.** `bind-interfaces` only binds interfaces that exist at startup; addresses that appear later (DHCP, VPN tunnels) need `bind-dynamic`. The default wildcard bind avoids that — but the wildcard is exactly what collides with the stub.

  Freeing port 53 once (`DNSStubListener=no`) avoids all of it, and you do not need the stub: dnsmasq *is* your resolver.

  *Aside:* `listen-address` alone does **not** prevent the collision — by default it is only a software filter over the wildcard `0.0.0.0:53` socket, not an address-specific bind.
</details>

### 2. dnsmasq base config

dnsmasq is configured entirely through drop-ins in `/etc/dnsmasq.d/`; there is no monolithic config file. Point dnsmasq at an empty config file so it reads **only** the drop-in directory.

`/etc/default/dnsmasq`:
```sh
ENABLED=1
DNSMASQ_OPTS="--conf-file=/dev/null"
CONFIG_DIR=/etc/dnsmasq.d,.dpkg-dist,.dpkg-old,.dpkg-new
IGNORE_RESOLVCONF=yes
```

`--conf-file=/dev/null` overrides the compiled-in default (`/etc/dnsmasq.conf`) with an empty file, so dnsmasq's entire configuration comes from `CONFIG_DIR` (passed to dnsmasq as `--conf-dir`). dcm needs a hosts *directory* and a log *file*; create those as drop-ins:
```sh
mkdir -p /etc/dnsmasq.d
printf 'addn-hosts = /etc/dnsmasq.d/hosts\n'           > /etc/dnsmasq.d/addn-hosts.conf
printf 'log-facility = /var/log/dnsmasq/dnsmasq.log\n' > /etc/dnsmasq.d/log-facility.conf
```

> Only the log **file** is defined here. **Query logging itself is switched on later on the Configuration page** — that also drives the Live Log and Analytics pages, which stay hidden until logging is on. Defining the file but enabling logging in the UI keeps a fresh install fail-safe.

Create the hosts directory and its three files:
```sh
mkdir -p /etc/dnsmasq.d/hosts
touch /etc/dnsmasq.d/hosts/{local,vms,block}
```

These are ordinary dnsmasq hosts files (loaded via `addn-hosts`), each managed by its own UI page:
- **`local`** — your LAN hosts, including one entry per cluster node (required — see below).
- **`vms`** — virtual-machine records, with one-click subnet relocation in the UI. *(Interim solution: a future version will drop manual relocation and auto-detect the network, so a VM is always reachable by name no matter which connected network it is started in.)*
- **`block`** — software *phone-home* endpoints (e.g. the license-check servers of Acronis, Adobe, …) pinned to `127.0.0.1` so those lookups fail silently. This is **not** an ad-blocking list; ad-list blocking is a separate, still-planned feature.

`hosts/local` must contain one line per node mapping its hostname to its IP — `dcm-cli` reads these to generate each node's `listen.conf`:
```
192.168.2.10  node-a
192.168.2.11  node-b
```

### 3. Install dcm-cli and the node list
```sh
install -o root -g root -m 755 sbin/dcm-cli /usr/local/sbin/dcm-cli
mkdir -p /etc/dcm
printf '%s\n' node-a node-b > /etc/dcm/nodes    # short hostnames, one per line
```
List **all** nodes (including this one) in `/etc/dcm/nodes`. The binary and this file are pushed to the other nodes on the first sync, so you install them here only.

### 4. Let the web user call it
```sh
echo 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/dcm-cli *' > /etc/sudoers.d/dcm-cli
chmod 440 /etc/sudoers.d/dcm-cli
visudo -c                                        # validate before relying on it
```

### 5. Let PHP-FPM write to /etc

PHP-FPM ships with `ProtectSystem=full`, which makes `/etc` read-only for the service and everything it spawns (including `sudo dcm-cli`). Grant write access with a **drop-in override** — never edit the packaged unit file directly:

```sh
sudo systemctl edit php8.x-fpm
```

Add only the section and line you need:
```ini
[Service]
ReadWritePaths=/etc/dnsmasq.d /etc/dcm
```

systemd saves this to `/etc/systemd/system/php8.x-fpm.service.d/override.conf` (which survives package updates), then reload and restart:
```sh
sudo systemctl daemon-reload
sudo systemctl restart php8.x-fpm
```

<details>
<summary>Why a drop-in — and how to verify or revert it</summary>

- Do **not** edit `/lib/systemd/system/php8.x-fpm.service` directly: that file is owned by the `php8.x-fpm` package and is silently overwritten on the next update (e.g. from deb.sury.org), breaking your setup again without warning. A [drop-in override](https://www.freedesktop.org/software/systemd/man/latest/systemd.unit.html#Description) under `/etc/systemd/system/` is the supported way to customise a package-provided unit and survives updates.

  Verify the merged result at any time:
  ```sh
  sudo systemctl cat php8.x-fpm
  ```

  Revert to the package defaults:
  ```sh
  sudo systemctl revert php8.x-fpm
  sudo systemctl daemon-reload
  ```

  List every customised, overridden or masked unit on the system:
  ```sh
  sudo systemd-delta
  ```
</details>

### 6. Deploy the web frontend
```sh
mkdir -p /var/www/dcm
cp -r www/* /var/www/dcm/
chown -R www-data:www-data /var/www/dcm
```

The UI writes dnsmasq files directly (as `www-data`): the hosts and upstream files, and — on the Configuration page — one `<directive>.conf` drop-in per setting in `/etc/dnsmasq.d/`. Creating and removing those drop-ins needs write access to the **directory** itself, so make it group-writable by `www-data` (setgid, so new drop-ins inherit the group); give `www-data` the files it edits and keep the rest root-owned:
```sh
# the Configuration page creates/removes <directive>.conf drop-ins here
chown root:www-data /etc/dnsmasq.d && chmod 2775 /etc/dnsmasq.d
# UI-edited drop-ins (per-directive config + upstream.conf) -> owned by www-data
chown www-data:www-data /etc/dnsmasq.d/*.conf
chown www-data:www-data /etc/dnsmasq.d/hosts/local /etc/dnsmasq.d/hosts/vms
# generated per node / read-only in the UI by design -> stay root-owned
chown root:root /etc/dnsmasq.d/listen.conf /etc/dnsmasq.d/hosts/block
```
(`www-data` already runs `dcm-cli` as root via `sudo`, so making the drop-in directory group-writable is not an additional exposure.)

Apache vhost (`/etc/apache2/sites-available/dcm.conf`) — adjust the domain and TLS to your environment:
```apache
<VirtualHost *:443>
    ServerName   dns.example.net
    ServerAlias  dcm.example.net
    DocumentRoot /var/www/dcm
    DirectoryIndex index.php

    SSLEngine on
    SSLCertificateFile    /path/to/fullchain.pem
    SSLCertificateKeyFile /path/to/privkey.pem

    <Directory /var/www/dcm>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/dcm_error.log
    CustomLog ${APACHE_LOG_DIR}/dcm_access.log combined
</VirtualHost>
```
```sh
a2ensite dcm
systemctl reload apache2
```

> **Use a wildcard TLS certificate.** The UI node must **never** be exposed to the internet, so it cannot answer an ACME HTTP-01 challenge. Obtain a wildcard certificate via a DNS-01 challenge (or copy one from a host that already manages it) and point `SSLCertificateFile` / `SSLCertificateKeyFile` at it.

> **Make the UI reachable by name.** Once dcm is the resolver, the vhost's `ServerName` / `ServerAlias` resolve only if dnsmasq knows them. Add an entry mapping each UI hostname to the UI node's IP on the **Hosts** page (or in `hosts/local`).

> **Authentication is intentionally left out** — `inc/auth.php` is a no-op stub. Until real auth is added, keep the vhost behind HTTP Basic auth, a VPN, or a trusted network.

### 7. Passwordless root SSH — from the UI node to every other node

`dcm-cli sync` runs `rsync` and `ssh` **as root**, connecting to `root@<other-node>`. So root on the UI node needs key-based access to root on every other node.

This may already be in place — test it first:
```sh
sudo ssh -o BatchMode=yes root@node-b true && echo OK
```
If it prints `OK`, you are done. Otherwise set it up:

<details>
<summary>Set up root SSH keys from scratch</summary>

- Become root on the UI node and create a key if root does not have one yet (empty passphrase, so the unattended sync can use it):
  ```sh
  sudo -i
  [ -f ~/.ssh/id_ed25519 ] || ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519
  ```

  Install root@node-a's public key on each other node — this prompts once for that node's root password and appends the key to its `/root/.ssh/authorized_keys`:
  ```sh
  ssh-copy-id root@node-b
  ```

  Verify, then drop back to your normal user:
  ```sh
  ssh -o BatchMode=yes root@node-b true && echo OK
  exit
  ```

  If `ssh-copy-id` is refused because the other node forbids root login, temporarily set `PermitRootLogin yes` in its `/etc/ssh/sshd_config`, `systemctl restart ssh`, copy the key, then tighten to key-only:
  ```
  PermitRootLogin prohibit-password
  ```
</details>

### 8. First sync and restart
```sh
sudo dcm-cli sync
sudo dcm-cli restart all
```

`sync` writes the local `listen.conf`, then rsyncs the config, the node list and the `dcm-cli` binary to every other node and regenerates their `listen.conf` (the one file never copied between nodes). `restart all` then restarts dnsmasq on every node so the freshly synced config takes effect.

## Paths

| Path | Owner / mode | Notes |
|---|---|---|
| `/usr/local/sbin/dcm-cli` | root `755` | CLI backend, identical on every node |
| `/etc/dcm/nodes` | root | node short-hostnames, one per line |
| `/etc/sudoers.d/dcm-cli` | root `440` | lets `www-data` run `dcm-cli` as root |
| `/var/www/dcm` | www-data | web frontend |
| `/etc/dnsmasq.d` | `root:www-data` `2775` | drop-in dir; UI creates/removes `<directive>.conf` here |
| `/etc/dnsmasq.d/*.conf` | www-data | per-directive drop-ins + `upstream.conf`, edited in the UI |
| `/etc/dnsmasq.d/hosts/{local,vms}` | www-data | host records, edited in the UI |
| `/etc/dnsmasq.d/hosts/block` | root | phone-home endpoints → `127.0.0.1`, read-only in the UI |
| `/etc/dnsmasq.d/listen.conf` | root | per-node, generated, never synced |

## CLI usage

```
dcm-cli sync                           sync config + binary to all other nodes
dcm-cli restart local|remote|all       systemctl restart dnsmasq
dcm-cli status  local|remote           systemctl status dnsmasq
dcm-cli logs    [N]                    last N log lines (default 200)
dcm-cli tail-f  local|remote           stream the log (used by the live view)
dcm-cli stats   local|remote [period]  log analytics (all|today|1h|24h|7d)
```

## License

[GPL-3.0-or-later](LICENSE) © 2026 [ernolf] Raphael Gradenwitz
