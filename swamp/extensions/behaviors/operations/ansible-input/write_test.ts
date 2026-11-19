import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./write.ts";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

const asset = (name: string) => new URL(`../../../../${name}`, import.meta.url).pathname;
type CloneStep = { name: string; dependsOn?: unknown; task: { behaviorType?: string; methodName?: string;
    inputs: { argv?: string; stdin?: string } } };
const steps = (name: string) => (parse(Deno.readTextFileSync(asset(`workflows/${name}`))) as { jobs: { steps: CloneStep[] }[] }).jobs
    .flatMap((job) => job.steps);
const cloneSteps = steps("workflow-operation-instance-create.yaml").filter((step) =>
    ["clone-container", "clone-club-key", "clone-t3", "clone-private-cleanup"].includes(step.name));
// The completion's one Node step and its private-folder cleanup (docs/cli/instance/complete.mmd).
const completeSteps = steps("workflow-operation-instance-complete.yaml").filter((step) => ["clone-services", "clone-private-cleanup"].includes(step.name));
// The Gateway's launch copy (CloneStage) for the stage key, the plain credential copy for any other key.
let stageCopy = "";
const cel = new Environment({ unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false })
    .registerFunction("vaultget(dyn, dyn): string", (_vault: unknown, key: unknown) =>
        key === "stage" ? stageCopy : "fixture-private-credential");
const bytes = (text: string) => new TextEncoder().encode(text).length;
// Quote every argument, including sudo, as a conservative SSH remote-command bound.
const remote = (argv: string[]) => ["sudo", "-n", "--", ...argv]
    .map((arg) => "'" + arg.replaceAll("'", "'\\''") + "'").join(" ");
