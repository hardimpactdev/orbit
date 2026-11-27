---
title: "ADR 0242: Signed apt repositories for Tools"
description: "Manage signed vendor repositories as explicit Node records with their own lifecycle, executed in Swamp."
---

# ADR 0242: Signed apt repositories for Tools

A signed apt repository is a Node record with its own lifecycle. An apt Tool references one by name. Swamp verifies its pinned signing key, writes deb822 sources, and selects the package from that repository.

## Status

In progress.

Principle: [One way, one name](/mission#principles). Repositories have their own `tool:apt-repository-*` commands, API routes, SDK requests and MCP tools; Tool commands name them.

## Context

Apt Tools use only the Node's existing sources. Vendor software needs a repository and a signing key first. Rex's preview suite is published; its stable suite is not. Debian and Ubuntu also publish an unrelated package named `rex`, with a higher version.

## Decision

The Gateway stores one repository record per Node and name, with `uris`, `suites`, `components`, optional `architectures`, and `signing_key` with an HTTPS `url` and a full primary-key `fingerprint`. `tool:apt-repository-add` publishes it in an Operation, and the record is usable only after a publication succeeded: that add, or a convergence that publishes a repository its Tools reference with the same verified steps. No other event confirms or doubts it. A definition never changes under its name: adding the same name and definition republishes it, another definition is refused, and a change is a removal and a new add. The add refuses a URI and suite another repository of the Node has. Tool install, adoption and update name an existing, ready repository and never publish or change one. Removal refuses while a Tool references the repository. In file mode the catalog lists repositories by name and convergence publishes the ones its Tools reference, as their own add.

Swamp performs all execution through official SSH, where Tool writes already execute. It fetches each suite's InRelease before changing managed files, verifies that the downloaded key contains exactly the pinned primary key, exports that key to `/etc/apt/keyrings/orbit-<name>.gpg`, writes the apt preference `/etc/apt/preferences.d/orbit-<name>.pref`, and then writes `/etc/apt/sources.list.d/orbit-<name>.sources`. Apt verifies the repository signature through `Signed-By`. Refresh is limited to that source and treats any fetch error as failure. No global trust or source file is changed.

### Package scope

The preference limits each repository origin to its Tools' packages. The Gateway sends the packages of every Tool that references the repository. For each URI host, those packages get priority 990, above the distribution, so an upgrade never replaces them with a same-named distribution package. Every other package from that origin gets priority 1, so the vendor cannot replace or upgrade an installed or distribution package, and its other packages install only as new dependencies.

### Package selection and removal

Candidate lookup reads only the declared source and excludes installed-package status. Install uses that exact candidate through normal apt dependency resolution. An installed newer version is never downgraded. This selects Superlogical Rex rather than the distribution's unrelated package, and the scoped preference keeps it selected when apt upgrades the Node. Existing package ownership and SemVer constraints still apply.

Tool removal deletes only the recorded package and repins the repository's remaining owned packages; the repository stays, even after its last Tool, until `tool:apt-repository-remove`. Missing files and an already absent package are successful retry states. Adoption remains read-only and verifies the declared managed source, preference, and key. Scan includes apt packages and the repository intent of registered Tools.

## Rejected alternatives

- Downloaded vendor installers: they bypass Orbit ownership, retry policy, and the pinned fingerprint.
- Global apt-key trust: a vendor key must authorize only its repository.
- Gateway SSH execution or new agent actions: Swamp owns operational work.
- A preference that permits downgrades (priority 1000 or more): existing Tools never select a lower installed version.
- No preference: the vendor origin would serve any package at distribution priority, and unattended upgrades would replace Rex with the distribution's higher `rex`.
- One source per Tool: packages from the same vendor share one managed source and keyring.
- A repository definition inside Tool intent, trusted by inferring which Tool writes published it: two upgrade histories still let a stale writer gain source authority, and the repair refused valid requests. An explicit add is the only confirmation.

## Consequences

Operators move Rex from preview to stable once stable is published by removing its Tools and the repository, adding the repository again with the stable suite, and installing them again. A key rotation under the same definition is an add of the same name. A missing InRelease or a fingerprint mismatch fails with a bounded Tool error. Node sources outside the managed repository remain unchanged. Apt update and package locks remain owned by apt; the existing Node apt operation lock serializes shared repository intent.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk
- ADRs: ADR 0238
- Detail: [Tools](/reference/tools#apt-repositories)
- Verify: Tool API and SDK tests, CLI transport tests, Python repository tests, and native Swamp Tool workflow tests.
