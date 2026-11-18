import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { inventoryReceipt } from "./receipt.ts";
import services from "./services.fixture.json" with { type: "json" };

const scope = { node_id: 17, profile: "services" as const };
const powersync = "orbit-process-264-powersync-hauzer-dev";
const ok = {
    status: "ok",
    id: "e".repeat(64),
    state: "running",
    running: true,
    pid: 123,
    started_at: "2026-01-01T00:00:00.000000001Z",
    restarts: 0,
    start_ticks: { status: "ok", value: 402 },
};
const publish = (value: unknown, target = scope) =>
    JSON.parse(inventoryReceipt({ ...target, stdout: JSON.stringify(value) }).stdout);

Deno.test("the Services receipt keeps only fixed runtime metadata and missing results", () => {
    const receipt = {
        ...services,
        powersync: { ...services.powersync, [powersync]: ok },
    };
    assertEquals(publish(receipt), receipt);
    assertThrows(() => publish(receipt, { ...scope, node_id: 4 }), Error, "node.base_inventory_invalid_receipt");
});

Deno.test("the inventory refuses extra paths, hashes of secret bytes, Docker details and omitted workloads", () => {
    const metadata = { status: "ok", owner: "root", group: "root", mode: "0600", type: "regular" };
    const values = [
        { ...services, verifier: "sensitive" },
        { ...services, files: { ...services.files, "/etc/shadow": metadata } },
        { ...services, files: {
            ...services.files,
            "/etc/orbit/agent/secret": { ...metadata, digest: { status: "ok", sha256: "a".repeat(64) } },
        } },
        { ...services, powersync: { ...services.powersync, [powersync]: { ...ok, env: ["SECRET=hidden"] } } },
        { ...services, powersync: { ...services.powersync, "arbitrary-name": ok } },
        { ...services, powersync: { [powersync]: ok } },
    ];
    for (const receipt of values) {
        assertThrows(() => publish(receipt), Error, "node.base_inventory_invalid_receipt");
    }
});

Deno.test("the worker rejects a substituted Node or Operation before loading code and exposing a destination", async () => {
    const { assertRejects } = await import("jsr:@std/assert@1.0.19");
    const { behavior } = await import("../ansible-input/write.ts");
    const admission = JSON.stringify({
        operation_id: 45,
        node_id: 17,
        profile: "services",
        target: { name: "target", node_id: 17, host: "192.0.2.17", user: "orbit", port: 22,
            host_keys: ["ssh-ed25519 YQ=="] },
        expected: {},
        websocket_port: 8790,
        backing_containers: [],
    });
    const context = {
        extensionFile: (_path: string): never => { throw new Error("collector loaded"); },
        writeResource: (): never => { throw new Error("resource written"); },
    };
    for (const args of [{ node_id: 4, operation_id: 45 }, { node_id: 17, operation_id: 46 }]) {
        await assertRejects(() => behavior.methods.baseInventorySource.execute({ ...args, admission, reference: "node-base-inventory-45-verifier" }, context),
            Error, "node.base_inventory_invalid_binding");
    }
});