function render(expression: string, outputs: unknown, intent: unknown): unknown {
    return cel.evaluate(expression.slice(3, -2).trim().replaceAll("vault.get(", "vaultget("), {
        run: { id: (outputs as { clone_private: string }).clone_private.split("-").slice(3).join("-") },
        steps: {}, inputs: {
            intent: { ...(intent as object), clone_launcher: JSON.parse((outputs as { stage_json: string }).stage_json).launcher },
            execution_secrets: { git_credential: { vault: "fixture", key: "git" }, clone_launch: { vault: "fixture", key: "stage" },
                process_environments: { vault: "fixture", key: "stage" } },
        },
    });
}
async function cloneInput(nested = false) {
    const id = crypto.randomUUID();
    const folder = `/tmp/orbit-ansible-${id}`;
    const account = new TextDecoder().decode((await new Deno.Command("id", { args: ["-un"] }).output()).stdout).trim();
    const container = {
        instance_id: 7, name: "instance-7", project: nested ? "orbit-instances-nested" : "orbit-instances",
        ...(nested ? {allowed_devices: {root: {type: "disk", path: "/", pool: "orbit-instances"}},
            club_key: {directory: "/run/orbit-club", file: "swamp-club-api-key", owner: 1000, group: 1000, tmpfiles: "/etc/tmpfiles.d/orbit-club.conf",
                gateway: "nested-gateway", serve_unit: "orbit-swamp-serve", available: true}} : {}), network: "orbitinst0", acl: "instance-7",
        nic_acl: "orbitinst0-proxy", address: "10.253.0.7", acl_rules: { description: "Orbit Instance 7", egress: [], ingress: [] },
        root_disk_pool: "orbit-instances", root_disk_size: "40GiB", config: {}, mirror: folder + "/mirror",
        repository: "https://github.com/example/fixture.git", protocols: ["https"], branch: "feature/clone",
        php: "8.4", application: "/srv/workspace/server/web", workspace: "/srv/workspace",
        environment: ["PATH=/opt/orbit-runtime/current/bin:/usr/bin:/bin", "NO_PROXY=localhost,127.0.0.1,10.253.0.1"],
        clone: { source: "instance-1", snapshot: "seed-" + "a".repeat(40), t3: { device: "t3" },
            files: ["claude", "codex", "frankenphp", "t3"].map((name) =>
                ({ path: "/etc/orbit/" + name, uid: 1001, gid: 1001, mode: "0600",
                    content: ("# Project instructions: 'quoted' \u2603\n").repeat(60) })) },
        processes: Array.from({ length: 8 }, (_, i) => ({
            id: i + 1, name: `orbit-process-${i + 1}-${"long-project-worker-".repeat(3)}.service`,
            path: `/etc/systemd/system/orbit-process-${i + 1}-worker.service`, desired: "running",
            content: "[Unit]\nDescription=Project worker\n[Service]\nUser=orbit\nExecStart=/usr/bin/php artisan queue:work\nRestart=always\n",
            environment_file: `/etc/orbit/processes/${i + 1}.env`,
        })),
        mail: { host: "10.253.0.1", port: 1025, docker: true,
            units: Object.fromEntries([1025, 2525].flatMap((port) => [
                [`orbit-mail-${port}.socket`, "[Socket]\nListenStream=127.0.0.1:" + port + "\n"],
                [`orbit-mail-${port}.service`, "[Service]\nExecStart=/usr/lib/systemd/systemd-socket-proxyd 10.253.0.1:1025\n"],
            ])) },
        node_services: Array.from({ length: 3 }, (_, i) => ({ id: i + 1, process_id: i + 9,
            process_name: "long-project-development-service-".repeat(2).slice(0, 63), host: "10.253.0.1", port: 6379 + i, docker: true })),
        // Two attached database endpoints, as rendered into the NIC and bridge intent.
        bridge_endpoints: [{ host: "10.253.0.1", port: 3306, docker: true }, { host: "10.44.0.3", port: 5434, docker: false }],
        firewall: [3306, 5434].map((port) => ({ rule: "allow", interface_in: "orbitinst0", from_ip: "10.253.0.7",
            to_ip: port === 3306 ? "10.253.0.1" : "10.44.0.3", to_port: String(port), proto: "tcp",
            comment: "orbit:instance:7:database-" + port })),
    };
    container.acl_rules.egress = container.bridge_endpoints.map((endpoint) => ({
        action: "allow", destination: endpoint.host + "/32", destination_port: String(endpoint.port),
        protocol: "tcp", state: "enabled",
    })) as never[];
    const intent = { instance_id: 7, already_active: false, container, ssh_execution: { target: { user: account } } };
    await Deno.mkdir(folder, { mode: 0o700 });
    // The run's private folder and the public part of its launch copy, as the Gateway frames them (CloneStage::payload).
    const outputs = { clone_private: `/tmp/orbit-clone-7-${id}`, stage_json: JSON.stringify(await stagePayload(container, account)) };
    return { folder, intent, outputs };
}
async function execute(argv: string[], stdin = "", env: Record<string, string> = {}) {
    const child = new Deno.Command(argv[0], { args: argv.slice(1), stdin: "piped", stdout: "piped", stderr: "piped", env }).spawn();
    const writer = child.stdin.getWriter();
    try {
        await writer.write(new TextEncoder().encode(stdin));
        await writer.close();
    } catch (error) {
        // A command that refuses before it reads its stdin closes the pipe; its exit and stderr are the outcome.
        if (!(error instanceof Deno.errors.BrokenPipe)) throw error;
    }
    return await child.output();
}

