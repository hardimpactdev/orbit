import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
    type Child,
    lifecycle,
    type Receipt,
    type Session,
} from "./lifecycle.ts";

class Fixture implements Session {
    id = 100;
    nodeId = 9;
    kind = "instance.register";
    input: Receipt = {
        instance_id: 501,
        initial_status: "reserved",
        first_setup_pending: true,
        route_id: 30,
        database_server_id: null,
        database_clone_source_id: null,
        setup_digest: "a".repeat(64),
        teardown_digest: "b".repeat(64),
    };
    calls: string[] = [];
    children: Child[] = [];
    results = new Map<string, Receipt>();
    saved = new Map<string, Receipt>();
    effects = new Map<string, number>();
    loseReply: string | null = null;
    run(key: string): Promise<Receipt> {
        return this.effect(
            key,
            this.results.get(key) ??
                (key.endsWith("-runtime-intent")
                    ? { process_ids: [], schedule_ids: [] }
                    : { instance_id: 501 }),
        );
    }
    child(child: Child): Promise<Receipt> {
        this.children.push(child);
        return this.effect(
            child.key,
            this.results.get(child.key) ??
                (child.kind === "instance.run_steps"
                    ? {
                        outcome: "succeeded",
                        error_code: null,
                        failed_step: null,
                    }
                    : { instance_id: 501 }),
        );
    }
    async effect(key: string, result: Receipt): Promise<Receipt> {
        this.calls.push(key);
        await Promise.resolve();
        if (!this.saved.has(key)) {
            this.effects.set(key, (this.effects.get(key) ?? 0) + 1);
            this.saved.set(key, structuredClone(result));
        }
        if (this.loseReply === key) {
            this.loseReply = null;
            throw new Error("reply lost");
        }
        return this.saved.get(key)!;
    }
}

Deno.test("rename waits for the Route child before it records branch or environment", async () => {
    const f = new Fixture();
    f.kind = "instance.rename";
    f.input.domain = "preview.test";
    f.results.set("route-update", { error_code: "route.domain_conflict" });
    assertEquals((await lifecycle(f)).error_code, "route.domain_conflict");
    assertEquals(f.calls, ["rename-inspect", "route-update"]);
});

Deno.test("clone never runs development setup or teardown", async () => {
    const f = new Fixture();
    f.kind = "instance.clone";
    f.input.candidate_node_id = 2;
    f.input.candidate_id = 100;
    f.input.sqlite_source_path = null;
    assertEquals((await lifecycle(f)).status, "succeeded");
    assertEquals(
        f.children.some((c) => c.kind === "instance.run_steps"),
        false,
    );
    assertEquals(f.children[0].kind, "instance.sync_environment");
    assertEquals(f.children[0].node_id, f.nodeId);
});

Deno.test("clone attaches and synchronizes only a proven database-family SQLite seed", async () => {
    const f = new Fixture();
    f.kind = "instance.clone";
    f.input.sqlite_source_path = "/srv/apps/source/database.sqlite";
    f.results.set("clone-sqlite-intent", {
        prepared: true,
        seed_required: true,
    });
    assertEquals((await lifecycle(f)).status, "succeeded");
    assertEquals(f.children[0].kind, "database.sqlite_seed");
    assertEquals(f.calls.slice(6, 10), [
        "clone-sqlite-intent",
        "clone-sqlite",
        "clone-sqlite-attach",
        "clone-environment-sync",
    ]);
});

Deno.test("clone resumes a retained seed checkpoint without taking another snapshot", async () => {
    const f = new Fixture();
    f.kind = "instance.clone";
    f.input.sqlite_source_path = "/srv/apps/source/database.sqlite";
    f.results.set("clone-sqlite-intent", {
        prepared: true,
        seed_required: false,
    });
    assertEquals((await lifecycle(f)).status, "succeeded");
    assertEquals(f.calls.includes("clone-sqlite"), false);
    assertEquals(f.calls.includes("clone-sqlite-attach"), true);
});

Deno.test("a refused SQLite seed preserves clone state before attachment or environment writes", async () => {
    const f = new Fixture();
    f.kind = "instance.clone";
    f.input.sqlite_source_path = "/srv/apps/source/database.sqlite";
    f.results.set("clone-sqlite-intent", {
        prepared: true,
        seed_required: true,
    });
    f.results.set("clone-sqlite", { error_code: "sqlite.seed_failed" });
    assertEquals((await lifecycle(f)).error_code, "sqlite.seed_failed");
    assertEquals(f.calls.includes("clone-sqlite-attach"), false);
    assertEquals(f.calls.includes("clone-environment-sync"), false);
});

