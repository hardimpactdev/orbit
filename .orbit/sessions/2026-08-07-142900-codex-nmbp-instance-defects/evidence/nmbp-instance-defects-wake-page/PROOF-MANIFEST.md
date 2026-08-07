# Browser runtime proof — failed wake page failure reason

## Checkout identity

| Field | Value |
| --- | --- |
| Feature branch | `codex/nmbp-instance-defects` |
| Candidate HEAD | recorded on the `Verification.runtime` row in `.orbit/loop.md` |
| Worktree | `/Users/nckrtl/orbit/.worktrees/codex-nmbp-instance-defects` |
| Environment | `dev-fixture` |

## What this proves

The failed development-runtime wake page renders its recorded failure reason as
legible centered prose above the explicit retry link, in a real browser, from a
real terminally-failed activation run.

## Candidate rebinding

The renders were captured at `aba5d58b015b5359cfd8d4c7cf5633057693f65a`. The
receipt is bound to the later candidate
`66e969d2e89d760d922b9576b7fed2831d0bede4` without re-rendering, because the
delta between them touches only `AppRegisterController.php`,
`AppRegistrar.php`, `NodesProbe.php`, and `ToolInstaller.php` — none of which
is in the wake-page request chain. `runtime-activation.blade.php`,
`RuntimeActivationPage.php`, `RuntimeActivationController.php`,
`RuntimeActivationService.php`, and `RuntimeActivationOperations.php` are
byte-identical across that delta, so the page rendered here is the page this
candidate serves. Verify with
`git diff aba5d58b0..66e969d2e -- apps/gateway/resources/views/runtime-activation.blade.php apps/gateway/app/Services/Processes apps/gateway/app/Http/Controllers/Api/RuntimeActivationController.php`
(empty).

## Boundary (do not overclaim)

- This is **page-render proof only**. The response body was produced by the real
  gateway HTTP endpoint `GET /api/runtime-activations/app-instance/{id}` through
  the real `RuntimeActivationController` → `RuntimeActivationService` →
  `RuntimeActivationOperations` → `RuntimeActivationPage` → `runtime-activation`
  Blade chain against a real `OperationRun` in terminal `Failed` status, captured
  in the in-memory Pest harness, then served over a local static HTTP server and
  rendered in Chrome.
- It does **not** prove retained-topology backend convergence, Caddy
  `forward_auth` header emission, WireGuard peer identity, or the detached
  activation runner. Those are unchanged by this candidate and were retained-proven
  on the prior runtime-activation feature.
- The auto-retry backoff and the failure-reason HTTP contract are proven
  deterministically by Pest in `apps/gateway/tests/Feature/Services/Processes/RuntimeColdActivationTest.php`
  ("surfaces the failure reason on the failed wake page", "auto-retries a terminal
  failed activation after the backoff elapses"), not by this browser run.

## Method

1. Drove the real endpoint twice in the Pest harness (cold header set): first call
   begins the activation run, `RuntimeActivationRunner` fails it, second call
   returns the failed page. Captured the exact response body and headers.
2. Served the captured body over `php -S 127.0.0.1:8099` and rendered it in Chrome
   at 1436x840.

## Observed

| Item | Value |
| --- | --- |
| HTTP status | `503` |
| `X-Orbit-Runtime-Activation-State` | `failed` |
| `Retry-After` | absent (correct for the terminal failed page) |
| Content-Security-Policy | `default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; frame-ancestors 'none'` (no `script-src`, so no poll script on the failed page) |
| Rendered reason | "Orbit gateway bootstrap image is not configured and the running gateway service image could not be resolved." |
| Rendered retry link | "Try again" → `/?orbit-wake-retry=1` |

## Defect found and fixed by this browser run

The first render (`01-failed-page-before-css-fix.jpg`) showed the reason wrapped
into a ~105px column and horizontally offset from the Orbit mark, because `main`
is pinned to `width: min(100%, 158px)` for the spinner-only layout. Fixed by
widening the container only when a reason is present
(`main:has(.reason) { width: min(100%, 32rem); }`) and dropping the fixed width
from `.reason`. The second render (`02-failed-page-after-css-fix.jpg`) shows the
mark, the two-line centered reason, and the retry link on one centered axis. The
spinner-only pending page is structurally unaffected: the new rule requires a
`.reason` element that the pending page never renders.

## Files

| File | Content |
| --- | --- |
| `failed-wake-page.html` | Exact response body from the real endpoint |
| `failed-wake-page-headers.txt` | Exact response headers |
| `01-failed-page-before-css-fix.jpg` | Chrome render before the measure fix |
| `02-failed-page-after-css-fix.jpg` | Chrome render after the measure fix |
