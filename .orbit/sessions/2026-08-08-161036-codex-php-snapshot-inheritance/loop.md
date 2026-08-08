# Orbit Feature Loop

- Scratchpad: none (compact local loop)
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-php-snapshot-inheritance
- Branch: codex/php-snapshot-inheritance

## Goal

PHP runtime version becomes snapshot-inherited: the app value is a creation-time template that new instances copy and new workspaces copy from their owning instance, every instance and workspace then owns its concrete version, and changing an app default never reaches anything that already exists.

## Scope

- Owned: apps/gateway (instances migration and model, AppRuntimeContainerRenderer, PhpRuntimeManager, AppRegistrar, AppStoreController, CreateWorkspace, Workspace model), apps/cli php:list renderer, packages/sdk-typescript generated contract artifacts, apps/docs/content php and instance-register contracts, PRODUCT_DECISIONS.md
- Constraints: deploying must not change any running runtime's version; instance:register is instance-scoped for PHP; no live inheritance anywhere in the resolution path
- Out of scope: a command to change an app default after creation (needs the --app selector and update-existing contract decisions), document root under the same model (slice 2), removal of the vestigial workspace php_inherited field

## Authorization

- User decisions verbatim: "if an app changes its default PHP setting, then it should be a follow-up question whether instances and workspaces need to be updated as well. And I would never automatically update an instance or a workspace when you update the default version of an app. Only new instances and workspaces should inherit that new version, but not existing instances and workspaces." Plus confirmations: workspaces copy from their instance, document root gets the same treatment later, none is the default for update-existing, and instance:register is instance-only.

## Proof

- Verification:
  - focused: passed - migration backfill asserts exact per-row versions before and after for instances and for null-valued workspaces; renderer tests assert an instance keeps its own version and does not move when the app default changes; workspace tests assert own-value resolution, instance-before-app fallback for legacy rows, and immunity to an app default change; PhpRuntimeManager, UsePhpRuntime, PhpRuntimeController, AppRegisterController and the 417-test workspace surface all updated to snapshot semantics
  - broader: passed - artifact `.orbit/quality-gates/quality-check-2026-08-08T135955Z-5750904266b2.json` on exact commit 3f13d088a81ef9579a40517d1c1dd8e74cbd86cb, exit_code 0, all 45 subgates 0, dirty false
  - runtime: passed - candidate=3f13d088a81ef9579a40517d1c1dd8e74cbd86cb; venue=retained-incus; environment=dev-fixture; target=incus topology dev-481460 kind operator_gateway_app-dev on host beast, app snapproof on node app-dev-1, every command guarded by a sentinel that aborts unless it runs on the fixture operator VM against a gateway holding only fixture nodes; expected=php:use writes one instance and its container runs that PHP binary, raising the app default moves no existing instance or workspace, a new instance copies the current template into its own row, a new workspace copies its owning instance, and php:list plus instance:show report each instance own version beside the template; observed=php:use 8.3 --instance=snapproof.second returned target instance with previous 8.4 and version 8.3 and an empty warnings list, the two containers then reported PHP 8.4.22 and PHP 8.3.31 with matching ORBIT_PHP_VERSION, moving the app default to 8.5 left development on 8.4 and second on 8.3 and workspace snapfeature on 8.3 with all three containers unchanged, instance:add stored its own concrete version and held it when the app default moved again, workspace:new on the 8.3 instance stored 8.3 with php_inherited false, workspace:setup adopting a path stored 8.3 from its owning instance rather than 8.5 from the app template and held 8.3 when that instance moved to 8.4, and php:list rendered snapproof.second (PHP 8.3, app template 8.4); result=passed; evidence=`.orbit/evidence/php-snapshot-inheritance/PROOF-MANIFEST.md`
- Blast radius: complete - evidence=repo-wide creation-site inventory across apps/gateway/app, apps/gateway/database and packages plus a docs sweep for the retired vocabulary; result=exactly five Instance/Workspace persistence sites, all stamping php_version with no sixth, resolution order own then instance then app on every read surface, and zero remaining hits for shared app PHP policy wording
- Review: passed - terminal PASS from the general reviewer on the exact candidate after the harness split; human-judgment=not-required
- Reviewed feature tip: 3f13d088a81ef9579a40517d1c1dd8e74cbd86cb
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3f13d088a81ef9579a40517d1c1dd8e74cbd86cb
- Accepted main tip: 7c3d1a8bcc4bf9eea633d6dbbeab2124114a7ad8

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
