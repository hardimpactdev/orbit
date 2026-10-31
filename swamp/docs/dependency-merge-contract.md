# Dependency batch squash admission

The Gateway admits a Services-owned dependency batch with its successful
publication, exact repository, PR, head, configured smoke workflow and
Project-owned secret reference. It records intent, starts one native run on Node
209, and projects the public result. All existing Project enable switches remain
disabled by this conversion.

Swamp owns operational policy. On every invocation and resume it refreshes the
private Project switch, obtains the existing scoped installation credentials
through the shared private-material callback, and reads the same PR first. An
already-merged exact head supplies recovery without another write. Otherwise the
current private switch must enable this exact Project/repository/default
branch/smoke workflow.

Before a new expected-head squash PUT, Swamp requires an open non-draft
mergeable same-repository PR, the current default branch, complete paginated
root dependency-file evidence, approved manifest and lock content, every latest
exact-head PR workflow successful, `Required checks`, and a successful smoke
check in the configured active workflow's latest suite. It re-reads the PR after
these checks and refuses changed evidence. The Gateway does not repeat these
execution checks when minting or projecting.

Changed manifests may change only existing dependency constraint values. Build
instructions, repository settings, dependency names and ordered lists remain
unchanged. Registry constraints may not introduce a new breaking semver
boundary; numeric ranges cannot become tags. Composer dev/local/Git constraints
and npm aliases are refused.

Only root `composer.json`, `composer.lock`, `package.json`, `package-lock.json`,
`npm-shrinkwrap.json`, `pnpm-lock.yaml`, `yarn.lock` and `bun.lock` paths
qualify, including both rename paths. Removed files, missing pages, duplicates
and GitHub's 3,000-file boundary are refused. Yarn remains an unsupported lock
format.

Composer package metadata and executable fields must match fresh Packagist P2
records. Its closed source/archive maps accept only the documented HTTPS
registry/GitHub hosts. npm uses the exact installed-name/version registry
tarball with integrity; pnpm v9 and Bun text locks use implicit registry
sources. Local, Git, alias and off-registry dependency edges are refused. Every
installed package must be reachable from the root manifest; JavaScript package
names must already exist in the base lock. Composer virtual providers must
already exist in the base lock and cannot replace a formerly real dependency.
Only top-level breaking version changes block a batch; attested transitive
updates may cross majors.

Private material contains four existing installation tokens: repository write
(including PR reads), contents read, checks read and Actions read. They exist only in the worker callback; no
token enters arguments, resources, errors, logs or public results. Every token
is revoked when the callback ends, including failed reads and merges. GitHub
bodies are bounded, redirects are refused, and credentials go only to fixed
GitHub API paths. Packagist reads carry no credential.

Uncertain GitHub responses remain resumable. Resume observes the same PR and
head before considering another write. Only `{merged:true,head_sha,merge_sha}`
or a fixed refusal code reaches projection. No polled executor, claim,
heartbeat, receipt ledger, child Operation or PHP merge path remains. The
existing task factory observes the merge and owns group completion and cleanup.
This operation does not deploy or enable a Project.
