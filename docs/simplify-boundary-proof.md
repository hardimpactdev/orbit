# Tools boundary proof

Nick approved T1–T5 on 2026-10-29. T6 (an mTLS listener) is deferred.
The orchestrator owns independent review, merge, and deployment.

Tools keeps its public commands, five routing entrypoints, fixed Node target,
manager policy, result shapes, cancellation, and keyed effect recovery. One
family plan serves those entrypoints. The full design proposes one internal
`tool.manage` kind; this proof preserves the current wire contract.

The Gateway family spec now declares command names and `done_when` variants.
A shared compiler emits the existing allowlist and completion contract. Its
capability requirements replace `ToolOperationAvailability`. The generated
contract has the same SHA-256 as the old declaration.

Swamp uses the admitted step descriptors for command names and input. It no
longer maintains another command catalogue, builds package input, or copies
materialization input from fields by hand. The fake Gateway supplies those
descriptors in tests. Its 100-case digest is checked against actual Orbit
contexts from the proof base.

The Gateway stops repeating package-policy checks in `beforeRun` and
`assertCompletion`. Swamp is trusted to enforce candidate constraints,
no silent adoption, exact removal plans, required mutation, and final package
state. These are tested in the existing Tools workflow suite. Gateway tests
for that second policy implementation are removed. Gateway admission,
owner/Node/command/input checks, native schema checks, inventory projection,
redaction, keyed receipts, generic receipt proof, and terminal conflict checks
remain. This is a partial implementation of T3, not removal of all receipt
verification.

The relevant Swamp cases are:

- `install refuses silent adoption, including on a restarted process`
- `candidate gates stop install and leave a blocked update unchanged`
- `APT and Composer removal uses only an exact single-package plan and never selects a candidate`
- `removal refuses a plan that includes another root or dependencies`
- `exact removal verifies absence and already absent removal is harmless`
- `a fresh dispatch settles open Operation 1190 from its successful empty mutation receipt`
- `a running receipt leaves the original Operation open without replaying the effect`

The Gateway still proxies Node calls in this proof. No vault values, new Node
credentials, grants, access edges, listeners, or live resources are changed.
The full design is at
`beast:~/swamp-execution/designs/simplify-gateway-swamp-boundary.md`.

The Gateway receipt test reuses the real loaded kind registry within one test.
It previously rebuilt every family's registry on each request and exceeded
the stock two-second case limit. The timeout was not raised.