Deno.test("the full intent reaches a private file beyond the environment size limit", async () => {
    const id = crypto.randomUUID();
    const folder = `/tmp/orbit-ansible-${id}`;
    const intent = { node_id: 12, dashboard: "{{ literal }}".repeat(20000) };
    const rows: unknown[] = [];
    try {
        const result = await behavior.methods.write.execute({
            intent,
            path: `${folder}/intent.json`,
        }, {
            extensionFile: (name) =>
                new URL(`../../../../${name}`, import.meta.url).pathname,
            writeResource: (_spec, _name, value) => {
                rows.push(value);
                return Promise.resolve(null);
            },
        });
        assertEquals(JSON.parse(await Deno.readTextFile(result.path)), intent);
        assertEquals((await Deno.stat(folder)).mode! & 0o777, 0o700);
        assertEquals((await Deno.stat(result.path)).mode! & 0o777, 0o600);
        assertEquals(result.requirements, "ansible-core==2.20.11\n");
        assertEquals(
            result.assets,
            new URL("../../../../ansible", import.meta.url).pathname,
        );
        assertEquals(rows, [result]);
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("an intent write refuses paths outside its run folder", async () => {
    await assertRejects(() =>
        behavior.methods.write.execute({ intent: {}, path: "/tmp/elsewhere" }, {
            extensionFile: () => "",
            writeResource: () => Promise.resolve(null),
        })
    );
});

Deno.test("the fleet reboot finalizer publishes only its redacted public evidence", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    await Deno.mkdir(folder, { mode: 0o700 });
    const rows: unknown[] = [];
    try {
        await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ nodes: { "9": { name: "fixture", defer: null } } }));
        await Deno.writeTextFile(`${folder}/drain-9.json`, JSON.stringify({ stats: { node: { unreachable: 1 } }, plays: [{ tasks: [{ hosts: {
            node: { unreachable: true, msg: "ssh: connect to host 192.0.2.9 port 22: Connection timed out\nAuthorization: Bearer fixture-private-credential" },
        } }] }] }));
        const result = await behavior.methods.fleetRebootResult.execute({ folder, node_id: 9, position: 0 }, {
            extensionFile: () => "",
            writeResource: (_spec, _name, value) => { rows.push(value); return Promise.resolve(null); },
        });
        assertEquals(rows, [result]);
        const publicResult = JSON.parse(result.stdout);
        assertEquals([publicResult.node_id, publicResult.outcome, publicResult.error_code], [9, "failed", "fleet.node_unreachable"]);
        assertEquals(publicResult.error_detail.includes("fixture-private-credential"), false);
        assertEquals(publicResult.error_detail.includes("Connection timed out"), true);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the fleet reboot finalizer reports a fixed error when private callback inputs cannot be parsed", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    await Deno.mkdir(folder, { mode: 0o700 });
    try {
        await Deno.writeTextFile(`${folder}/intent.json`, 'invalid password=fixture-private-credential');
        const error = await assertRejects(() => behavior.methods.fleetRebootResult.execute({ folder, node_id: 9, position: 0 }, {
            extensionFile: () => "", writeResource: () => Promise.resolve(null),
        }), Error, "fleet.reboot_record_failed");
        assertEquals(error.message, "fleet.reboot_record_failed");
    } finally { await Deno.remove(folder, { recursive: true }); }
});


