---
title: "ADR 0242: Signed apt repositories for Tools"
description: "Manage signed vendor repositories as shared Node intent and execute their lifecycle in Swamp."
---

# ADR 0242: Signed apt repositories for Tools

An apt Tool carries a signed repository definition. Tools on one Node share a repository by name. Swamp verifies its pinned signing key, writes deb822 sources, and selects the package from that repository.

## Status

In progress.

Principle: [One way, one name](/mission#principles). Repository intent uses the existing Tool API, CLI, MCP catalogue, and native Swamp operations.

## Context

Apt Tools use only the Node's existing sources. Vendor software needs a repository and a signing key first. Rex's preview suite is published; its stable suite is not. Debian and Ubuntu also publish an unrelated package named `rex`, with a higher version.

## Decision

The Gateway stores one repository definition per Node and slug, referenced by Tool intent. The definition contains `name`, `uris`, `suites`, `components`, optional `architectures`, and `signing_key` with an HTTPS `url` and a full primary-key `fingerprint`. Repository definitions are accepted only for apt. A Tool update can replace the definition under the same name, including its suite, URI, and signing key. Every Tool sharing that name then uses the new intent. Install and adoption refuse a conflicting definition.

Swamp performs all execution through official SSH, where Tool writes already execute. It fetches each suite's InRelease before changing managed files, verifies that the downloaded key contains exactly the pinned primary key, exports that key to `/etc/apt/keyrings/orbit-<name>.gpg`, writes the apt preference `/etc/apt/preferences.d/orbit-<name>.pref`, and then writes `/etc/apt/sources.list.d/orbit-<name>.sources`. Apt verifies the repository signature through `Signed-By`. Refresh is limited to that source and treats any fetch error as failure. No global trust or source file is changed.

### Package scope

The preference limits each repository origin to its Tools' packages. The Gateway sends the packages of every Tool that references the repository. For each URI host, those packages get priority 990, above the distribution, so an upgrade never replaces them with a same-named distribution package. Every other package from that origin gets priority 1, so the vendor cannot replace or upgrade an installed or distribution package, and its other packages install only as new dependencies.

### Package selection and removal

Candidate lookup reads only the declared source and excludes installed-package status. Install uses that exact candidate through normal apt dependency resolution. An installed newer version is never downgraded. This selects Superlogical Rex rather than the distribution's unrelated package, and the scoped preference keeps it selected when apt upgrades the Node. Existing package ownership and SemVer constraints still apply.

Removal deletes only the recorded package. After proven absence, Swamp removes the source, preference, and key only for the final Tool reference. A shared repository keeps the removed package in its preference until the next write for that repository. Missing files and an already absent package are successful retry states. Adoption remains read-only and verifies the declared managed source, preference, and key. Scan includes apt packages and the repository intent of registered Tools.

## Rejected alternatives

- Downloaded vendor installers: they bypass Orbit ownership, retry policy, and the pinned fingerprint.
- Global apt-key trust: a vendor key must authorize only its repository.
- Gateway SSH execution or new agent actions: Swamp owns operational work.
- A preference that permits downgrades (priority 1000 or more): existing Tools never select a lower installed version.
- No preference: the vendor origin would serve any package at distribution priority, and unattended upgrades would replace Rex with the distribution's higher `rex`.
- One source per Tool: packages from the same vendor share one managed source and keyring.

## Consequences

Operators can move Rex from preview to stable with a normal Tool update once stable is published. A vendor key rotation is a normal Tool update with the new `signing_key`; the keyring is replaced and the old key is gone. A missing InRelease or a fingerprint mismatch fails with a bounded Tool error. Node sources outside the managed repository remain unchanged. Apt update and package locks remain owned by apt; the existing Node apt operation lock serializes shared repository intent.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk
- ADRs: ADR 0238
- Detail: [Tools](/reference/tools#signed-apt-repositories)
- Verify: Tool API and SDK tests, CLI transport tests, Python repository tests, and native Swamp Tool workflow tests.
