# Dependency merge admission policy

Execution follows the binding [Swamp shape guide](swamp-shape.md). These
admission rules remain in force; the retired private-material and Gateway
callback protocol does not.

A merge needs the owning Project's enabled policy and exact repository, PR,
head, default branch and configured smoke workflow. Require an open, non-draft,
mergeable same-repository PR and complete paginated evidence for every changed
file. Re-read the PR after checking evidence and refuse a changed head. An
already-merged exact head is an observation, not a reason to repeat the write.

Every latest exact-head PR workflow must succeed, including `Required checks`.
The configured active smoke workflow must also have a successful latest suite.
Checks for another head, incomplete evidence or an unknown result do not
authorize merging.

Only root dependency manifests and supported lockfiles qualify: `composer.json`,
`composer.lock`, `package.json`, `package-lock.json`, `npm-shrinkwrap.json`,
`pnpm-lock.yaml` and `bun.lock`. Check both rename paths. Reject removed files,
duplicate or missing pages and GitHub's truncated file-list boundary. Yarn
remains unsupported.

Manifest changes may alter only existing dependency constraint values. Preserve
build instructions, repository settings, dependency names and ordered lists. Do
not turn numeric ranges into tags or introduce a breaking registry constraint
boundary. Refuse Composer dev/local/Git constraints and npm aliases.

Verify Composer metadata and executable fields against fresh Packagist records
and approved HTTPS registry/GitHub endpoints. Verify npm tarballs against exact
installed name/version and integrity. Refuse off-registry, local, Git and alias
dependency edges. Packages must be reachable from the root manifest; JavaScript
package names and Composer virtual providers must already exist in the base
lock. A virtual provider cannot replace a formerly real dependency. Attested
transitive updates may cross majors; top-level breaking changes block the batch.

Use scoped short-lived GitHub credentials through the guide's vault and minting
boundary. Never forward them to registry reads or arbitrary endpoints. Native
outputs retain the exact merged head and merge identity; an uncertain write
needs observation before another mutation. This policy does not deploy or enable
a Project.
