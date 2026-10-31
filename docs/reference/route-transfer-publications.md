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

The publication executor and its native serialization checks remain in progress. Cleanup also needs proof that no loaded publication uses a certificate. This capture does not switch the transfer parent or make a live admission ready. Shared PHP and private DNS require their own format-aware delta application; capturing them does not establish that application.
