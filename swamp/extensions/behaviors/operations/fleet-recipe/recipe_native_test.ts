import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { type History } from "../../../../tests/_support/native_harness.ts";
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
test("optional runtime versions and native children are not dropped", async (f) => {
    await f.save({ fail: "native-child node-route-residue" });
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
        ]
    ) assertEquals(steps(history)[name].status, "succeeded", name);
    assertEquals(
        f.calls().filter((c) => c === "native-child node-php-runtime").length,
        2,
    );
    assert(f.calls().includes("native-child node-private-dns"));
    assert(f.calls().includes("native-child node-route-residue"));
    assertEquals(steps(history)["Clear Route residue"].status, "failed");
    assertEquals(result(history).footprint.artifacts.find((a: { name: string }) => a.name === "route-residue"), {
        name: "route-residue", status: "skipped", reason: "route_residue_cleanup_failed",
    });
    assert(f.calls().includes("delivery"));
});
test("uncertain runtime native resume keeps completed package step", async (f) => {
    await f.save({ fail: "javascript", noServices: true });
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