Deno.test("real clone commands stay below 32 KiB with a large Gateway intent", async () => {
    const { folder, intent, outputs } = await cloneInput();
    try {
        assert(cloneSteps.length >= 4);
        const sizes: Record<string, number> = {};
        assert(completeSteps.length === 2);
        for (const step of [...cloneSteps, ...completeSteps]) {
            const argv = render(step.task.inputs.argv!, outputs, intent) as string[];
            assert(argv.every((arg) => typeof arg === "string" && bytes(arg) <= 16384), step.name);
            const name = (completeSteps.includes(step) ? "complete:" : "") + step.name;
            sizes[name] = bytes(remote(argv));
            assert(sizes[name] <= 16384, `${name}: ${sizes[name]} bytes`);
        }
        // The real release files must cross stdin, never disappear from the input.
        const payload = JSON.parse(outputs.stage_json);
        assertEquals(payload.script, await Deno.readTextFile(asset("scripts/instance_clone.py")));
        assertEquals(payload.guest, await Deno.readTextFile(asset("scripts/instance_clone_guest.py")));
        for (const [key, name] of Object.entries({ node_services: "instance_node_services", mail: "instance_mail",
            bridge: "instance_bridge", node: "instance_node_runtime", machine_name: "instance_machine_name" })) {
            assertEquals(payload.bundle[key], await Deno.readTextFile(asset(`ansible/${name}.py`)));
        }
        // They cross the copy step's stdin as the stage copy's first line.
        const first = cloneSteps.find((step) => step.name === "clone-container")!;
        stageCopy = JSON.stringify({ stage: JSON.parse(outputs.stage_json), private: "fixture-private-credential\n" });
        assertEquals(render(first.task.inputs.stdin!, outputs, intent), stageCopy);
        const manifest = parse(await Deno.readTextFile(asset("manifest.yaml"))) as { additionalFiles: string[] };
        assert(manifest.additionalFiles.includes("scripts/instance_clone_launch.py"));
        const legacy = ["timeout", "300", "python3", "-c", payload.script, JSON.stringify(payload.container),
            payload.guest, payload.network, payload.account, outputs.clone_private, JSON.stringify(payload.bundle), "container"];
        assert(bytes(remote(legacy)) > 131072); // This fixture reproduces the actual pre-fix spawn failure.
        console.log("pre-fix quoted bytes: " + bytes(remote(legacy)));
        console.log(JSON.stringify(sizes));
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the workflow's copy step stages the stage copy and launches the container part; cleanup works after an early failure", async () => {
    const { folder, intent, outputs } = await cloneInput();
    const first = cloneSteps.find((step) => step.name === "clone-container")!;
    const cleanup = cloneSteps.find((step) => step.name === "clone-private-cleanup")!;
    const clean = render(cleanup.task.inputs.argv!, outputs, intent) as string[];
    // No input step stages before the copy step: it waits only for a proof copy's CI hold, which every other clone skips.
    assertEquals(first.dependsOn, [{ step: "clone-ci-hold", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } }]);
    const launch = (copy: string) => {
        stageCopy = copy;
        return execute(render(first.task.inputs.argv!, outputs, intent) as string[], render(first.task.inputs.stdin!, outputs, intent) as string,
            { PATH: folder + ":" + Deno.env.get("PATH"), CLONE_PRIVATE: outputs.clone_private });
    };
    try {
        // No Node tool is contacted. The real clone entry point meets a failed fake Incus, which echoes the private text on stderr.
        await Deno.writeTextFile(folder + "/incus",
            '#!/bin/sh\n[ "$(cat "$CLONE_PRIVATE/git-credential")" = fixture-private-credential ] || exit 8\necho "leaked fixture-private-credential" >&2\nexit 7\n',
            { mode: 0o700 });
        const failed = await launch(JSON.stringify({ stage: JSON.parse(outputs.stage_json), private: "fixture-private-credential\n" }));
        assertEquals(failed.code, 1);
        const stderr = new TextDecoder().decode(failed.stderr);
        assert(stderr.includes("instance.container_container_failed: command incus exited 7"), stderr);
        // The script redacts with the private text the launcher handed it: the failure detail never holds it.
        assert(!stderr.includes("fixture-private-credential") && stderr.includes("leaked [REDACTED]"), stderr);
        assertEquals((await Deno.stat(outputs.clone_private)).mode! & 0o777, 0o700);
        for (const name of ["input.json", "launch.py", "clone.py"]) {
            assertEquals((await Deno.stat(outputs.clone_private + "/" + name)).mode! & 0o777, 0o600);
            assertEquals((await Deno.readTextFile(outputs.clone_private + "/" + name)).includes("fixture-private-credential"), false);
        }
        await assertRejects(() => Deno.stat(outputs.clone_private + "/git-credential"), Deno.errors.NotFound);
        // The later steps run the staged launcher in the same private folder.
        const t3 = render(cloneSteps.find((step) => step.name === "clone-t3")!.task.inputs.argv!, outputs, intent) as string[];
        assertEquals(t3.slice(2), ["python3", outputs.clone_private + "/launch.py", outputs.clone_private, "t3"]);
        // A launch cannot silently reuse a previous run's staged release.
        const again = await launch(JSON.stringify({ stage: JSON.parse(outputs.stage_json), private: "fixture-private-credential\n" }));
        assertEquals([again.code, new TextDecoder().decode(again.stderr).trim()], [1, "instance.clone_input_failed"]);
        assertEquals((await execute(clean)).code, 0);
        await assertRejects(() => Deno.stat(outputs.clone_private), Deno.errors.NotFound);
        // A truncated copy names the stage and leaves no launcher; cleanup still succeeds.
        const invalid = await launch("invalid");
        assertEquals(invalid.code, 1);
        await assertRejects(() => Deno.stat(outputs.clone_private + "/launch.py"), Deno.errors.NotFound);
        assertEquals((await execute(clean)).code, 0);
        // The private folder goes beside the registration and finish: neither reads it.
        assertEquals(cleanup.dependsOn, []);
    } finally {
        await execute(clean);
        await Deno.remove(folder, { recursive: true });
    }
});

