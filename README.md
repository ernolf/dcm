<!--
SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
SPDX-License-Identifier: GPL-3.0-or-later
-->

<!-- Project header -->
<p>
  <img src="www/assets/logo.svg" alt="dcm — dnsmasq cluster manager" width="480" align="left">
  <h3>Web frontend + CLI for a two-node dnsmasq cluster</p>
</p>
<p>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-GPL--3.0--or--later-blue"></a>
  <img alt="PHP 8.4" src="https://img.shields.io/badge/PHP-777BB4">
  <img alt="Bash" src="https://img.shields.io/badge/shell-bash-4EAA25">
</p>
<hr>

# dcm — dnsmasq cluster manager

manages a two-node dnsmasq cluster from a single place:
- add, toggle and delete hosts
- relocate VM records between subnets
- sync the configuration to all nodes
- restart the service
- watch both servers' query logs live with per-server analytics.

The web frontend drives a single privileged CLI backend (`dcm-cli`) over `sudo`.

---
