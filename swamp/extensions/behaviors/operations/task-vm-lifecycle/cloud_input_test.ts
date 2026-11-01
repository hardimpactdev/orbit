import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.14";
import { cloudChoice, cloudObservation, cloudResult } from "./cloud_input.ts";
import { discoverProvider } from "./input.ts";
const image = "00000000-0000-4000-8000-000000000001",
    uuid = "bb757fe7-0d7f-497e-b160-b545075e2cba";
const intent = {
    task_vm_id: 42,
    node_id: 14,
    host_node_id: null,
    guest_node_id: 142,
    gateway_node_id: 14,
    model_router_node_id: 6,
    provider: "upcloud",
    provider_id: null,
    name: "tvm-42",
    image_fingerprint: image,
    recipe_identity: "sha256:" + "a".repeat(64),
    vm: null,
    create: null,
    bootstrap_host_key: "ssh-ed25519 public",
    ssh_identity: {
        control_node_id: 209,
        identity_file: "/identity",
        public_key: "ssh-ed25519 public",
    },
    upcloud: {
        zone: "fi-hel1",
        plan: "test",
        disk_gb: 20,
        max_vms: 1,
        source_template: image,
    },
};
const row = () => ({
    uuid,
    title: "tvm-42",
    hostname: "tvm-42",
    state: "started",
    labels: {
        label: [{ key: "orbit-task-vm", value: "42" }, {
            key: "orbit-task-vm-image",
            value: image,
        }],
    },
    ip_addresses: {
        ip_address: [{
            access: "public",
            family: "IPv4",
            address: "93.184.216.34",
        }],
    },
});
const plans = JSON.stringify({
    plans: { plan: [{ name: "test", storage_tier: "standard" }] },
});
Deno.test("cloud selection reuses same VM and enforces account budget before create", () => {
    assertEquals(
        cloudChoice(
            intent,
            JSON.stringify({ servers: { server: [row()] } }),
            plans,
        ).provider_id,
        uuid,
    );
    const other = { ...row(), title: "tvm-43" };
    assertThrows(
        () =>
            cloudChoice(
                intent,
                JSON.stringify({ servers: { server: [other] } }),
                plans,
            ),
        "task_vm.upcloud_budget_full",
    );
    assertThrows(() =>
        cloudChoice(intent, JSON.stringify({ servers: { server: [] } }), "{}")
    );
    assertEquals(
        cloudChoice(intent, JSON.stringify({ servers: { server: [] } }), plans)
            .storage_tier,
        "standard",
    );
});
Deno.test("cloud receipt needs matching owner image public address and started state", () => {
    assertEquals(
        cloudResult(intent, JSON.stringify({ server: row() }), uuid)
            .ssh_host_key,
        intent.bootstrap_host_key,
    );
    for (const field of ["title", "hostname", "uuid"]) {
        const bad = { ...row(), [field]: "wrong" };
        assertThrows(() =>
            cloudObservation(intent, JSON.stringify({ server: bad }), uuid)
        );
    }
    const bad = row();
    bad.labels.label[1].value = "foreign-image";
    assertThrows(
        () => cloudResult(intent, JSON.stringify({ server: bad }), uuid),
        "task_vm.provider_identity_invalid",
    );
    assertThrows(
        () =>
            cloudResult(
                intent,
                JSON.stringify({ server: { ...row(), state: "stopped" } }),
                uuid,
            ),
        "task_vm.not_settled",
    );
});
Deno.test("unknown provider UUID cleanup discovery refuses foreign or duplicate identities", () => {
    const provider = {
        task_vm_id: 42,
        provider: "upcloud",
        name: "tvm-42",
        provider_id: null,
        vm: null,
        host_ssh_execution: null,
    };
    assertEquals(
        discoverProvider(
            provider,
            JSON.stringify({ servers: { server: [row()] } }),
        ).provider_id,
        uuid,
    );
    assertEquals(
        discoverProvider(provider, JSON.stringify({ servers: { server: [] } }))
            .provider_id,
        null,
    );
    const bad = row();
    bad.labels.label[0].value = "43";
    assertThrows(
        () =>
            discoverProvider(
                provider,
                JSON.stringify({ servers: { server: [bad] } }),
            ),
        "task_vm.provider_identity_invalid",
    );
    assertThrows(
        () =>
            discoverProvider(
                provider,
                JSON.stringify({ servers: { server: [row(), row()] } }),
            ),
        "task_vm.provider_identity_invalid",
    );
});

Deno.test("cloud UUID and owner are proven by detail after label-less discovery", () => {
    const { labels: _labels, ...listed } = row();
    const listing = JSON.stringify({ servers: { server: [listed] } });
    assertEquals(cloudChoice(intent, listing, plans).provider_id, uuid);
    const provider = {
        task_vm_id: 42,
        provider: "upcloud",
        name: "tvm-42",
        provider_id: null,
        vm: null,
        host_ssh_execution: null,
    };
    assertEquals(discoverProvider(provider, listing).provider_id, uuid);
    const foreign = "6651dd0d-fb0e-4689-9664-22de529dd200";
    assertThrows(
        () => cloudChoice({ ...intent, provider_id: foreign }, listing, plans),
        "task_vm.provider_identity_invalid",
    );
    assertThrows(
        () =>
            cloudObservation(
                intent,
                JSON.stringify({ server: { ...row(), uuid: foreign } }),
                uuid,
            ),
        "task_vm.provider_identity_invalid",
    );
    assertThrows(() =>
        cloudObservation(intent, JSON.stringify({ server: listed }), uuid)
    );
});

Deno.test("pre-start observation may lack an address but settled receipt requires one", () => {
    const pending = {
        ...row(),
        state: "maintenance",
        ip_addresses: { ip_address: [] },
    };
    assertEquals(
        cloudObservation(intent, JSON.stringify({ server: pending }), uuid)
            .address,
        null,
    );
    assertThrows(() =>
        cloudResult(intent, JSON.stringify({ server: pending }), uuid)
    );
    assertThrows(() =>
        cloudResult(
            intent,
            JSON.stringify({ server: { ...pending, state: "started" } }),
            uuid,
        )
    );
    assertEquals(
        cloudResult(intent, JSON.stringify({ server: row() }), uuid).address,
        "93.184.216.34",
    );
});
