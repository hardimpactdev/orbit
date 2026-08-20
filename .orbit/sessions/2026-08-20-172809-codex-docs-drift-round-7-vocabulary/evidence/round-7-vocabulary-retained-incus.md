# Round 7 vocabulary retained Incus proof

- Candidate: `88a596bf4f889104a8354d444295a570139ca5c1`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-7d1566` (`operator_gateway_app-dev`, host `beast`)
- Solo terminal: process `2544` (`vocabulary-retained-incus-proof`)
- Runtime checkout: `/home/orbit/orbit-run`
- Launcher: `/home/orbit/orbit-run/apps/cli/orbit`
- Candidate sync: `composer e2e:incus -- --sync --id=dev-7d1566`
- Runtime checksum: `CfCacheRuleAddCommand.php` = `575b63ba1408dad2e1f76ada773ad36620a42c0ecbf4af15a20081a847f605de`
- Runtime checksum: `CfCacheRuleRemoveCommand.php` = `879ecf3d379bf204381a6ab339909db953a5b8a9f6fc4f76c4e16c4f68b5d24c`
- Guard checksum: `AppInstanceVocabularyContractTest.php` = `8d86a6a6c5e835404503f682abbd407b66c0642cf5ace17040f99963048feea8`

## Observations

The agent ran all commands in Solo terminal 2544 inside the retained operator VM.

1. `orbit schedule:<add|list|show|remove|run|logs> --help`
   - All six commands rendered `(app.instance; bare app only when unambiguous)`.
2. `orbit app:new vocabulary-proof --node=app-dev-1 --repo=laravel/laravel --no-interaction`
   - The candidate-bound human frame rendered `Creating App`, `Prepare app creation`, `Create app source`, and `Register app`.
   - Source creation stopped at the external repository access boundary. The source/repository use of `project` is an explicit reconciliation exclusion.
3. `orbit instance:register vocabulary-proof --node=app-dev-1 --path=/home/orbit/orbit --no-interaction`
   - First run rendered `Instance for app 'vocabulary-proof' adopted` and the adopted success message.
   - Second run rendered `Instance for app 'vocabulary-proof' converged` and the already-converged message.
4. `orbit app:new vocabulary-proof --node=app-dev-1 --repo=laravel/laravel --json`
   - Returned `App name 'vocabulary-proof' is already registered in the gateway app registry on node 'app-dev-1'.`
5. `orbit instance:add vocabulary-proof.development --node=app-dev-1 --path=/home/orbit/orbit --root=public --json`
   - Returned `Instance 'development' already exists for app 'vocabulary-proof'.`
6. `orbit instance:show vocabulary-proof.missing --json`
   - Returned `Instance 'missing' was not found for app 'vocabulary-proof'.`
7. `orbit instance:env list vocabulary-proof.missing --json`
   - Returned the same canonical missing-instance message from the env controller.
8. `orbit php:use 8.5 --instance=vocabulary-proof.development --node=gateway --json`
   - Returned `Node 'gateway' does not own app 'vocabulary-proof'.`
9. `orbit cf-cache-rule:add vocabulary-proof --no-interaction`
   - Rendered `Resolve instance domain`, `Resolve Cloudflare zone`, and `Write cache rule` before the expected gateway-extension boundary.
10. `orbit cf-cache-rule:remove vocabulary-proof --force --no-interaction`
    - Rendered `Resolve instance domain`, `Resolve Cloudflare zone`, and `Delete cache rule` before the expected gateway-extension boundary.

Result: passed. After the candidate sync, the retained runtime directly re-exercised the changed human help, corrected progress frames, success outcome, and JSON failure envelopes. Focused Pest assertions cover the workspace-step error envelopes, and the exact 28-string source guard covers every reconciled branch.
