import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.14";
import { observation, ProviderInput, receipt } from "./input.ts";
const ssh = {
    control_node_id: 209,
    identity_file: "/etc/orbit/swamp-role-worker/ssh-identity",
    public_key: "ssh-ed25519 public",
    target: {
        name: "target",
        host: "192.0.2.10",
        port: 22,
        user: "orbit",
        host_keys: ["ssh-ed25519 pin"],
    },
};
const incus = ProviderInput.parse({
    task_vm_id: 42,
    provider: "incus",
    name: "tvm-42",
    vm: { project: "tasks", name: "tvm-42" },
    host_ssh_execution: ssh,
});
const vm = (status: string) =>
    JSON.stringify([{ name: "tvm-42", type: "virtual-machine", status }]);
Deno.test("power receipt needs actual desired power and exact VM", () => {
    assertEquals(observation(incus, "start", vm("Stopped")).change, true);
    assertEquals(receipt(incus, "start", vm("Running")).running, true);
    assertThrows(
        () => receipt(incus, "start", vm("Stopped")),
        "task_vm.not_settled",
    );
    assertThrows(
        () => receipt(incus, "stop", vm("Running")),
        "task_vm.not_settled",
    );
    assertThrows(() => observation(incus, "start", "[]"), "task_vm.vm_missing");
});
Deno.test("delete admits absence and stopped power, never another named object", () => {
    assertEquals(receipt(incus, "delete", "[]").absent, true);
    assertThrows(
        () => observation(incus, "delete", vm("Running")),
        "task_vm.not_settled",
    );
    assertThrows(
        () => observation(incus, "stop", vm("Starting")),
        "task_vm.not_settled",
    );
    assertThrows(() =>
        observation(
            incus,
            "start",
            vm("Running").replace("virtual-machine", "container"),
        )
    );
});
Deno.test("provider input retains original VM and the control Node as an id", () => {
    assertThrows(() => ProviderInput.parse({ ...incus, name: "other" }));
    assertThrows(() =>
        ProviderInput.parse({
            ...incus,
            host_ssh_execution: { ...ssh, control_node_id: "swamp" },
        })
    );
});

Deno.test("inspect reports absence and running state without requesting mutation", () => {
    assertEquals(observation(incus, "inspect", "[]"), {
        state: "absent",
        disk: null,
        change: false,
    });
    assertEquals(receipt(incus, "inspect", vm("Running")).running, true);
    assertEquals(observation(incus, "inspect", vm("Running")).change, false);
});

Deno.test("power changes only for an unsettled desired state", () => {
    for (
        const [action, state, change] of [
            ["start", "Stopped", true],
            ["start", "Running", false],
            ["stop", "Running", true],
            ["stop", "Stopped", false],
            ["delete", "Stopped", true],
            ["delete", null, false],
        ] as const
    ) {
        const stdout = state === null ? "[]" : vm(state);
        assertEquals(observation(incus, action, stdout).change, change);
        if (!change) assertEquals(receipt(incus, action, stdout).settled, true);
    }
});
