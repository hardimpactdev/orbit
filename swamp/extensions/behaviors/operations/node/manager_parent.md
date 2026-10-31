# Parent manager action

`parentManagerAction()` calls the canonical `toolNode` manager policy inside the
current native enrollment or role run. It supports the fixed Linux managers
`apt`, `vp` and `composer`. It creates no Tool Operation, calls no Gateway
endpoint and implements no package policy. The internal `tool.manager_converge`
value selects that canonical policy; the Operation id and native run id remain
the Node parent's identities.

The parent records the manager input using the existing Gateway
`ToolManagerIntent.input()` renderer, together with its Node platform,
architecture and account. The parent binds its Operation and run before dispatch
and places the action on that exact Node using its target and matching label.
Task VM image-provided managers use `materialize: null`; their installed version
is still verified by the canonical action.

Include `parentManagerResources` in the parent behavior's resources. The adapter
scopes canonical observation and outcome resources by parent Operation, Node and
manager. Managers in one root cannot overwrite each other's receipts. The result
contains the canonical bounded outcome plus the Node and manager identity, and
retains the native data handles. No source environment or command output is
copied into the result.

The canonical action owns refusal handling and uncertain outcomes. The adapter
does not retry, compensate, wait on PHP, call another workflow or choose another
Node. This source alone does not replace the retained `ProvisionNodeAction` or
`AddNodeRoleAction` callers and grants no deletion clearance.