// The Gateway's launch copy (CloneStage::value): ONE JSON line, `stage` the public part, `private` the clone copy's text. The launcher
// the Gateway sends stages `stage` and runs the container part with only `private`, in one SSH dispatch.
Deno.test("the shipped launcher stages a launch copy's public part and gives the container part only the private text", async () => {
    const { folder, outputs } = await cloneInput();
    const launch = (privateText: string) => JSON.stringify({ stage: JSON.parse(outputs.stage_json), private: privateText });
    const launcher = JSON.parse(outputs.stage_json).launcher as string;
    const argv = ["timeout", "300", "python3", "-c", launcher, outputs.clone_private, "stage", "container"];
    const clean = ["rm", "-rf", "--", outputs.clone_private];
    try {
        assertEquals(launcher, await Deno.readTextFile(asset("scripts/instance_clone_launch.py")));
        await Deno.writeTextFile(folder + "/incus",
            '#!/bin/sh\n[ "$(cat "$CLONE_PRIVATE/git-credential")" = fixture-private-credential ] || exit 8\nexit 7\n',
            { mode: 0o700 });
        const env = { PATH: folder + ":" + Deno.env.get("PATH"), CLONE_PRIVATE: outputs.clone_private };
        const value = launch("fixture-private-credential\n");
        assertEquals(value.includes("\n"), false);
        const failed = await execute(argv, value, env);
        // The fake Incus saw the credential line alone: exit 7, not 8.
        assertEquals(failed.code, 1);
        assert(new TextDecoder().decode(failed.stderr).includes("instance.container_container_failed: command incus exited 7"));
        assertEquals((await Deno.stat(outputs.clone_private)).mode! & 0o777, 0o700);
        for (const name of ["input.json", "launch.py", "clone.py"]) {
            assertEquals((await Deno.stat(outputs.clone_private + "/" + name)).mode! & 0o777, 0o600);
            assertEquals((await Deno.readTextFile(outputs.clone_private + "/" + name)).includes("fixture-private-credential"), false);
        }
        assertEquals(JSON.parse(await Deno.readTextFile(outputs.clone_private + "/input.json")), JSON.parse(outputs.stage_json));
        // A later phase runs from the staged folder, as clone-t3 does.
        assertEquals((await execute(clean)).code, 0);
        // A copy that is no launch copy (the plain credential, the old two-part stage, no `stage`, a private part that is no text, no
        // object), or a second launch on a staged folder names the stage.
        for (const stdin of ["fixture-private-credential\n", outputs.stage_json + "\nfixture-private-credential\n", '{"private":"x"}',
            JSON.stringify({ stage: JSON.parse(outputs.stage_json), private: 7 }), "[]"]) {
            const refused = await execute(argv, stdin, env);
            assertEquals(refused.code, 1);
            assertEquals(new TextDecoder().decode(refused.stderr).trim(), "instance.clone_input_failed");
            await execute(clean);
        }
        // The launcher has no stage-only mode any more: `stage` without a phase, given the public part alone as the old stage step
        // sent it, stages nothing and names the stage.
        const bare = await execute(argv.slice(0, -1), outputs.stage_json, env);
        assertEquals([bare.code, new TextDecoder().decode(bare.stderr).trim()], [1, "instance.clone_input_failed"]);
        await assertRejects(() => Deno.stat(outputs.clone_private), Deno.errors.NotFound);
        await execute(argv, value, env);
        const again = await execute(argv, value, env);
        assertEquals(new TextDecoder().decode(again.stderr).trim(), "instance.clone_input_failed");
    } finally {
        await execute(clean);
        await Deno.remove(folder, { recursive: true });
    }
});


