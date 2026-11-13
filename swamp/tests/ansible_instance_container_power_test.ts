/** The asleep marker parts of a container's sleep and wake, run by real Ansible against the Incus fixture, and the shipped step expressions. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = { name: string; dependsOn?: { step: string }[]; task: { behaviorName?: string; inputs: { run?: string; env?: Record<string, string> } } };
const steps = (kind: string) =>
    (parse(Deno.readTextFileSync(new URL(`../workflows/workflow-operation-instance-${kind}.yaml`, import.meta.url))) as { jobs: { steps: Step[] }[] })
        .jobs.flatMap((job) => job.steps);
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const shipped = new URL("../ansible/", import.meta.url).pathname.replace(/\/$/, "");
const runId = "0f0e0d0c-0b0a-4908-8706-050403020100";

Deno.test("each sleep and wake step runs its charted part with the shipped Ansible settings", () => {
    const context = { inputs: { node_id: 9, intent: {} }, steps: { input: { status: "succeeded", outputs: { assets: shipped } } }, run: { id: runId } };
    const evaluate = (value: string) => value.startsWith("${{") ? String(cel.evaluate(value.slice(3, -2).trim(), context)) : value;
    for (const [kind, parts] of [["sleep", [["Mark the Route asleep", "--tags sleep", "instance-container-power.yml"], ["Stop the container", "--tags stop", "instance-container.yml"]]],
        ["wake", [["Start the container", "--tags start", "instance-container.yml"], ["Mark the Instance awake", "--tags wake", "instance-container-power.yml"]]]] as const) {
        const all = steps(kind);
        assertEquals(all.map((step) => step.name), ["input", parts[0][0], parts[1][0], "result", "cleanup"]);
        assertEquals(all[2].dependsOn?.[0].step, parts[0][0]);
        for (const [name, tags, playbook] of parts) {
            const step = all.find((candidate) => candidate.name === name)!;
            assert(step.task.inputs.run!.includes(`${tags} "$ORBIT_ANSIBLE/${playbook}"`), name);
            const env = Object.fromEntries(Object.entries(step.task.inputs.env!).map(([key, value]) => [key, evaluate(value)]));
            assertEquals(env.ANSIBLE_CONFIG, `${shipped}/ansible.cfg`);
            assertEquals(env.ORBIT_ANSIBLE, shipped);
            assertEquals(env.ORBIT_RUN_ID, runId);
            assert(Deno.statSync(`${shipped}/${playbook}`).isFile);
            assert(evaluate(step.task.behaviorName!).endsWith("-9"), name);
        }
    }
});

const available = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const user = new TextDecoder().decode(new Deno.Command("id", { args: ["-un"] }).outputSync().stdout).trim();
const group = new TextDecoder().decode(new Deno.Command("id", { args: ["-gn"] }).outputSync().stdout).trim();

/** One part of instance-container-power.yml with a Caddy admin answer and, for a clone, a T3 that serves or not (the fixture's T3). */
async function part(
    tag: "sleep" | "wake",
    options: { caddy?: string; t3?: boolean; clone?: boolean; marker?: boolean },
): Promise<{ ok: boolean; output: string; result: Record<string, unknown>; marker: boolean }> {
    const folder = Deno.makeTempDirSync();
    const servers: Deno.HttpServer[] = [];
    try {
        for (const dir of ["bin", "run", "hibernation", "containers/instance-7/home/agent"]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
        Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({ containers: { "instance-7": { status: "Running", t3: options.t3 === true } } }));
        Deno.writeTextFileSync(
            `${folder}/bin/incus`,
            `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-container-incus.ts", import.meta.url).pathname}' "$@"\n`,
        );
        Deno.chmodSync(`${folder}/bin/incus`, 0o755);
        const marker = `${folder}/hibernation/app-instance-7.asleep`;
        if (options.marker) Deno.writeTextFileSync(marker, "");
        const caddy = Deno.serve({ port: 0, hostname: "127.0.0.1", onListen() {} }, () => new Response(options.caddy ?? "{}"));
        servers.push(caddy);
        const clone = options.clone === false ? null : { t3: { internal_port: 3773, ready_seconds: 1, unit: "t3.service" } };
        Deno.writeTextFileSync(
            `${folder}/run/intent.json`,
            JSON.stringify({
                ssh_execution: { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } },
                container: { project: "orbit-instances", name: "instance-7", clone },
                marker,
            }),
        );
        const done = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", "--tags", tag, "-e", "ansible_connection=local", "-e", "ansible_become=false",
                "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", "-e", `caddy_admin=http://127.0.0.1:${caddy.addr.port}`,
                "-e", `marker_owner=${user}`, "-e", `marker_group=${group}`, `${shipped}/instance-container-power.yml`],
            env: { HOST_FIXTURE: folder, ORBIT_RUN: `${folder}/run`, PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
                ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1", ANSIBLE_CONFIG: `${shipped}/ansible.cfg` },
        }).output();
        return {
            ok: done.success,
            output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr),
            result: JSON.parse(Deno.readTextFileSync(`${folder}/run/result.json`)),
            marker: (() => { try { return Deno.statSync(marker).isFile; } catch { return false; } })(),
        };
    } finally {
        for (const server of servers) await server.shutdown();
        Deno.removeSync(folder, { recursive: true });
    }
}

const loaded = JSON.stringify({ apps: { http: { servers: { orbit: { routes: [{ match: [{ file: { root: "/data/caddy/orbit/hibernation", try_files: ["/app-instance-7.asleep"] } }] }] } } } } });

Deno.test({ name: "a sleep writes the marker once Caddy's loaded site has the check, and refuses without changing anything when it lacks it", ignore: !available, async fn() {
    const ready = await part("sleep", { caddy: loaded });
    assert(ready.ok, ready.output);
    assertEquals(ready.result, {});
    assert(ready.marker);

    const unready = await part("sleep", { caddy: JSON.stringify({ apps: { http: {} } }) });
    assert(!unready.ok, unready.output);
    assertEquals(unready.result, { error_code: "instance.sleep_route_unready" });
    assert(!unready.marker);
} });

Deno.test({ name: "a wake removes the marker after T3 answers in a clone, keeps it when T3 stays silent, and needs no T3 in a default", ignore: !available, async fn() {
    const clone = await part("wake", { t3: true, marker: true });
    assert(clone.ok, clone.output);
    assertEquals(clone.result, {});
    assert(!clone.marker);

    const silent = await part("wake", { t3: false, marker: true });
    assert(!silent.ok, silent.output);
    assertEquals(silent.result, { error_code: "instance.t3_unready" });
    assert(silent.marker);

    const defaultWake = await part("wake", { clone: false, marker: true });
    assert(defaultWake.ok, defaultWake.output);
    assert(!defaultWake.marker);
} });
