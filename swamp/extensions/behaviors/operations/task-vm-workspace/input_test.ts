import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.16";
import { WorkspaceInput, workspaceResult } from "./input.ts";

const intent = {
    task_vm_id: 184,
    checkout_path: "/home/orbit/apps/project/workspace",
    ssh_execution: {
        control_node_id: 209,
        identity_file:
            "/run/credentials/orbit-node-worker.service/proxy-ssh-identity",
        public_key: "ssh-ed25519 PUBLIC",
        target: {
            name: "target",
            host: "10.44.0.212",
            user: "orbit",
            port: 22,
            host_keys: ["ssh-ed25519 PIN"],
        },
    },
};
Deno.test("workspace carries the captured path and public canonical SSH input", () => {
    assertEquals(WorkspaceInput.parse(intent), intent);
});
Deno.test("workspace refuses missing pin and relative path before guest action", () => {
    assertThrows(() =>
        WorkspaceInput.parse({ ...intent, checkout_path: "relative" })
    );
    assertThrows(() =>
        WorkspaceInput.parse({
            ...intent,
            ssh_execution: {
                ...intent.ssh_execution,
                target: { ...intent.ssh_execution.target, host_keys: [] },
            },
        })
    );
});
Deno.test("workspace result comes from the official SSH receipt", () => {
    const output = {
        task_vm_id: 184,
        checkout_path: intent.checkout_path,
        ready: true,
    };
    assertEquals(workspaceResult({ stdout: JSON.stringify(output) }), output);
    assertThrows(() => workspaceResult({ stdout: "" }));
    assertThrows(() =>
        workspaceResult({ stdout: JSON.stringify({ ...output, ready: false }) })
    );
});

Deno.test("workspace preserves caller IDs and handles spaces as public path data", () => {
    const caller = {
        ...intent,
        checkout_path: "/home/orbit/apps/a project/workspace",
        group_id: 5,
        instance_id: 6,
        project_id: 7,
    };
    const { ssh_execution: _ssh, ...receipt } = WorkspaceInput.parse(caller);
    assertEquals(
        workspaceResult({
            stdout: JSON.stringify({ ...receipt, ready: true }),
        }),
        { ...receipt, ready: true },
    );
});
