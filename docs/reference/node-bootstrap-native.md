---
title: "Native Node bootstrap"
description: "Standalone bootstrap intent, native first contact, candidate accounts, and PHP prerequisites."
covers:
  - apps/gateway/app/Actions/Nodes/{AdmitNodeBootstrapAction,StartNodePhpPackagesAction}.php
  - apps/gateway/app/Domain/Nodes/{NodeBootstrapProjection,NodePhpPackagesInput,NodePhpPackagesProjection,bootstrap-direct-operations}.php
---

# Native Node bootstrap

The Gateway records one Operation for each standalone bootstrap kind. It admits the Node, candidate account, pinned SSH endpoint and published observer artifact. Swamp performs the work and returns a bounded result. Node enrollment composes the same lower actions as native steps in its own root; it creates no child Operations.

First contact runs on the exact admitted Gateway worker because the candidate Node does not yet need a worker. The target Node is an immutable participant. The existing protected bootstrap identity supplies a local SSH key path on the Gateway inside the action callback. The key stays on that host. Public intent and results contain no identity path or credential. Every connection checks the admitted host fingerprint before trusting the endpoint.

`node.bootstrap_probe` reads the platform, architecture and host key without installing software. Managed Linux is x86_64 only. macOS supports x86_64 and arm64. A platform or architecture mismatch fails before any download or change. `node.bootstrap_install` verifies the published observer's checksum before atomic delivery. The agent source and published pin remain frozen.

`node.bootstrap_host` prepares the admitted Linux packages, account, SSH access and passwordless sudo. `node.bootstrap_verify` checks the exact candidate login and observer. `node.macos_inspect` checks the existing macOS account and admitted WireGuard address. It creates no account, packages, DNS, firewall or service. The persisted prior account and other Nodes remain unchanged until the owning enrollment activates its candidate. Projection records observed architecture and host identity only; it does not repeat execution checks or commit an account.

`node.prepare_php_packages` installs the recipe's exact selected PHP builds on the target's worker. It preserves extra extensions and does not restart FPM masters. Its parent deploy or runtime DAG owns subsequent configuration and pool publication. The Gateway keeps version validation and recipe selection; Swamp owns downloads, package effects and recovery.

Each workflow has a separate failure callback on its root Node. It settles the original Operation when native execution fails before the family callback and preserves an already recorded terminal result. The Gateway records native results without step receipts, claims, child admission or execution transports. Frozen Rust action code is left for the final observer pass.