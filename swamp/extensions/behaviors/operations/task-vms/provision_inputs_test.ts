import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import {
    provisionCommandInput,
    provisionMetadata,
    provisionProviderResult,
    provisionSeedObservation,
    provisionTerminalResult,
    provisionVmObservation,
} from "./provision_inputs.ts";

const intent = () => ({
    task_vm_id: 12,
    node_id: 9,
    host_node_id: 9,
    guest_node_id: 210,
    gateway_node_id: 14,
    issuer_node_id: 209,
    model_router_node_id: 6,
    provider: "incus",
    name: "tvm-12",
    image_fingerprint: "a".repeat(64),
    recipe_identity: `sha256:${"b".repeat(64)}`,
    vm: { project: "orbit-task-vms", name: "tvm-12", cidr: "10.240.9.0/24" },
    create: {
        image: "a".repeat(64),
        expected_fingerprint: "a".repeat(64),
        gateway_public_key: `ssh-ed25519 ${btoa("k".repeat(32))}`,
        cpus: 2,
        memory: "2GiB",
        disk: "20GiB",
        network: "orbit-task",
        pool: "default",
        topology: true,
    },
});

Deno.test("seed planning requires the exact immutable source or the exact existing clone", () => {
    assertEquals(
        provisionSeedObservation(
            intent(),
            JSON.stringify([{ name: "tvm-12-topology-seed", type: "custom" }]),
        ),
        { present: true },
    );
    assertEquals(
        provisionSeedObservation(
            intent(),
            JSON.stringify([{
                name: `orbit-topology-seed-${"a".repeat(12)}`,
                type: "custom",
            }]),
        ),
        { present: false },
    );
    assertThrows(() =>
        provisionSeedObservation(
            intent(),
            JSON.stringify([{ name: "tvm-13-topology-seed", type: "custom" }]),
        )
    );
});

const terminal = () => ({
    intent: intent(),
    provider: {
        provider_id: null,
        address: "10.240.9.12",
        image_fingerprint: "a".repeat(64),
        running: true,
        settled: true,
    },
    node: {
        ssh_host_key_type: "ssh-ed25519",
        ssh_host_key: btoa("k".repeat(32)),
        ssh_host_fingerprint: "SHA256:public",
        wireguard_public_key: "p".repeat(43) + "=",
    },
    model: { task_vm_id: 12, issued: true },
    process: { status: "active" },
    network_ready: true,
    runtime_ready: true,
});

Deno.test("terminal output retains provider identity and guest public keys without a progress callback", () => {
    const result = JSON.parse(provisionTerminalResult(terminal()).result_json);
    assertEquals(result.ready, true);
    assertEquals(result.enrollment.node.address, "10.240.9.12");
    assertEquals(result.enrollment.node.ssh_host_fingerprint, "SHA256:public");
    assertEquals(result.provider.image_fingerprint, "a".repeat(64));
});

Deno.test("terminal fold refuses foreign model or image proof and incomplete readiness", () => {
    assertThrows(() =>
        provisionTerminalResult({
            ...terminal(),
            model: { task_vm_id: 13, issued: true },
        })
    );
    assertThrows(() =>
        provisionTerminalResult({
            ...terminal(),
            provider: {
                ...terminal().provider,
                image_fingerprint: "c".repeat(64),
            },
        })
    );
    assertThrows(() =>
        provisionTerminalResult({ ...terminal(), network_ready: false })
    );
    assertThrows(() =>
        provisionTerminalResult({ ...terminal(), runtime_ready: false })
    );
});
const vm = () => ({
    name: "tvm-12",
    type: "virtual-machine",
    status: "Running",
    config: { "volatile.base_image": "a".repeat(64) },
    state: {
        network: {
            eth0: { addresses: [{ family: "inet", address: "10.240.9.12" }] },
        },
    },
});

