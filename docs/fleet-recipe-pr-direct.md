# Direct recipe pull-request maintenance

Swamp owns the GitHub reads, authenticated package-index checks and narrowly
permitted recipe pull-request writes for `fleet.recipe_pr`. The Gateway admits
one fixed action on `hardimpactdev/orbit`, exposes safe release state and
projects a bounded outcome. GitHub App signing remains in the Gateway; the
shared GitHub mint supplies a short-lived scoped token only to the admitted run.
No family relay, route or token is added.

The workflow preserves the existing policy: App-authored PR and signed single
commit, exact expected head, one modified recipe file, authenticated snapshot or
complete artifact-pin change, and every required check green on that head.
Refresh additionally requires failed trusted checks, a superseded parent and
complete untouched timeline/edit history before an expected-head ref update.
Before an open action writes to GitHub, it saves the first authenticated public
candidate in a native `open_plan` resource, keyed by Operation ID. The resource
contains the admitted public intent, base commit and recipe blob, candidate
content and branch. It contains no token. It lasts two days under native GC,
covering Amsterdam's 25-hour autumn date while retries still pass the date gate. A
retry validates the same admitted intent and reuses that candidate even if
artifact indexes have advanced. Existing branch content suppresses a repeated
content write, and an existing PR on that branch suppresses repeated creation.
The expected-old-head update protects refresh pushes: after a successful push,
the old admitted head is absent and retry makes no second reachable ref update.

Closed date or pin PRs remain closed. Retirement rechecks App ownership and head
before closing an obsolete snapshot PR. GitHub uncertainty fails the native run
for inspection; there is no Gateway execution cache, retry timer or receipt.

One stable behavior identity serializes GitHub actions on the admitted Gateway
Node worker, with matching target and Node label. The approved shared private
reader mints only for that authenticated Node and exact bound run. This is
control-plane GitHub work and does not install or converge another Node.

The maintenance producer reads candidates every five minutes, only when the
Gateway reports the direct writer. It rotates retire, refresh and merge actions
so each runs at most every fifteen minutes. Daily opens use one Gateway intent
key per date, variant and serving recipe revision. They retain no Swamp
admission receipt and poll no Operation status. A deployment mismatch projects
`fleet.recipe_pending_deployment` before any GitHub write; a new serving recipe
revision allows a fresh intent. Unknown mutation outcomes fail natively for
inspection before recovery. A committed terminal projection prevents native
resume from minting or executing again.

Existing PR actions use stable Gateway intent keys derived from their relevant
observations. Retirement wakes for recipe or candidate-set changes, refresh for
main or check changes, and merge for check changes plus the recipe revision for
artifact candidates. These actions also wake when a candidate leaves draft,
changes its base, or changes its source repository. Ordinary main commits do not
wake retirement. Snapshot merge admission waits for passed checks; artifact
inspection can run before green, while the action still enforces green checks
before merging. Repeated maintenance requests return the existing intent rather
than starting a new native root. The Gateway exposes read-only source revisions,
with no retry or execution cache. Daily open identity uses the serving recipe
separately from the upstream recipe blob revision. External index changes alone
do not wake an existing action within the same date; the next daily intent
inspects them again. Inspect native history before recovering an uncertain
write.

`native_test.py` runs the shipped DAG in a disposable native repository, with
fake Gateway/GitHub transports and a synthetic credential. It covers binding
refusal, open writes, revocation, terminal policy refusal, lost projection and
uncertain writes, and scans all retained files for credential bytes. This is
fixture evidence, not a live Gateway, GitHub or worker canary.

The owning conversion includes the writer switch and removal of its old Gateway
execution and tests. Merge first, canary immediately after release, and revert
on failure, as PLAN requires.
Work on this branch is not live takeover evidence.