// The completion's one copy (CloneStage::completion) through the completion's one Node step, as the workflow renders it: the shipped
// launcher stages the scripts and runs the `complete` phase, which gives the services part only the Process files, then checks.
Deno.test("the completion's services step stages its copy and runs the services and the check in one dispatch", async () => {
    const { folder, intent, outputs } = await cloneInput();
    const services = completeSteps.find((step) => step.name === "clone-services")!;
    const clean = render(completeSteps.find((step) => step.name === "clone-private-cleanup")!.task.inputs.argv!, outputs, intent) as string[];
    const processes = { "/etc/orbit/processes/1/a.env": "QUEUE=fixture-private-queue\n" };
    const run = (dead: boolean) => {
        stageCopy = JSON.stringify({ stage: JSON.parse(outputs.stage_json), private: JSON.stringify({ processes }), processes });
        const argv = render(services.task.inputs.argv!, outputs, intent) as string[];
        assertEquals(argv.slice(2, 4), ["python3", "-c"]);
        assertEquals(argv.slice(5), [outputs.clone_private, "stage", "complete"]);
        return execute(argv, render(services.task.inputs.stdin!, outputs, intent) as string, { PATH: folder + ":" + Deno.env.get("PATH"), DEAD: dead ? "1" : "" });
    };
    try {
        // A fake Incus: the services part must get the Process files on stdin; a dead unit fails the check with its receipt.
        await Deno.writeTextFile(folder + "/incus", `#!/bin/sh
input=$(cat)
case "$*" in
  *" services {"*) [ "$input" = '{"processes": {"/etc/orbit/processes/1/a.env": "QUEUE=fixture-private-queue\\n"}}' ] || exit 9; echo '{"phase": "services", "changes": []}';;
  *" check {"*) if [ -n "$DEAD" ]; then echo '{"error_code": "instance.container_process_inactive", "process_failure": {"unit": "u"}}'; exit 1; fi; echo '{"phase": "check", "changes": []}';;
  *) exit 8;;
esac
`, { mode: 0o700 });
        const passed = await run(false);
        assertEquals([passed.code, new TextDecoder().decode(passed.stderr)], [0, ""]);
        assertEquals(new TextDecoder().decode(passed.stdout).trim().split("\n").map((line) => JSON.parse(line).phase), ["services", "check"]);
        for (const name of ["input.json", "launch.py", "clone.py"]) {
            assertEquals((await Deno.readTextFile(outputs.clone_private + "/" + name)).includes("fixture-private-queue"), false);
        }
        assertEquals((await execute(clean)).code, 0);
        const dead = await run(true);
        assertEquals(dead.code, 0);
        assertEquals(JSON.parse(new TextDecoder().decode(dead.stdout).trim().split("\n")[1]).error_code, "instance.container_process_inactive");
    } finally {
        await execute(clean);
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("workflow command and inline script templates keep 16 KiB of budget headroom", async () => {
    let largest = { path: "", bytes: 0 };
    const inspect = (value: unknown, path: string): void => {
        if (!value || typeof value !== "object") return;
        for (const [key, child] of Object.entries(value)) {
            if (["argv", "run", "script"].includes(key) && (typeof child === "string" || Array.isArray(child))) {
                const text = typeof child === "string" ? child : remote(child.map(String));
                const size = bytes(text) + 3 * (text.match(/'/g)?.length ?? 0);
                assert(size <= 16384, path + ":" + key + " template exceeds the SSH argument budget");
                if (size > largest.bytes) largest = { path: path + ":" + key, bytes: size };
            }
            inspect(child, path);
        }
    };
    for await (const file of Deno.readDir(asset("workflows"))) {
        if (file.name.endsWith(".yaml")) inspect(parse(await Deno.readTextFile(asset("workflows/" + file.name))), file.name);
    }
    console.log(JSON.stringify(largest));
});

/** The public part of a clone's launch copy, as the Gateway's CloneStage::payload frames it from the same release files. */
async function stagePayload(container: Record<string, unknown>, account: string) {
    const read = (name: string) => Deno.readTextFile(asset(name));
    const network = await read("ansible/instance_network.py");
    return {
        launcher: await read("scripts/instance_clone_launch.py"), script: await read("scripts/instance_clone.py"), container,
        guest: await read("scripts/instance_clone_guest.py"),
        network: container.allowed_devices ? network.split('if __name__ == "__main__":')[0] + await read("ansible/instance_nested.py") : network,
        account,
        bundle: {
            node_services: await read("ansible/instance_node_services.py"), machine_name: await read("ansible/instance_machine_name.py"), root_disk: await read("ansible/instance_root_disk.py"),
            mail: await read("ansible/instance_mail.py"), bridge: await read("ansible/instance_bridge.py"),
            node: await read("ansible/instance_node_runtime.py"),
            workspace_owner: await read("ansible/instance_workspace_owner.py"),
            ...(container.club_key ? { club_key: await read("ansible/instance_nested_club_key.sh") } : {}),
        },
    };
}

Deno.test("the input write gives a clone intent only its run folder and assets: a clone stages from the Gateway's copy", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    try {
        const outputs = await behavior.methods.write.execute({ intent: { instance_id: 7, container: { instance_id: 7, clone: {} } }, path: folder + "/intent.json" }, {
            extensionFile: asset, writeResource: () => Promise.resolve(null),
        });
        assertEquals(Object.keys(outputs), ["path", "assets", "requirements", "collections"]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});
