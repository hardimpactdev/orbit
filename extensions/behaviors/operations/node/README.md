# Node Operations

The placed `operation-node` workflow claims `node.prepare_php_packages` and runs
only the admitted `php.install-build` input. The Gateway captures recipe pins
before admission; Swamp cannot select another Node, mirror, version, module
policy or FPM mode.

The installer keeps modules and uses `fpm: none`. Completion requires `current`
or `applied`. Source refusals become `app-prod.php_package_source_unavailable`;
other build refusals become `app-prod.php_install_failed`.

The invocation saves its first claimed Operation. After a lost reply it reads
the same target receipt, and after a lost completion it checks the terminal
Operation. A running receipt remains unsettled. Cancellation before the first
effect dispatches no installer.

Focused proof:
`deno test --allow-env --allow-read extensions/behaviors/operations/node`.

Only the deploy/rollback composition admits this child. Existing production and
Route callers keep the synchronous installer until their cutover. The Gateway
refuses changed recipe revisions and admissions older than 120 seconds at
dispatch. Missing data or unexpected outcomes complete failed. Kit C preserves
bounded `stale_masters` and `deferred` warning lists; the Gateway logs them.
