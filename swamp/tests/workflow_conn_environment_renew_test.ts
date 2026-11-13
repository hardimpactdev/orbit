import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = {
    name: string;
    guard?: string;
    task: { expr?: string; inputs: { argv?: unknown; env?: Record<string, string> } };
};
const flow = parse(
    Deno.readTextFileSync(new URL("../workflows/workflow-operation-conn-environment-renew.yaml", import.meta.url)),
) as { jobs: { name: string; steps: Step[] }[] };
const step = (name: string) => flow.jobs.flatMap((job) => job.steps).find((candidate) => candidate.name === name)!;
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const evaluate = (expression: string, steps: Record<string, unknown>) =>
    cel.evaluate(expression.trim().replace(/^\$\{\{/, "").replace(/\}\}$/, "").trim(), { inputs: { node_id: 9 }, steps, run: { id: "run-1" } });

const issued = (stdout: string, status = "succeeded") => ({ "Issue the Gateway session": { status, outputs: { stdout } } });
const stored = '{"outcome":"succeeded","environment_id":"env-7","gateway_session":{"vault":"orbit-control-inputs","key":"k"}}';
const report = (steps: Record<string, unknown>) => JSON.parse(evaluate(step("Report the renewal").task.inputs.env!.ORBIT_RENEW_REPORT, steps) as string);

Deno.test("the report carries the stored session's report, or the code of the step that refused", () => {
    assertEquals(report({ ...issued('{"outcome":"issued"}\n'), "Store the session": { status: "succeeded", outputs: { stdout: stored } } }).outcome, "succeeded");
    assertEquals(report({ ...issued('{"outcome":"failed","error_code":"conn.environment_stopped"}\n') }), { outcome: "failed", error_code: "conn.environment_stopped" });
    assertEquals(report({ ...issued('{"outcome":"issued"}\n'), "Store the session": { status: "failed", outputs: {} } }), { outcome: "failed", error_code: "conn.renewal_incomplete" });
    assertEquals(report(issued("", "failed")), { outcome: "failed", error_code: "conn.node_unreachable" });
    assertEquals(report({}), { outcome: "failed", error_code: "conn.node_unreachable" });
});

Deno.test("the run succeeds only on a stored session", () => {
    const confirm = (stdout: string) => cel.evaluate(step("Confirm renewal").task.expr!, { steps: { "Report the renewal": { outputs: { stdout } } } });
    assertEquals(confirm(stored), true);
    assertEquals(confirm('{"outcome":"failed","error_code":"conn.environment_stopped"}'), false);
});

Deno.test("the copy runs only for an issued session, and the Node copy is removed whenever the Node step ran", () => {
    assertEquals(evaluate(step("Copy the session").guard!, issued('{"outcome":"issued"}\n')), false);
    assertEquals(evaluate(step("Copy the session").guard!, issued('{"outcome":"failed","error_code":"conn.environment_stopped"}\n')), true);
    for (const status of ["succeeded", "failed"]) assertEquals(evaluate(step("Remove the Node copy").guard!, issued("", status)), false);
    assertEquals(evaluate(step("Remove the Node copy").guard!, {}), true);
    assertEquals(step("Remove the local copy").guard, undefined);
});

/** Runs the Node step's script with a stand-in `incus` that reports `state` and issues a session unless `issue` is false. */
async function node(state: string, issue: boolean) {
    const bin = await Deno.makeTempDir();
    const folder = `${await Deno.makeTempDir()}/renew`;
    await Deno.writeTextFile(
        `${bin}/incus`,
        `#!/bin/sh\ncase "$1" in list) printf '%s\\n' "${state}" ;; *) ${issue ? `echo '{"token":"private-node-sentinel"}'` : "exit 1"} ;; esac\n`,
        { mode: 0o755 },
    );
    const argv = step("Issue the Gateway session").task.inputs.argv as string[];
    const user = new TextDecoder().decode((await new Deno.Command("id", { args: ["-un"] }).output()).stdout).trim();
    const output = await new Deno.Command("sh", {
        args: ["-c", argv[4], "sh", "orbit-instances", "instance-7", folder, "/opt/orbit-agent/current/agent/bin/t3", user],
        env: { PATH: `${bin}:${Deno.env.get("PATH")}` },
    }).output();
    const stdout = new TextDecoder().decode(output.stdout);
    assert(!stdout.includes("private-node-sentinel"));
    return { stdout: JSON.parse(stdout), folder };
}

Deno.test("the Node step refuses a stopped container before it issues anything", async () => {
    const { stdout, folder } = await node("STOPPED", true);
    assertEquals(stdout, { outcome: "failed", error_code: "conn.environment_stopped" });
    await assertRejects(() => Deno.stat(folder), Deno.errors.NotFound);
    assertEquals((await node("", true)).stdout.error_code, "conn.environment_missing");
});

Deno.test("the Node step writes the issued session to a private file and prints only its outcome", async () => {
    const { stdout, folder } = await node("RUNNING", true);
    assertEquals(stdout, { outcome: "issued" });
    assertEquals((await Deno.stat(`${folder}/issued.json`)).mode! & 0o777, 0o600);
    assertEquals((await Deno.stat(folder)).mode! & 0o777, 0o700);
});

Deno.test("the Node step removes its folder when the issue fails", async () => {
    const { stdout, folder } = await node("RUNNING", false);
    assertEquals(stdout, { outcome: "failed", error_code: "conn.session_issue_failed" });
    await assertRejects(() => Deno.stat(folder), Deno.errors.NotFound);
});

Deno.test("the Node step removes copies that a cancelled run left more than ten minutes ago, and keeps newer ones", async () => {
    const old = `/tmp/orbit-conn-renew-fixture-old-${crypto.randomUUID()}`;
    const recent = `/tmp/orbit-conn-renew-fixture-new-${crypto.randomUUID()}`;
    for (const path of [old, recent]) await Deno.mkdir(path);
    await Deno.utime(old, new Date(Date.now() - 20 * 60_000), new Date(Date.now() - 20 * 60_000));
    try {
        await node("RUNNING", true);
        await assertRejects(() => Deno.stat(old), Deno.errors.NotFound);
        assert((await Deno.stat(recent)).isDirectory);
    } finally {
        for (const path of [old, recent]) await Deno.remove(path, { recursive: true }).catch(() => {});
    }
});

Deno.test("each Instance's renewal runs under its own SSH model, so two servers on one Node never wait on each other", () => {
    for (const name of ["Issue the Gateway session", "Copy the session", "Remove the Node copy"]) {
        const task = step(name).task as unknown as { behaviorName: string };
        assertEquals(task.behaviorName, '${{ ("conn-renew-" + string(inputs.intent.instance_id)).split(".").join("-") }}');
    }
});
