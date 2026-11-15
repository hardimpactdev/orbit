import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Args, behavior, register, renew } from "./mod.ts";
import { CreateArgs } from "../instance-dev-command/result.ts";

const gatewayToken = "private-gateway-sentinel";
const swampToken = "private-swamp-sentinel";
const environment = JSON.stringify({ environmentId: "env-7", label: "t3", serverVersion: "0.1.7" });
const session = (scopes: string[], status = 200) => ({
    status,
    body: JSON.stringify({ authenticated: true, scopes, expiresAt: "2026-12-10T10:00:00.000Z" }),
});

const commit = "c".repeat(40);
/** A ready clone's create arguments, as the workflow's `create_inputs` map hands them to `finish` and `clone-register`. */
const createArgs = (run: string, changes: Record<string, unknown> = {}) => ({
    intent: { instance_id: 7, already_active: false, name_converted_from: null, setup_steps: [], route: null,
        container: { clone: { source: "instance-1" } } },
    folder: run, t3_file: `${run}-t3.json`, checkout: null, database: "skipped", setup: "skipped", processes: [], route: "skipped",
    container: { launch: "succeeded", workspace: { status: "succeeded", outputs: { stdout: `orbit-commit=${commit}` } }, deploy: [],
        processes: "skipped", seed: "skipped", t3: "succeeded", register: "skipped", server: null },
    ...changes,
});

/** The run folder (a Route clone's input) and the t3.json that clone-session-copy put beside it. */
async function fixture(reply: unknown, run: (directory: string) => Promise<void>) {
    const directory = await Deno.makeTempDir({ prefix: "orbit-ansible-", dir: "/tmp" });
    try {
        await Deno.writeTextFile(`${directory}-t3.json`, JSON.stringify(reply));
        await run(directory);
    } finally {
        await Deno.remove(directory, { recursive: true }).catch(() => undefined);
        await Deno.remove(`${directory}-t3.json`).catch(() => undefined);
    }
}

const exists = (path: string) => Deno.stat(path).then(() => true, () => false);

function context() {
    const writes: { spec: string; name: string; data: unknown }[] = [];
    return {
        writes,
        modelId: "00000000-0000-4000-8000-000000000001",
        writeResource: (spec: string, name: string, data: unknown) => {
            writes.push({ spec, name, data });
            return Promise.resolve({});
        },
    };
}

const ready = {
    environment,
    gateway: { token: gatewayToken, ...session(["access:read", "access:write", "orchestration:read"]) },
    swamp: { token: swampToken, ...session(["orchestration:read"]) },
};

Deno.test("a checked reply stores both sessions as sensitive fields and returns the create's result with only their vault keys", () =>
    fixture(ready, async (run) => {
        const ctx = context();
        await register(createArgs(run), ctx);
        assertEquals(ctx.writes.slice(0, 2), [
            { spec: "session", name: "gateway-7", data: { token: gatewayToken } },
            { spec: "session", name: "swamp-7", data: { token: swampToken } },
        ]);
        const terminal = ctx.writes[2];
        assertEquals(terminal.spec, "terminal");
        const data = terminal.data as { stdout: string; ready: boolean; removed: boolean };
        assert(!data.stdout.includes(gatewayToken) && !data.stdout.includes(swampToken));
        const prefix = "nckrtl-instance-t3-00000000-0000-4000-8000-000000000001-register-session-";
        // The same result finish builds for a ready clone: the registered server is its `t3`.
        assertEquals([data.ready, data.removed], [true, true]);
        assertEquals(JSON.parse(data.stdout), { instance_id: 7, ready: true, name_converted_from: null, commit, t3: {
            environment_id: "env-7",
            server_version: "0.1.7",
            expires_at: "2026-12-10T10:00:00.000Z",
            gateway_session: { vault: "orbit-control-inputs", key: `${prefix}gateway-7-token` },
            swamp_session: { vault: "orbit-control-inputs", key: `${prefix}swamp-7-token` },
        } });
        // The copy of the sessions and the ready create's run folder are gone.
        assertEquals([await exists(`${run}-t3.json`), await exists(run)], [false, false]);
    }));

