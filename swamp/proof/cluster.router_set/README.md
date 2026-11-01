# Router cutover and return proof

Preparation is blocked on current main: `NativeInstanceProvisioningIntent::assertCreateSupported` refuses web-serving Projects, and `StartDevelopmentInstanceCreateAction` refuses an explicit domain. B/lane 9 owns normal serving-Instance/publication support. These are draft fixture inputs, not an executable or proven path today. The normal refusal must stop preparation; do not seed an Instance in the Gateway database or substitute a narrower proxy Route.

Run `01-forward` then `02-return` in the same topology. The declared topology is `app-dev+ingress`, `spare+router`, `spare-2+router`. Lane 11 owns its catalog. Both variants use normal CLI admission and the topology Gateway's CA. They use no production Node IDs, direct database writes or separate signer.

Each variant prepares its old Router, a local fixture Project and a real PHP Instance/Route. `before_request` records the selected old Router, the Route, both Router identities, loaded Caddy and fresh DNS answers. The request selects the other Router. Parity requires a successful native result and matching normal Gateway projection, actual candidate selection, exact admitted Caddy files, loaded/adapted Caddy agreement, candidate HTTPS and fresh DNS on both Routers. It reads affected forward leaves from `prepare_certificates` (the forward `certificates` list is intentionally empty). It checks the admitted public CA for each affected certificate and exact admitted DNS files on the DNS participants. Private keys are never read.

`01-forward` selects `spare-2`; `02-return` separately selects `spare`. The second preparation is idempotent for the selected Router after a successful first variant. It adds a distinct proof Route; the return compares the complete admitted publication, which includes the first Route. Each variant receives its own Operation/run receipt. A failed forward followed by restoration cannot pass either success check. The harness must stop after a failed variant and retain its failure evidence.

Both hooks and parity use repo-relative argv paths under the final F13 worktree-root cwd rule.

The repository is local PHP fixture source, declared as a `symfony-app` to use the supported PHP serving contract without Laravel lifecycle steps. The runner serves it from its topology-local bare repository through `${proof.repo.url}`. A Project/source refusal fails preparation; there is no URL or credential bypass.

The run budget is the existing provisional 2,700 seconds per Operation; the explicit Router queue timeout is 600 seconds. These are not a successful measured p95. The saved failed op 6592 supplied the observations in `docs/reference/native-cluster-router.md`. The phase table maps the root's native preparation, forward publication and old-Router retirement; the source reference lists their legacy subphases.

No real F13 receipt exists. The fixture is pending the shared runner, topology catalog, normal repository admission and successful topology bring-up. A source or synthetic test is not a receipt. These variants prove Router set and the return, not Router unset.
