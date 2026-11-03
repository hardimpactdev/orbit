import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { type History, ROOT } from "../../../../tests/_support/native_harness.ts";
import { recipeFixture } from "./native_fixture.ts";

const steps = (history: History) =>
    Object.fromEntries(
        history.jobs.flatMap((j) => j.steps).map((s) => [s.name, s]),
    );
const result = (history: History) =>
    JSON.parse(steps(history).result.outputs!.stdout as string);
const test = (
    name: string,
    body: (f: Awaited<ReturnType<typeof recipeFixture>>) => Promise<void>,
) => Deno.test(name, async () => {
    const f = await recipeFixture();
    await using _repo = f.repo;
    await body(f);
});

test("complete recipe footprint and delivery in one native root", async (f) => {
    const { history } = await f.start();
    const calls = f.calls();
    for (
        const [before, after] of [
            ["packages", "browser"],
            ["browser", "javascript"],
            ["javascript", "services"],
            ["verify", "delivery"],
            ["native-child node-cli-install", "verify"],
        ]
    ) {
        assert(calls.includes(before) && calls.includes(after));
        assert(calls.indexOf(before) < calls.indexOf(after));
    }
    const value = result(history);
    assert(value.footprint.footprint_proven);
    assertEquals(value.identity, f.intent.identity);
    assertEquals(value.footprint.artifacts.length, 2);
});
test("unchanged node with no service units still finishes", async (f) => {
    await f.save({ noServices: true });
    const { history } = await f.start();
    assert(f.calls().includes("delivery"));
    assertEquals(history.status, "succeeded");
});
test("Caddy render refusal keeps the last good publication", async (f) => {
    f.intent.footprint.artifacts.push({
        name: "caddy",
        digest: "c".repeat(64),
        required: true,
        repair: false,
    });
    f.intent.footprint.payloads.caddy = {
        error_code: "caddy.render_failed",
        stage: "render",
    };
    const { history } = await f.start();
    assertEquals(
        result(history).footprint.artifacts.find((a: { name: string }) =>
            a.name === "caddy"
        ),
        { name: "caddy", status: "skipped", reason: "caddy_render_refused" },
    );
    assert(!f.calls().includes("caddy-validation"));
});
test("invalid Caddy child stops delivery before publication", async (f) => {
    await f.save({ caddyInvalid: true });
    f.intent.footprint.artifacts.push({
        name: "caddy",
        digest: "c".repeat(64),
        required: true,
        repair: false,
    });
    f.intent.footprint.payloads.caddy = {
        content: "public candidate",
        version: "b".repeat(32),
        listen_addresses: [],
        unserved: false,
    };
    const { history } = await f.start(false);
    assertEquals(steps(history)["Publish Caddy sites"].status, "failed");
    assert(!f.calls().includes("delivery"));
});
test("optional runtime versions and native children are not dropped", async (f) => {
    const fp = f.intent.footprint;
    for (
        const name of ["caddy", "php-runtime", "private-dns", "route-residue"]
    ) {
        fp.artifacts.push({
            name,
            digest: "c".repeat(64),
            required: true,
            repair: false,
        });
    }
    fp.payloads.caddy = {
        content: "public candidate",
        version: "b".repeat(32),
        listen_addresses: [],
        unserved: false,
    };
    fp.payloads["php-runtime"] = {
        configurations: [{ version: "8.4" }, { version: "8.5" }],
    };
    fp.payloads["private-dns"] = {
        listener: {},
        publication: { records: "public records", catalog: "{}" },
    };
    fp.payloads["route-residue"] = {
        rows: [{ steps: ["caddy"] }],
        caddy: fp.payloads.caddy,
        php: { "8.5": "user = __orbit_converge_group__" },
        certificates: ["route-1"],
        lan: { node_id: 9, rules: [] },
        ingress_commands: null,
    };
    f.intent.php = { versions: { "8.5": {} } };
    const { history } = await f.start();
    for (
        const name of [
            "php_plan-0",
            "php_build-0",
            "Configure PHP-FPM-8.4",
            "Configure PHP-FPM-8.5",
            "Publish Caddy sites",
            "Install private DNS",
            "Clear Route residue",
        ]
    ) assertEquals(steps(history)[name].status, "succeeded", name);
    assertEquals(
        f.calls().filter((c) => c === "native-child node-php-runtime").length,
        2,
    );
    assert(f.calls().includes("native-child node-private-dns"));
    assert(f.calls().includes("native-child node-route-residue"));
});
test("private DNS validation refusal keeps live files untouched", async (f) => {
    await f.save({ dnsValidationInvalid: true });
    f.intent.footprint.artifacts.push({
        name: "private-dns",
        digest: "c".repeat(64),
        required: true,
        repair: false,
    });
    f.intent.footprint.payloads["private-dns"] = {
        listener: {},
        publication: { records: "public records", catalog: "{}" },
    };
    const { history } = await f.start(false);
    assertEquals(steps(history)["Install private DNS"].status, "failed");
    assert(!f.calls().includes("node.private-dns-restore"));
});
test("private DNS failed catalog proof stops delivery", async (f) => {
    await f.save({ dnsInvalid: true });
    f.intent.footprint.artifacts.push({
        name: "private-dns",
        digest: "c".repeat(64),
        required: true,
        repair: false,
    });
    f.intent.footprint.payloads["private-dns"] = {
        listener: {},
        publication: { records: "public records", catalog: "{}" },
    };
    await f.start(false);
    assert(f.calls().includes("native-child node-private-dns"));
    assert(!f.calls().includes("delivery"));
    assert(!f.calls().includes("node.private-dns-restore"));
});
test("Route residue cleanup failure keeps delivery best effort", async (f) => {
    await f.save({ fail: "native-child node-route-residue" });
    const fp = f.intent.footprint;
    fp.artifacts.push({
        name: "route-residue",
        digest: "c".repeat(64),
        required: true,
        repair: false,
    });
    fp.payloads["route-residue"] = { rows: [] };
    const { history } = await f.start();
    assertEquals(steps(history)["Clear Route residue"].status, "failed");
    assertEquals(
        result(history).footprint.artifacts.find((a: { name: string }) =>
            a.name === "route-residue"
        ),
        {
            name: "route-residue",
            status: "skipped",
            reason: "route_residue_cleanup_failed",
        },
    );
    assert(f.calls().includes("delivery"));
});
test("refusal before first host effect", async (f) => {
    f.intent.footprint.refusal = "node.footprint_failed";
    await f.start(false);
    assertEquals(f.calls(), []);
});
test("uncertain runtime native resume keeps completed package step", async (f) => {
    await f.save({ fail: "javascript" });
    const { run } = await f.start(false);
    assert(!f.calls().includes("delivery"));
    assertEquals(f.calls().filter((c) => c === "packages").length, 1);
    await f.repo.cli([
        "workflow",
        "resume",
        "operation-fleet-recipe-direct",
        "--run",
        run.id,
        "--from",
        "javascript_command",
    ]);
    assertEquals(f.calls().filter((c) => c === "packages").length, 1);
    assertEquals(f.calls().filter((c) => c === "javascript").length, 2);
    const history = JSON.parse(
        (await f.repo.cli(["workflow", "history", "get", run.id])).stdout,
    );
    assertEquals(history.status, "succeeded");
});
Deno.test("all node targets use 209 with their saved host keys", async () => {
    const text = await Deno.readTextFile(
        `${ROOT}/workflows/workflow-operation-fleet-recipe-direct.yaml`,
    );
    const source = parse(text) as {
        jobs: {
            steps: {
                labels: unknown;
                task?: {
                    globalArgs?: {
                        hosts?: { transport: { hostKeys: string } }[];
                    };
                };
            }[];
        }[];
    };
    for (const _node of [4, 8, 9, 14, 45, 202, 206, 207, 208, 209, 210, 212]) {
        for (const job of source.jobs) {
            for (const step of job.steps) {
                assertEquals(step.labels, { role: "swamp" });
                const hosts = step.task?.globalArgs?.hosts;
                if (hosts?.length) {
                    assert(
                        hosts[0].transport.hostKeys.includes(
                            "inputs.intent.ssh.target.host_keys",
                        ),
                    );
                }
            }
        }
    }
    assert(!text.includes("/api/v1/direct-operations"));
    assert(!text.includes('orbit-node-" +'));
});