Deno.test("a migrate's registration reads the run folder's t3.json and returns only the server with its vault keys", async () => {
    const run = await Deno.makeTempDir({ prefix: "orbit-ansible-", dir: "/tmp" });
    try {
        await Deno.writeTextFile(`${run}/t3.json`, JSON.stringify(ready));
        const ctx = context();
        await register({ instance_id: 7, folder: run }, ctx);
        assertEquals(ctx.writes.map((write) => [write.spec, write.name]), [["session", "gateway-7"], ["session", "swamp-7"], ["terminal", "terminal"]]);
        const stdout = (ctx.writes[2].data as { stdout: string }).stdout;
        assert(!stdout.includes(gatewayToken) && !stdout.includes(swampToken));
        assertEquals(Object.keys(JSON.parse(stdout)), ["environment_id", "server_version", "expires_at", "gateway_session", "swamp_session"]);
        await assertRejects(() => register({ instance_id: 7, folder: `${run}-none` }, context()), Error, "conn.response_invalid");
    } finally {
        await Deno.remove(run, { recursive: true });
    }
});

Deno.test("a registration whose result is not ready keeps the run folder for finish and the container stop", () =>
    fixture(ready, async (run) => {
        const ctx = context();
        // A workspace without its commit line is not finished: the result names it, and finish runs after this step.
        await register(createArgs(run, { container: { launch: "succeeded", workspace: { status: "succeeded", outputs: { stdout: "" } },
            deploy: [], processes: "skipped", seed: "skipped", t3: "succeeded", register: "skipped", server: null } }), ctx);
        const data = ctx.writes[2].data as { ready: boolean; removed: boolean };
        assertEquals([data.ready, data.removed, await exists(run), await exists(`${run}-t3.json`)], [false, false, true, false]);
    }));

Deno.test("each failed check refuses with the code of POST /conn/environments, stores nothing and removes the copy", async () => {
    const cases: [unknown, string][] = [
        [{ environment, gateway: { token: "g", ...session(["access:read"]) }, swamp: { token: "s", ...session([]) } }, "conn.admin_scope_missing"],
        [{ environment, gateway: { token: "g", status: 401, body: "" }, swamp: { token: "s", ...session([]) } }, "conn.session_rejected"],
        [{ environment, gateway: { token: "g", ...session(["access:read", "access:write"]) }, swamp: { token: "s", status: 403, body: "no" } }, "conn.session_rejected"],
        [{ environment: "<html>", gateway: { token: "g", ...session(["access:read", "access:write"]) }, swamp: { token: "s", ...session([]) } }, "conn.response_invalid"],
        [{ environment, gateway: { token: "g", ...session(["access:read", "access:write"], 403) }, swamp: { token: "s", ...session([]) } }, "conn.session_rejected"],
        [{ environment, gateway: { token: "same", ...session(["access:read", "access:write"]) }, swamp: { token: "same", ...session([]) } }, "conn.response_invalid"],
    ];
    for (const [reply, code] of cases) {
        await fixture(reply, async (run) => {
            const ctx = context();
            await assertRejects(() => register(createArgs(run), ctx), Error, code);
            assertEquals([ctx.writes, await exists(`${run}-t3.json`)], [[], false]);
        });
    }
});

const renewToken = "private-renewed-sentinel";

/** A stand-in for the container's T3 server at its recorded URL. */
async function t3Server(served: string, accepts: (authorization: string | null) => Response, run: (url: string) => Promise<void>) {
    const server = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen: () => {} }, (request) => {
        const path = new URL(request.url).pathname;
        if (path === "/.well-known/t3/environment") return new Response(served);
        if (path === "/api/auth/session") return accepts(request.headers.get("authorization"));
        return new Response("", { status: 404 });
    });
    try {
        await run(`http://127.0.0.1:${server.addr.port}`);
    } finally {
        await server.shutdown();
    }
}

async function renewFolder(issued: unknown | null) {
    const folder = await Deno.makeTempDir({ prefix: "orbit-ansible-", dir: "/tmp" });
    if (issued !== null) await Deno.writeTextFile(`${folder}/issued.json`, JSON.stringify(issued));
    return folder;
}

async function gone(path: string) {
    await assertRejects(() => Deno.stat(path), Deno.errors.NotFound);
}

const authenticated = (authorization: string | null) =>
    authorization === `Bearer ${renewToken}`
        ? Response.json({ authenticated: true, scopes: ["access:read", "access:write"], expiresAt: "2027-01-12T10:00:00.000Z" })
        : new Response("", { status: 401 });

Deno.test("a renewal proves the session over the recorded URL, stores it, reports only its vault key, and removes the copy", () =>
    t3Server(environment, authenticated, async (url) => {
        const folder = await renewFolder({ sessionId: "s-1", token: renewToken });
        const ctx = context();
        await renew({ instance_id: 7, folder, url, environment_id: "env-7" }, ctx);
        assertEquals(ctx.writes[0], { spec: "session", name: "gateway-7", data: { token: renewToken } });
        const stdout = (ctx.writes[1].data as { stdout: string }).stdout;
        assert(!stdout.includes(renewToken));
        assertEquals(JSON.parse(stdout), {
            outcome: "succeeded",
            environment_id: "env-7",
            server_version: "0.1.7",
            expires_at: "2027-01-12T10:00:00.000Z",
            gateway_session: {
                vault: "orbit-control-inputs",
                key: "nckrtl-instance-t3-00000000-0000-4000-8000-000000000001-renew-session-gateway-7-token",
            },
        });
        await gone(folder);
    }));

