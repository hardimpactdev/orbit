# Task VM workspace child

`task-vm-workspace` prepares an already reachable Task VM's captured checkout directory. Every step runs on Node209. Guest actions use official `@swamp/ssh`; the VM needs no Swamp binary, worker or worker token.

The owning parent supplies its original `operation_id`, `node_id` and `kind`, plus `intent.task_vm_id`, `intent.checkout_path` and the full `intent.ssh_execution` map from `SwampSshExecutionInput::forNode`. The workspace path comes from the parent's managed apps/project/workspace capture. This child does not choose a filesystem layout or allocate a VM. Optional `group_id`, `instance_id` and `project_id` remain public context.

The child history locator is job `workspace`, step `result`. Its typed output is native child data, not a standalone Gateway admission. The owning root keeps its own terminal stdout/result selector. The public metadata step checks the input. The official SSH steps check the managed account, create the directory as that account, then inspect the directory and return `{task_vm_id,checkout_path,ready:true}`. A failed connection or directory action fails normally; resume uses native workflow resume. No custom retry, lease, supervisor or lock is added.

The parent calls the child with `type: workflow`, reads `steps.<call>.outputs.result`, and performs its own next native step. Directory readiness does not mean repository checkout, setup, Process readiness or complete VM provisioning. Lane25 owns repository/source/setup and Tasks terminal projection. Provider creation, SSH-key authorization and guest/network bootstrap precede this child. The shared Gateway terminal reader handles the owning root; this child has no Gateway client or callback.

Core/official review: native child workflows supply composition and recovery; official `@swamp/ssh` `exec` supplies pinned SSH and literal argv. Official `cfgmgmt` is unnecessary for an idempotent `mkdir -p`; no configuration engine, transport or secret channel is introduced. The existing209 `identityFile` is passed by its shared public supplier. The official extension's defaults remain unchanged.

## Plain VM worker retirement

TaskVM enrollment no longer issues a guest worker token, writes worker configuration or installs/starts a guest worker. The TaskVM-only worker helpers are deleted. TaskVM declarations and private bindings no longer admit worker credentials; Tasks inherits that declaration change. Old saved `issue-worker`, `configure-worker` and `worker-ready` stages refuse `operation.kind_unavailable` before private reads or effects. They do not become successful no-ops. Existing source/bootstrap/runtime parents still need their complete209 composition; this directory child does not claim those parents are ready.

The expanded-topology exception is separate: the sandbox's Gateway VM may own its own serve and one worker using Nick's existing collective token. The generic installer, topology seed and E2E topology harness remain intact. The removed outer TaskVM worker path had no topology predicate and did not implement that sandbox setup. No topology runtime readiness is claimed.
