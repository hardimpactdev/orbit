# Automatic deployment policy

Execution follows the binding [Swamp shape guide](swamp-shape.md). This page
preserves selection and operator-control rules, not the retired tick, callback,
keyed-receipt or PHP switch-back implementation.

Select only an enabled production Instance on a Node with an active `app-prod`
role and no `app-dev` role. Pending, failed and removing production roles are
ineligible. Narrowing a selected Instance list cannot add an ineligible
Instance. Verify source, head and required checks before admitting a deployment;
incomplete or moved evidence stops admission.

Every manual deployment and every rollback pauses automatic deployment. A known
completed manual recovery followed by an explicit operator resume permits
selection again. A newer commit or resume alone does not prove that an earlier
unknown effect settled and cannot clear an unknown-effect hold.

Never issue a replacement mutation merely because a start response was lost.
Retain the uncertain state and reconcile actual execution before choosing
another action. A read outage is unknown evidence, not success or proof that no
effect occurred. Keep incident and blocked-commit state when recovery fails.

A default development Instance treats an `interrupted` deployment Operation with no deployment ID as a failed attempt. It clears that pending intent and persists one ten-minute backoff before admitting the current head through the normal automatic path. This does not create deployment evidence or mark the attempt successful. Other terminal Operations without deployment evidence retain their hold.

Use native step outputs and terminal run projection. Swamp owns execution and
recovery; the Gateway does not regain a deploy loop or executor fallback.
[Smoke failure policy](production-smoke-failure-contract.md) owns
verified-failure classification and code/data preservation.