Deno.test("registration runs setup only when it was requested", async () => {
    for (const requested of [false, true]) {
        const f = new Fixture();
        f.kind = "instance.register";
        f.input.members = [{
            instance_id: 501,
            route_node_id: 9,
            route_id: 30,
        }];
        f.input.run_setup = requested;
        assertEquals((await lifecycle(f)).status, "succeeded");
        assertEquals(f.calls.includes("setup"), requested);
    }
});

Deno.test("a confirmed registration failure restores its Laravel receipt before reporting the original refusal", async () => {
    const f = new Fixture();
    f.kind = "instance.register";
    f.input.members = [{ instance_id: 501, route_node_id: 9, route_id: 30 }];
    f.results.set("registration-501-configuration", {
        error_code: "app-dev.laravel_url_configuration_failed",
    });
    assertEquals(
        (await lifecycle(f)).error_code,
        "app-dev.laravel_url_configuration_failed",
    );
    assertEquals(f.calls.at(-1), "registration-501-receipt-restore");
    assertEquals(f.calls.includes("registration-501-activate"), false);
});

Deno.test("cancelled, busy and unconfirmed registration effects retain their saved Laravel receipt", async () => {
    for (
        const code of [
            "operation.cancelled",
            "instance.source_busy",
            "operation.execution_uncertain",
        ]
    ) {
        const f = new Fixture();
        f.kind = "instance.register";
        f.input.members = [{
            instance_id: 501,
            route_node_id: 9,
            route_id: 30,
        }];
        f.results.set("registration-501-configuration", { error_code: code });
        assertEquals((await lifecycle(f)).error_code, code);
        assertEquals(
            f.calls.includes("registration-501-receipt-restore"),
            false,
        );
        assertEquals(
            f.calls.includes("registration-501-receipt-discard"),
            false,
        );
    }
});

Deno.test("transfer proves destination completion before cutover and source cleanup", async () => {
    const f = new Fixture();
    f.kind = "instance.transfer";
    f.input.destination_node_id = 10;
    f.input.transfer_id = "proof-transfer";
    f.results.set("transfer-destination", {
        error_code: "instance.transfer_failed",
    });
    assertEquals((await lifecycle(f)).status, "failed");
    assertEquals(f.calls, [
        "transfer-stop-source",
        "transfer-capture",
        "transfer-sqlite-intent",
        "transfer-destination",
        "transfer-rollback",
    ]);
    assertEquals(
        f.children.find((child) => child.key === "transfer-destination")!
            .node_id,
        10,
    );
});

Deno.test("cancellation after the stop child admits compensation without a parent rollback run", async () => {
    const f = new Fixture();
    f.kind = "instance.transfer";
    f.input.destination_node_id = 10;
    f.input.transfer_id = "cancelled-transfer";
    f.results.set("transfer-capture", { error_code: "operation.cancelled" });
    assertEquals((await lifecycle(f)).status, "cancelled");
    assertEquals(f.calls, [
        "transfer-stop-source",
        "transfer-capture",
        "transfer-rollback",
    ]);
    assertEquals(f.children.at(-1)!.kind, "instance.transfer_rollback");
});

Deno.test("pending capture cancellation retains both copies without admitting rollback", async () => {
    const f = new Fixture();
    f.kind = "instance.transfer";
    f.input.destination_node_id = 10;
    f.input.transfer_id = "pending-transfer";
    f.results.set("transfer-capture", {
        error_code: "operation.cancelled",
        rollback_pending: true,
    });

    assertEquals(await lifecycle(f), {
        status: "cancelled",
        error_code: "operation.cancelled",
        result: { error_code: "operation.cancelled", rollback_pending: true },
    });
    assertEquals(f.calls, ["transfer-stop-source", "transfer-capture"]);
});

Deno.test("rollback restores the source before recorded SQLite seed and destination cleanup", async () => {
    const f = new Fixture();
    f.kind = "instance.transfer_rollback";
    f.results.set("rollback-record", {
        recorded: true,
        seed_abandon_required: true,
        destination_node_id: 10,
        seed_abandon_node_id: 12,
    });
    assertEquals((await lifecycle(f)).status, "succeeded");
    assertEquals(f.calls, [
        "rollback-record",
        "transfer-restore-source",
        "transfer-abandon-sqlite",
        "transfer-discard-destination",
        "rollback-state",
        "compensation-proof",
    ]);
    assertEquals(f.children.map((child) => child.kind), [
        "process.instance_runtime",
        "database.sqlite_seed_abandon",
        "instance.transfer_discard",
    ]);
    assertEquals(f.children.map((child) => child.node_id), [9, 12, 10]);
});

