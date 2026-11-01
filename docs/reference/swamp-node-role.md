---
title: "Swamp Node role source"
description: "The source contribution and activation boundary for the Swamp singleton."
---

The `swamp` source contribution defines a mutable, relocatable singleton that can share a Node with Gateway. This draft supplies public role policy, bounded native phases and a topology fixture. It does not register the role with Gateway admission, assign it to Node 209, load Swamp definitions or change the live registry. Relocation remains in the owning role parent.

## Contribution contract

`swampRoleConvergeSteps(name, intentExpr, fullGlobals, dependency?, guard?)` builds the target contribution. Its schema-1 intent holds the recorded Node and assignment IDs, `created`, the literal role `swamp`, managed user `orbit`, target WireGuard address, prepared source SHA, Services proxy source address and `activate`. The caller supplies the complete pinned official SSH globals. Every effect runs through official SSH from the control worker. Assets resolve once; account, install, checkout, configuration, release-load installation, unit loading, activation and verification each run as one script-file phase with a coreutils deadline and explicit queue timeout.

The image or F2 owner supplies Python YAML support and `/usr/local/libexec/behavior_versions.py`; the account phase refuses before writes when either is absent. The caller must prepare the exact source checkout through the image or canonical release supplier before this contribution runs. This contribution verifies its SHA; it does not introduce another source loader. It calls the existing `platform/swamp-load/install_release_load.py` installer function to publish the SHA-specific load entry. That phase performs zero loads and zero restarts. The Gateway release runner remains the only loader.

`activate:false` prepares public files and units for an image. It starts no service and returns `prepared:true` only when those phases succeed. It returns `role_proven:false` and `runtime_proven:false`. `activate:true` also requires successful service and TLS verification. The result preserves preparation facts if later activation fails. `publication_proven` remains false: authoritative `swamp.orbit` publication on Services belongs to the parent and cannot be proved by a target-local listener.

## Source and private material

The official binary pin and memory floor come from the existing worker supplier. The canonical primary worker unit and checker come from `node-worker-assets`; this contribution does not copy their policy. The public worker plan preserves the captured capacities of 7, 2, 2 and 2 and the migration/services pool labels.

Serve and its TLS proxy run as `orbit:orbit`. The official cfgmgmt firewall contribution admits TCP 9417 only from the captured Services proxy address. Its connection uses cfgmgmt SSH defaults; script phases use the caller's pinned official SSH globals. Image preparation skips the firewall phase. The proxy binds the admitted WireGuard address on 9417 and forwards to loopback 9418. It reads `/home/orbit/.config/swamp/serve-tls/cert.pem` and `key.pem`. Both must be regular files owned by `orbit:orbit` with mode `0600`. The contribution writes neither file. It requires the Gateway-owned credential push and certificate supplier to finish before activation.

Credentials and vault contents never enter the image, public intent or repository. Public descriptors contain file paths and owners only. The contribution preserves existing native state and vault contents in their separate storage paths and declares bind mounts into the prepared checkout. It refuses a foreign mount or untransferred native state rather than hiding it. It preserves `serve.yaml`, including trigger inputs; it does not activate the captured staged 90-minute ceiling.

Convergence never restarts Serve. A live legacy Process or changed active configuration requires its owning release or relocation sequence. This draft does not retire the existing Process records or supplementary worker records.

## Proof and remaining gates

The F13 fixture at `swamp/proof/node.role_add/swamp.json` requests `orbit node:role:add` on the topology's `spare` Node. It uses the topology Gateway and its normal credential/signing path. It is a pending fixture, not a successful receipt. Gateway role registration/admission, the parent publication composition and the roleless `spare` topology are separate integration gates. The captured Serve environment names `SWAMP_API_KEY_FILE` and `SWAMP_SERVER_TOKEN_FILE` still need their names-only source descriptors from the extensions owner; activation remains blocked on preserving those references. No live assignment or relocation is authorized by this source.
