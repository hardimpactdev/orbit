# Source fixtures

The manager binary, Bun table, Bun/npm advisory JSON, and Hauzer/Mealou mobile
outdated and locked versions come from `hardimpactdev/maintainer`, main commit
caf034a6b04758465caeadcc6da58257f8bb53ae, covering PRs #1–#5. JSON formatting
follows Deno; values are unchanged.

The Deno harness adds fixture paths inside each binary launcher after capturing
the environment the manager receives. It does not stub internal extension code.
PHP is a test-only dependency. Git commands run against real temporary
repositories.