Deno.test("refused SQLite abandonment preserves the restored source and destination for recovery", async () => {
    const f = new Fixture();
    f.kind = "instance.transfer_rollback";
    f.results.set("rollback-record", {
        recorded: true,
        seed_abandon_required: true,
        destination_node_id: 10,
        seed_abandon_node_id: 12,
    });
    f.results.set("transfer-abandon-sqlite", {
        error_code: "sqlite.seed_failed",
    });
    assertEquals((await lifecycle(f)).error_code, "sqlite.seed_failed");
    assertEquals(f.calls, [
        "rollback-record",
        "transfer-restore-source",
        "transfer-abandon-sqlite",
    ]);
});

Deno.test("a completed clone retry only returns its final receipt", async () => {
    const f = new Fixture();
    f.kind = "instance.clone";
    f.input.initial_completed = true;
    assertEquals((await lifecycle(f)).status, "succeeded");
    assertEquals(f.calls, ["lifecycle-finish"]);
});

Deno.test("source cleanup follows proven Route retirement after cutover", async () => {
    const f = new Fixture();
    f.kind = "instance.transfer";
    f.input.destination_node_id = 10;
    f.input.transfer_id = "proof-transfer";
    f.results.set("transfer-retire-source", {
        error_code: "instance.transfer_cleanup_incomplete",
    });
    assertEquals(
        (await lifecycle(f)).error_code,
        "instance.transfer_cleanup_incomplete",
    );
    assertEquals(f.calls.includes("transfer-cutover"), true);
    assertEquals(f.calls.includes("transfer-cleanup-source"), false);
});

Deno.test("an after-cutover retry only moves forward and never copies source again", async () => {
    const f = new Fixture();
    f.kind = "instance.transfer";
    f.input.destination_node_id = 10;
    f.input.transfer_id = "proof-transfer";
    f.input.initial_cutover = true;
    assertEquals((await lifecycle(f)).status, "succeeded");
    assertEquals(f.calls.includes("transfer-stop-source"), false);
    assertEquals(f.calls.includes("transfer-capture"), false);
    assertEquals(f.calls.includes("transfer-destination"), false);
    assertEquals(f.calls.includes("transfer-rollback-intent"), false);
    assertEquals(f.calls.at(-2), "transfer-finish");
});

Deno.test("an uncertain destination outcome does not discard source or destination", async () => {
    const f = new Fixture();
    f.kind = "instance.transfer";
    f.input.destination_node_id = 10;
    f.input.transfer_id = "proof-transfer";
    f.loseReply = "transfer-destination";
    await assertRejects(() => lifecycle(f), Error, "reply lost");
    assertEquals(f.calls.includes("transfer-rollback-intent"), false);
    assertEquals(f.calls.includes("transfer-cutover"), false);
    assertEquals((await lifecycle(f)).status, "succeeded");
    assertEquals(f.effects.get("transfer-destination"), 1);
});

Deno.test("clone installs captured Process and Schedule definitions stopped before Route projection", async () => {
    const f = new Fixture();
    f.kind = "instance.clone";
    f.input.sqlite_source_path = null;
    assertEquals((await lifecycle(f)).status, "succeeded");
    assertEquals(
        f.children.filter((c) => c.key.startsWith("clone-")).map((
            c,
        ) => [c.kind, c.input]),
        [
            ["instance.sync_environment", { instance_id: 501 }],
            ["process.install_instance_definitions", {
                instance_id: 501,
            }],
            ["schedule.install_instance_definitions", {
                instance_id: 501,
            }],
        ],
    );
    assertEquals(
        f.calls.indexOf("route-converge") > f.calls.indexOf("clone-schedules"),
        true,
    );
});

Deno.test("rollback without SQLite restores the source before discarding on the destination", async () => {
    const f = new Fixture();
    f.kind = "instance.transfer_rollback";
    f.results.set("rollback-record", {
        recorded: true,
        seed_abandon_required: false,
        destination_node_id: 10,
        seed_abandon_node_id: null,
    });
    assertEquals((await lifecycle(f)).status, "succeeded");
    assertEquals(f.children.map((child) => [child.key, child.node_id]), [[
        "transfer-restore-source",
        9,
    ], ["transfer-discard-destination", 10]]);
});

Deno.test("an offline destination cannot block rollback source restoration", async () => {
    const f = new Fixture();
    f.kind = "instance.transfer_rollback";
    f.results.set("rollback-record", {
        recorded: true,
        seed_abandon_required: false,
        destination_node_id: 10,
        seed_abandon_node_id: null,
    });
    f.results.set("transfer-discard-destination", {
        error_code: "node.unreachable",
    });

    assertEquals((await lifecycle(f)).error_code, "node.unreachable");
    assertEquals(f.calls, [
        "rollback-record",
        "transfer-restore-source",
        "transfer-discard-destination",
    ]);
    assertEquals(f.effects.get("transfer-restore-source"), 1);
});
