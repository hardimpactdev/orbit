import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.14";
import { observation, ProviderInput, receipt } from "./input.ts";
const ssh = {
    control_node_id: 209,
    identity_file:
        "/run/credentials/orbit-node-worker.service/proxy-ssh-identity",
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
    provider_id: null,
    vm: { project: "tasks", name: "tvm-42" },
    host_ssh_execution: ssh,
});
const serverId = "5666a7a9-8e26-44e0-badc-0053bc11422f";
const cloud = ProviderInput.parse({
    task_vm_id: 42,
    provider: "upcloud",
    name: "tvm-42",
    provider_id: serverId,
    vm: null,
    host_ssh_execution: null,
});
const vm = (status: string) =>
    JSON.stringify([{ name: "tvm-42", type: "virtual-machine", status }]);
const server = (state = "started") => ({
    server: {
        uuid: serverId,
        title: "tvm-42",
        hostname: "tvm-42",
        state,
        labels: { label: [{ key: "orbit-task-vm", value: "42" }] },
        storage_devices: {
            storage_device: [{
                type: "disk",
                storage: "6651dd0d-fb0e-4689-9664-22de529dd200",
            }],
        },
    },
});
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
Deno.test("UpCloud observation proves UUID title hostname label and sole disk", () => {
    assertEquals(
        receipt(cloud, "start", JSON.stringify(server())).running,
        true,
    );
    for (
        const mutate of [
            (v: ReturnType<typeof server>) =>
                v.server.labels.label[0].value = "43",
            (v: ReturnType<typeof server>) => v.server.title = "tvm-43",
            (v: ReturnType<typeof server>) => v.server.hostname = "tvm-43",
            (v: ReturnType<typeof server>) =>
                v.server.uuid = "6651dd0d-fb0e-4689-9664-22de529dd200",
            (v: ReturnType<typeof server>) =>
                v.server.storage_devices.storage_device.push(
                    v.server.storage_devices.storage_device[0],
                ),
        ]
    ) {
        const row = server();
        mutate(row);
        assertThrows(
            () => observation(cloud, "start", JSON.stringify(row)),
            "task_vm.provider_identity_invalid",
        );
    }
    assertThrows(
        () =>
            observation(cloud, "start", JSON.stringify(server("maintenance"))),
        "task_vm.not_settled",
    );
    assertEquals(receipt(cloud, "delete", "{}").absent, true);
});
Deno.test("provider input retains original VM and canonical209 SSH", () => {
    assertThrows(() => ProviderInput.parse({ ...incus, name: "other" }));
    assertThrows(() =>
        ProviderInput.parse({
            ...incus,
            host_ssh_execution: { ...ssh, control_node_id: 14 },
        })
    );
    assertThrows(() => ProviderInput.parse({ ...cloud, provider_id: null }));
});

Deno.test("inspect reports absence and running state without requesting mutation", () => {
    assertEquals(observation(incus, "inspect", "[]"), {state:"absent",disk:null,change:false});
    assertEquals(receipt(incus, "inspect", vm("Running")).running, true);
    assertEquals(observation(incus, "inspect", vm("Running")).change, false);
});
