import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
    directOperation,
    DirectOperationCancelled,
    GatewayOperation,
    Globals,
} from "./direct.ts";

const args = {
    operation_id: 71,
    kind: "sample.apply",
    node_id: null,
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
function fixture() {
    const projected: Record<string, unknown>[] = [];
    let effects = 0;
    const gateway = {
        read: () =>
            Promise.resolve({
                id: 71,
                kind: args.kind,
                node_id: null,
                status: "queued",
                result: null,
                cancel_requested: false,
            }),
        project: (data: Record<string, unknown>) => {
            projected.push(data);
            return Promise.resolve(null);
        },
    };
    return {
        gateway,
        projected,
        action: () => {
            effects++;
            return Promise.resolve({ changed: true });
        },
        effects: () => effects,
    };
}
Deno.test("shared template supports a typed local action with no Gateway effect call", async () => {
    const f = fixture();
    assertEquals(await directOperation(args, f.gateway, f.action, (r) => r), {
        status: "succeeded",
    });
    assertEquals(f.effects(), 1);
    assertEquals(f.projected.map((p) => p.status), ["running", "succeeded"]);
    assertEquals(f.projected[1].result, { changed: true });
});
Deno.test("shared template gives a local Node action only the admitted safe input", async () => {
    const f = fixture();
    const read = f.gateway.read;
    const seen: unknown[] = [];
    await directOperation(args, {
        ...f.gateway,
        read: async () => ({ ...await read(), input: { instance_id: 42 } }),
    }, (input) => {
        seen.push(input);
        return Promise.resolve({ ready: true });
    }, (r) => r);
    assertEquals(seen, [{ instance_id: 42 }]);
});
Deno.test("shared template refuses foreign operation identity before action or projection", async () => {
    for (
        const change of [{ id: 72 }, { kind: "foreign.apply" }, { node_id: 9 }]
    ) {
        const f = fixture();
        const read = f.gateway.read;
        const gateway = {
            ...f.gateway,
            read: async () => ({ ...await read(), ...change }),
        };
        await assertRejects(
            () => directOperation(args, gateway, f.action, (r) => r),
            Error,
            "does not match",
        );
        assertEquals(f.effects(), 0);
        assertEquals(f.projected, []);
    }
});
Deno.test("shared template fails natively without a failed callback after an unexpected action error", async () => {
    const f = fixture();
    let attempts = 0;
    const error = await assertRejects(
        () =>
            directOperation(args, f.gateway, () => {
                attempts++;
                throw new Error("private external result");
            }, (r) => r),
        Error,
    );
    assertEquals(error.message, "operation.native_outcome_unknown");
    assertEquals(attempts, 1);
    assertEquals(f.projected.map((p) => p.status), ["running"]);
    assertEquals(
        JSON.stringify(f.projected).includes("private external result"),
        false,
    );
});
Deno.test("shared template checks cancellation before a local Node action", async () => {
    const f = fixture();
    const controller = new AbortController();
    controller.abort();
    assertEquals(
        await directOperation(
            args,
            f.gateway,
            f.action,
            (r) => r,
            controller.signal,
        ),
        { status: "cancelled" },
    );
    assertEquals(f.effects(), 0);
});
Deno.test("shared template projects safe family refusals and rejects free-form diagnostics", async () => {
    const f = fixture();
    await assertRejects(
        () =>
            directOperation(
                args,
                f.gateway,
                () => Promise.resolve({ error_code: "sample.refused" }),
                (r) => r,
            ),
        Error,
        "sample.refused",
    );
    assertEquals(f.projected[1].error_code, "sample.refused");
    const g = fixture();
    await assertRejects(() =>
        directOperation(
            args,
            g.gateway,
            () => Promise.resolve({ error_code: "x".repeat(101) }),
            (r) => r,
        )
    );
    assertEquals(g.projected.map((p) => p.status), ["running"]);
});
Deno.test("shared gateway client binds every projection to the workflow run and hides remote diagnostics", async () => {
    const calls: unknown[] = [];
    const gateway = new GatewayOperation(
        (_m, path, body) => {
            calls.push({ path, body });
            return Promise.resolve({ status: 200, body: { data: {} } });
        },
        args,
    );
    await gateway.project({ status: "running", run_id: "foreign" });
    assertEquals(calls, [{
        path: "/api/v1/direct-operations/71/result",
        body: { status: "running", run_id: args.run_id },
    }]);
    const bad = new GatewayOperation(
        () =>
            Promise.resolve({
                status: 503,
                body: { error: "private credential" },
            }),
        args,
    );
    await assertRejects(
        () => bad.read(),
        Error,
        "Direct Operation request did not succeed",
    );
});

Deno.test("a bound legacy read proceeds once before its original projections and effect", async () => {
    const f = fixture();
    const calls: string[] = [];
    const gateway = new GatewayOperation(async (method, path, body) => {
        calls.push(method);
        assertEquals(path.startsWith("/api/v1/direct-operations/71"), true);
        if (method === "POST") {
            await f.gateway.project(body as Record<string, unknown>);
        }
        return { status: 200, body: { data: await f.gateway.read() } };
    }, args);

    await directOperation(args, gateway, f.action, (r) => r);

    assertEquals(calls, ["GET", "POST", "POST"]);
    assertEquals(f.effects(), 1);
});

Deno.test("an unbound or refused legacy read fails once before any effect or projection", async () => {
    for (
        const [status, code] of [
            [409, "operation.run_unbound"],
            [409, "operation.run_mismatch"],
            [403, "node.access_denied"],
            [503, "operation.run_unbound"],
        ] as const
    ) {
        const f = fixture();
        const calls: string[] = [];
        const gateway = new GatewayOperation((method) => {
            calls.push(method);
            return Promise.resolve({ status, body: { error: { code } } });
        }, args);
        await assertRejects(
            () => directOperation(args, gateway, f.action, (r) => r),
            Error,
            "request did not succeed",
        );
        assertEquals(calls, ["GET"]);
        assertEquals(f.effects(), 0);
        assertEquals(f.projected, []);
    }
});

Deno.test("a pre-cancelled bound legacy read records cancellation without an effect", async () => {
    const f = fixture();
    let reads = 0;
    const gateway = new GatewayOperation(() => {
        reads++;
        return Promise.resolve({ status: 200, body: { data: {} } });
    }, args);
    const signal = AbortSignal.abort();
    assertEquals(await gateway.read(signal), {});
    assertEquals(reads, 1);
    assertEquals(
        await directOperation(args, f.gateway, f.action, (r) => r, signal),
        { status: "cancelled" },
    );
    assertEquals(f.effects(), 0);
});

Deno.test("cancellation during the single legacy read refuses before an effect or projection", async () => {
    const f = fixture();
    const controller = new AbortController();
    let reads = 0;
    const gateway = new GatewayOperation(() => {
        reads++;
        controller.abort(new Error("native cancellation"));
        return Promise.resolve({ status: 200, body: { data: {} } });
    }, args);
    await assertRejects(
        () =>
            directOperation(
                args,
                gateway,
                f.action,
                (r) => r,
                controller.signal,
            ),
        Error,
        "native cancellation",
    );
    assertEquals(reads, 1);
    assertEquals(f.effects(), 0);
    assertEquals(f.projected, []);
});

Deno.test("a read-only pending wait observes Gateway cancellation before accepting an applied result", async () => {
    const f = fixture();
    let cancelRequested = false;
    let status = "pending";
    const gateway = {
        ...f.gateway,
        read: async () => ({
            ...await f.gateway.read(),
            cancel_requested: cancelRequested,
        }),
    };
    const result = await directOperation(args, gateway, async () => {
        assertEquals(status, "pending");
        cancelRequested = true;
        status = "applied";
        const current = await gateway.read();
        if (current.cancel_requested) {
            throw new DirectOperationCancelled({ effectRunning: false });
        }
        return { status };
    }, (r) => r);

    assertEquals(result, { status: "cancelled" });
    assertEquals(f.effects(), 0);
    assertEquals(f.projected.map((p) => p.status), ["running", "cancelled"]);
    assertEquals(f.projected[1].result, {});
    assertEquals(f.projected[1].error_code, null);
});

Deno.test("only explicit no-effect cancellation can end a running direct action as cancelled", async () => {
    for (
        const error of [
            new Error("uncertain root mutation"),
            new DOMException("native abort during mutation", "AbortError"),
        ]
    ) {
        const f = fixture();
        await assertRejects(() =>
            directOperation(args, f.gateway, () => {
                throw error;
            }, (r) => r)
        );
        assertEquals(f.projected.map((p) => p.status), ["running"]);
    }
    const f = fixture();
    const controller = new AbortController();
    assertEquals(
        await directOperation(
            args,
            f.gateway,
            () => {
                controller.abort();
                return Promise.resolve({ applied: true });
            },
            (r) => r,
            controller.signal,
        ),
        { status: "succeeded" },
    );
    assertEquals(f.projected[1].result, { applied: true });
});

Deno.test("Gateway calls use the public CA installed for every Node worker", () => {
    assertEquals(Globals.parse({}), {
        gatewayUrl: "https://gateway.orbit",
        caFile: "/usr/local/share/ca-certificates/orbit-root.crt",
    });
});

Deno.test("an explicit model CA setting remains an explicit override", () => {
    const caFile = "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt";
    assertEquals(Globals.parse({ caFile }).caFile, caFile);
});

Deno.test("Gateway TLS requires a nonempty explicit or default CA path", () => {
    assertEquals(Globals.safeParse({ caFile: "" }).success, false);
    assertEquals(Globals.parse({}).caFile.length > 0, true);
    assertEquals(
        Globals.parse({
            caFile: "/usr/local/share/ca-certificates/orbit-root.crt",
        })
            .caFile,
        "/usr/local/share/ca-certificates/orbit-root.crt",
    );
});