Deno.test("public provision metadata preserves the pinned image and topology cloud-init without executing anything", () => {
    const result = provisionCommandInput(intent());
    const config = JSON.parse(result.config_json);
    assertEquals(config.devices["topology-seed"], {
        type: "disk",
        pool: "default",
        source: "tvm-12-topology-seed",
    });
    assertEquals(
        JSON.parse(result.input_json).create.expected_fingerprint,
        "a".repeat(64),
    );
    assertEquals(
        JSON.parse(config.config["cloud-init.user-data"].slice(14)).users[0]
            .ssh_authorized_keys,
        [intent().create.gateway_public_key],
    );
});

Deno.test("provision metadata refuses inconsistent admitted provider and host identity", () => {
    for (
        const patch of [{ name: "tvm-13" }, { host_node_id: 10 }, {
            image_fingerprint: "c".repeat(64),
        }]
    ) {
        assertThrows(() => provisionCommandInput({ ...intent(), ...patch }));
    }
});

Deno.test("typed observation distinguishes absence and exact desired running state", () => {
    assertEquals(provisionVmObservation(intent(), "[]"), {
        exists: false,
        running: false,
        address: null,
    });
    assertEquals(
        provisionVmObservation(intent(), JSON.stringify([vm()]), true),
        { exists: true, running: true, address: "10.240.9.12" },
    );
    assertThrows(
        () => provisionVmObservation(intent(), "[]", true),
        Error,
        "task_vm.vm_missing",
    );
});

Deno.test("provider fragment exposes only settled public provider identity to its parent", () => {
    assertEquals(provisionProviderResult(intent(), JSON.stringify([vm()])), {
        provider_id: null,
        address: "10.240.9.12",
        image_fingerprint: "a".repeat(64),
        running: true,
        settled: true,
    });
    assertThrows(() => provisionProviderResult(intent(), "[]"));
    assertThrows(() =>
        provisionProviderResult(
            intent(),
            JSON.stringify([{ ...vm(), status: "Stopped" }]),
        )
    );
});

Deno.test("typed observation rejects foreign fingerprints, duplicate identities and ambiguous guest addresses", () => {
    assertThrows(
        () =>
            provisionVmObservation(
                intent(),
                JSON.stringify([{
                    ...vm(),
                    config: { "volatile.base_image": "c".repeat(64) },
                }]),
            ),
        Error,
        "task_vm.image_mismatch",
    );
    assertThrows(() =>
        provisionVmObservation(intent(), JSON.stringify([vm(), vm()]))
    );
    const row = vm();
    row.state.network.eth0.addresses.push({
        family: "inet",
        address: "10.240.9.13",
    });
    assertThrows(() => provisionVmObservation(intent(), JSON.stringify([row])));
    assertThrows(() =>
        provisionVmObservation(
            intent(),
            JSON.stringify([{ ...vm(), type: "container" }]),
        )
    );
});

Deno.test("pending power or address fails once instead of starting a hidden wait loop", () => {
    assertThrows(
        () =>
            provisionVmObservation(
                intent(),
                JSON.stringify([{ ...vm(), status: "Stopped" }]),
                true,
            ),
        Error,
        "task_vm.not_settled",
    );
    assertThrows(
        () =>
            provisionVmObservation(
                intent(),
                JSON.stringify([{ ...vm(), state: null }]),
                true,
            ),
        Error,
        "task_vm.not_settled",
    );
});

Deno.test("metadata shaping removes seed templates while preserving other public metadata", () => {
    const result = provisionMetadata(
        "architecture: x86_64\ntemplates:\n  /var/lib/cloud/seed/nocloud-net/user-data: {template: cloud-init-user.tpl}\n  /etc/hostname: {template: hostname.tpl}\n",
    );
    assertEquals(result.includes("cloud-init-user.tpl"), false);
    assertEquals(result.includes("hostname.tpl"), true);
    assertEquals(result.includes("architecture: x86_64"), true);
});