for (
    const [name, served, accepts, code] of [
        ["another environment", JSON.stringify({ environmentId: "env-default", serverVersion: "0.1.7" }), authenticated, "conn.environment_mismatch"],
        ["a refused session", environment, () => new Response("", { status: 401 }), "conn.session_rejected"],
        ["a session without admin scopes", environment, () => Response.json({ authenticated: true, scopes: ["orchestration:read"], expiresAt: "2027-01-12T10:00:00.000Z" }), "conn.admin_scope_missing"],
    ] as const
) {
    Deno.test(`a renewal that meets ${name} reports ${code}, stores nothing, and removes the copy`, () =>
        t3Server(served, accepts, async (url) => {
            const folder = await renewFolder({ token: renewToken });
            const ctx = context();
            await renew({ instance_id: 7, folder, url, environment_id: "env-7" }, ctx);
            assertEquals(ctx.writes.map((write) => write.spec), ["terminal"]);
            assertEquals(JSON.parse((ctx.writes[0].data as { stdout: string }).stdout), { outcome: "failed", error_code: code });
            await gone(folder);
        }));
}

Deno.test("a renewal whose server does not answer reports conn.server_unreachable and removes the copy", async () => {
    const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
    const port = (listener.addr as Deno.NetAddr).port;
    listener.close();
    const folder = await renewFolder({ token: renewToken });
    const ctx = context();
    await renew({ instance_id: 7, folder, url: `http://127.0.0.1:${port}`, environment_id: "env-7" }, ctx);
    assertEquals(JSON.parse((ctx.writes[0].data as { stdout: string }).stdout), { outcome: "failed", error_code: "conn.server_unreachable" });
    await gone(folder);
});

Deno.test("a renewal without a readable copy reports conn.response_invalid and removes the folder", async () => {
    const folder = await renewFolder(null);
    const ctx = context();
    await renew({ instance_id: 7, folder, url: "http://127.0.0.1:9", environment_id: "env-7" }, ctx);
    assertEquals(JSON.parse((ctx.writes[0].data as { stdout: string }).stdout), { outcome: "failed", error_code: "conn.response_invalid" });
    await gone(folder);
});

// Every workflow step that calls this behavior names a method it has and passes exactly the arguments that method requires: the
// migrate's registration keeps `{instance_id, folder}` while the create's takes the create's result inputs (both through `register`, whose method name keys the sessions).
Deno.test("each workflow step of @nckrtl/instance-t3 passes the arguments of the method it names", async () => {
    type Step = { name: string; task?: { behaviorType?: string; methodName?: string; inputs?: Record<string, unknown> } };
    const folder = new URL("../../../../workflows/", import.meta.url);
    let seen = 0;
    for await (const file of Deno.readDir(folder)) {
        if (!file.name.endsWith(".yaml")) continue;
        const flow = parse(await Deno.readTextFile(new URL(file.name, folder))) as { jobs?: { steps: Step[] }[] };
        for (const step of (flow.jobs ?? []).flatMap((job) => job.steps)) {
            if (step.task?.behaviorType !== "@nckrtl/instance-t3") continue;
            seen++;
            const method = (behavior.methods as Record<string, { arguments: { shape: Record<string, { isOptional(): boolean }> } }>)[step.task.methodName!];
            assert(method !== undefined, `${file.name} ${step.name}: no method ${step.task.methodName}`);
            const given = Object.keys(step.task.inputs ?? {}).sort();
            // `register` takes either shape whole (RegisterArgs): the create's when the step passes `intent`, else the migrate's.
            const shape = (step.task.methodName === "register" ? (given.includes("intent") ? CreateArgs : Args) : method.arguments).shape as
                Record<string, { isOptional(): boolean }>;
            const required = Object.entries(shape).filter(([, field]) => !field.isOptional()).map(([key]) => key);
            for (const key of required) assert(given.includes(key), `${file.name} ${step.name}: missing ${key}`);
            for (const key of given) assert(key in shape, `${file.name} ${step.name}: unknown ${key}`);
        }
    }
    assert(seen >= 3, `${seen} steps`);
});
