# Tool operations

Swamp executes admitted `tool.install`, `tool.update`, `tool.remove`,
`tool.adopt`, and `tool.manager_converge` Operations. The Gateway validates
requests and records the selected Node, manager, package, constraint, and
managed ownership. The normal CLI and MCP commands return an Operation and
follow its steps.

The tools family reuses the existing typed agent commands. It sends no script,
environment, arbitrary arguments, or caller-selected executable. Linux supports
apt, Composer, Vite+ and Homebrew formulae. macOS supports the enrolled
account's existing Vite+ and Homebrew formula/cask scopes. The Node always comes
from the admitted Operation.

Install checks the manager and the live package before changing it. It refuses
silent adoption. Adopt reads one selected package and never materializes a
manager or changes a package. Constraints gate the manager's normal candidate;
they never select an older version. Removal checks the exact removal plan and
verifies absence. Protected prerequisites cannot be adopted or removed. Scan
stays in Orbit and reads Homebrew and Vite+ inventory without materializing
managers or recording discovered packages as managed Tools.

Package candidate, adoption, removal-plan and command-failure execution tests
live in this workflow family. They cover APT and Composer refusals, native
Homebrew formula/cask adoption facts, fixed macOS inputs and the original
package observation after a failed mutation. Composer adoption reads root
membership and installed inventory: an absent package reports
`tool.package_absent`, and an installed dependency reports
`tool.adoption_unsupported`. Neither selects a candidate or changes a package.
Gateway adapters retain admission policy and read-only probes. Gateway command
activity records admission; the verified completion projects managed Tool state.

Services discovers claimable kinds without claiming an Operation. The Tools
workflow takes `{kind, worker}` and runs in the migration worker pool. That
placed worker claims the kind and records its first Operation ID in a durable
assignment before effects. Claim, heartbeat, effect and completion writes use
the same authenticated Node. A retry uses the recorded ID and never claims
another Operation. After a lost completion reply, it reads and verifies the
original terminal result or replays the saved completion under its existing
lease. An expired lease requires a new dispatch; the stale retry stops.

Each named effect has a stable Operation-local key. Swamp records intent before
execution, resumes successful step receipts, and saves completion intent before
posting it. A lost reply reuses its key. A lost lease stops the worker.
Cancellation before a new effect closes the Operation; a proven effect is
recorded before completion.

The Gateway may encode an empty command result as `[]`. Tools treats only that
empty array as an empty result record, including saved step and completion
receipts. Non-empty arrays remain invalid. A resumed successful package mutation
uses its existing receipt before checking inventory and projecting Tool intent.
For an unfinished step with recorded intent, the workflow reads its original
keyed receipt first. A terminal receipt finishes that step without invoking its
command again. A running receipt leaves the Operation open. Only an unknown
receipt permits the original keyed command to be sent again.

Deploy the empty-result recovery fix to orbit-swamp on Services first. It uses
the already deployed Gateway receipt API and needs no Gateway update. Ordinary
discovery then reclaims expired open Tool Operations, including 1190, and reads
the successful package receipt before inventory verification and completion.
Keep the original Operation, receipts and managed package; no manual completion,
new install, adoption or access change is part of recovery.

The Gateway's tools manifest restricts commands and exact admitted inputs.
Completion requires matching successful agent run and step receipts. Inventory
retains only declared scalar leaves, with secret redaction. The Gateway projects
verified results into Tool and manager records; Swamp has no direct database
access.

After paired deployment, use the orchestrator's designated dev Node and a
harmless package. Record install, update and remove Operation IDs, steps and
results, plus the direct scan report. Use a separate explicitly installed
package for adoption proof, then remove only that exact managed package. Do not
change production Nodes or Instances for this proof.

This family depends on Kit v2 C (scalar lists and completion variants), D
(AwaitOperation), and the shared OperationFamilyExecution hooks. The Gateway
projects native inventory receipts into one selected version and outcome before
applying result_fields. It retains no inventory objects. The kind declarations
contain no secret sources or child Operations. Release-scoped Vite+ build
environments belong to the deploy family. Target effect receipts use the shared
kit; this family does not implement its own Node receipt store.
