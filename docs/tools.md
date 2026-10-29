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
