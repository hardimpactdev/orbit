---
title: Route transfer publications
description: Admission-time Route publication capture for native Instance transfer.
---

# Route transfer publications

`NativeRouteTransferPublication::capture(array $transferPlan, array $destinationInstallations)` captures complete public `before` and `after` publications while Instance and Route placement remain unchanged. The Instance parent calls this inside its admission transaction after `NativeRouteTransferPlan::capture` and canonical Process capture. The second argument is exactly `process_installations.destination_installations`, including its admitted destination Instance identity and any collision-adjusted Vite ports. The Route lower does not allocate ports or reuse the source ports. The parent records the result as workflow input. Steps never call the Gateway to refresh it.

The prospective view replaces the moving Instance's site projections through `DevelopmentSiteRepository::projectSites`. It uses the admitted destination Node and checkout path, and the canonical Route placements. Unrelated sites and literal alias domains remain in the complete inventory. No Node identity, Instance placement, Route status or target row is saved to produce the view. The request-scoped view is always cleared after rendering, including on failure.

Each Node row contains its public identity and `before`/`after` Caddy, shared PHP and private DNS artifacts. Caddy captures every ordered site block, including multiple blocks with the same source and scope. A certificate scope list describes references in each view. It is an inventory, not permission to delete a leaf.

## Publication revisions

An admission snapshot is not fresh state. A worker must compare each changed owned section with its captured before value. If current equals before, apply after; if current already equals after, keep it. Otherwise refuse the owned conflict. Sections outside the delta retain their current values, including unrelated additions and deletions. Restoration applies the inverse delta under the same rule. Replaying a whole admission-time file is unsafe.

The executable Caddy section merge retains all blocks in a source/name group. It requires the canonical global header to agree, and refuses foreign or malformed files. The resulting candidate still requires native Caddy validation, serialized activation and reload before cleanup. A merge result alone does not prove a loaded configuration or authorize certificate deletion.

## Native Caddy composition

The reusable native Caddy workflow is `operation-route-caddy-delta`, with fixed terminal selector `completion/result`. A parent passes its real `operation_id`, `kind`, `node_id`, an empty `execution_secrets` object, and `intent: {node_id: publicationNodeId, user, before: node.before.caddy, after: node.after.caddy, direction: "forward" | "restore"}`. It uses `@nckrtl/route-publication-command.assets` to validate the public payload and resolve shipped action files. The capture's optional block evidence is validated and omitted from the shipped payload.

Placed steps prepare and write public fragments, compose the candidate against the current managed file, and call the canonical Caddy native workflow with the observed digest and generation path. An intervening revision refuses before publication. Resume from `compose` recalculates the candidate using the same immutable delta; no step retries itself or reads the Gateway. The combined delta payload is bounded to 4 MiB so its encoded metadata fits the measured shared 8 MiB native frame. Logical Nodes 1/18 are refused.

Four official native scratch cases cover forward/restoration with unrelated revisions, owned conflicts, a missing destination file, and an intervening revision followed by native resume. Host commands are fixtures; these are not production or remote-custody proofs. Cleanup also needs proof that no loaded publication uses a certificate. This capture does not switch the transfer parent or make a live admission ready. Private DNS requires its own format-aware delta application; capturing it does not establish that application.

## Native PHP composition

The shared PHP composition is `operation-route-php-delta`, with fixed terminal selector `completion/result`. Its parent identity and public intent have the Caddy delta shape, using `node.before.php` and `node.after.php`. The metadata method is `@nckrtl/route-publication-command.php`. It returns public fragment metadata, chunks, version identifiers and shipped script paths. No execution secret is needed. Native steps stage, validate, publish, enable, reload and confirm each version; failure restores files, service enablement and runtime in separate steps.

Shared PHP deltas compare the exact changed pool sections with the current managed file. Unrelated pools and local text stay on the worker. A conflicting revision refuses before publication. Publication and compensation compare the captured file identity as well as its digest. The result never returns worker pool text. Missing application directories refuse; the parent must materialize destination files before this lower runs. This transfer lower does not move a pool between PHP versions in one call. Version migration requires the owning parent to order withdrawal and preparation explicitly. Its existence does not establish private DNS or loaded-certificate cleanup safety.

## Swamp facilities used

This lower uses core placed command/shell steps, shipped script files and native dependencies, fan-out and resume. Checked official swamp-extensions `d6f7afdcd6d04a63fdbc6ed56c403832770f67a6` cfgmgmt file/template/line/systemd and SSH exec/script. Whole-file replacement and line convergence cannot preserve unrelated current Caddy groups or PHP pool sections under an immutable before/after delta. Format-aware merging and Caddy/FPM validation remain family actions; execution, scheduling, results and transport remain native. No custom transport or callback is added.
