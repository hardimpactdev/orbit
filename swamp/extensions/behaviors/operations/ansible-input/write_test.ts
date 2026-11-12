import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./write.ts";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

const asset = (name: string) => new URL(`../../../../${name}`, import.meta.url).pathname;
type CloneStep = { name: string; dependsOn?: unknown; task: { behaviorType?: string; methodName?: string;
    inputs: { argv?: string; stdin?: string } } };
const flow = parse(Deno.readTextFileSync(asset("workflows/workflow-operation-instance-create.yaml"))) as {
    jobs: { steps: CloneStep[] }[];
};
const cloneSteps = flow.jobs.flatMap((job) => job.steps).filter((step) =>
    ["clone-input", "clone-container", "clone-workspace", "clone-agent", "clone-t3", "clone-sessions", "clone-private-cleanup"].includes(step.name));
const cel = new Environment({ unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false })
    .registerFunction("vaultget(dyn, dyn): string", () => "fixture-private-credential");
const bytes = (text: string) => new TextEncoder().encode(text).length;
// Quote every argument, including sudo, as a conservative SSH remote-command bound.
const remote = (argv: string[]) => ["sudo", "-n", "--", ...argv]
    .map((arg) => "'" + arg.replaceAll("'", "'\\''") + "'").join(" ");
function render(expression: string, outputs: unknown, intent: unknown): unknown {
    return cel.evaluate(expression.slice(3, -2).trim().replaceAll("vault.get(", "vaultget("), {
        run: { id: (outputs as { clone_private: string }).clone_private.split("-").slice(3).join("-") },
        steps: { input: { status: "succeeded", outputs } }, inputs: {
            intent, execution_secrets: { git_credential: { vault: "fixture", key: "git" } },
        },
    });
}
async function cloneInput() {
    const id = crypto.randomUUID();
    const folder = `/tmp/orbit-ansible-${id}`;
    const account = new TextDecoder().decode((await new Deno.Command("id", { args: ["-un"] }).output()).stdout).trim();
    const container = {
        instance_id: 7, name: "instance-7", project: "orbit-instances", network: "orbitinst0", acl: "instance-7",
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
    const outputs = await behavior.methods.write.execute({ intent, path: folder + "/intent.json" }, {
        extensionFile: asset, writeResource: () => Promise.resolve(null),
    });
    return { folder, intent, outputs };
}
async function execute(argv: string[], stdin = "", env: Record<string, string> = {}) {
    const child = new Deno.Command(argv[0], { args: argv.slice(1), stdin: "piped", stdout: "piped", stderr: "piped", env }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(stdin));
    await writer.close();
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
        assert(cloneSteps.length >= 6);
        const sizes: Record<string, number> = {};
        for (const step of cloneSteps) {
            const argv = render(step.task.inputs.argv!, outputs, intent) as string[];
            assert(argv.every((arg) => typeof arg === "string" && bytes(arg) <= 16384), step.name);
            sizes[step.name] = bytes(remote(argv));
            assert(sizes[step.name] <= 16384, `${step.name}: ${sizes[step.name]} bytes`);
        }
        // The real release files must cross stdin, never disappear from the input.
        const payload = JSON.parse(outputs.clone_payload);
        assertEquals(payload.script, await Deno.readTextFile(asset("scripts/instance_clone.py")));
        assertEquals(payload.guest, await Deno.readTextFile(asset("scripts/instance_clone_guest.py")));
        for (const [key, name] of Object.entries({ node_services: "instance_node_services", mail: "instance_mail",
            bridge: "instance_bridge", node: "instance_node_runtime" })) {
            assertEquals(payload.bundle[key], await Deno.readTextFile(asset(`ansible/${name}.py`)));
        }
        const first = cloneSteps.find((step) => step.name === "clone-input")!;
        assertEquals(render(first.task.inputs.stdin!, outputs, intent), outputs.clone_payload);
        const manifest = parse(await Deno.readTextFile(asset("manifest.yaml"))) as { additionalFiles: string[] };
        assert(manifest.additionalFiles.includes("scripts/instance_clone_launch.py"));
        const legacy = ["timeout", "300", "python3", "-c", payload.script, JSON.stringify(payload.container),
            payload.guest, payload.network, payload.account, outputs.clone_private, JSON.stringify(payload.bundle), "container"];
        assert(bytes(remote(legacy)) > 131072); // This fixture reproduces the actual pre-fix spawn failure.
        console.log("pre-fix quoted bytes: " + bytes(remote(legacy)));
        console.log(JSON.stringify(sizes));
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("real clone launch preserves stdin and cleanup works after an early container failure", async () => {
    const { folder, intent, outputs } = await cloneInput();
    const stage = cloneSteps.find((step) => step.name === "clone-input")!;
    const first = cloneSteps.find((step) => step.name === "clone-container")!;
    const cleanup = cloneSteps.find((step) => step.name === "clone-private-cleanup")!;
    const clean = render(cleanup.task.inputs.argv!, outputs, intent) as string[];
    try {
        // No Node tool is contacted. The real clone entry point meets a failed fake Incus.
        await Deno.writeTextFile(folder + "/incus",
            '#!/bin/sh\n[ "$(cat "$CLONE_PRIVATE/git-credential")" = fixture-private-credential ] || exit 8\nexit 7\n',
            { mode: 0o700 });
        const staged = await execute(render(stage.task.inputs.argv!, outputs, intent) as string[],
            render(stage.task.inputs.stdin!, outputs, intent) as string);
        assertEquals(staged.code, 0);
        const failed = await execute(render(first.task.inputs.argv!, outputs, intent) as string[],
            render(first.task.inputs.stdin!, outputs, intent) as string,
            { PATH: folder + ":" + Deno.env.get("PATH"), CLONE_PRIVATE: outputs.clone_private });
        assertEquals(failed.code, 1);
        assert(new TextDecoder().decode(failed.stderr).includes("instance.container_container_failed: command incus exited 7"));
        assertEquals((await Deno.stat(outputs.clone_private)).mode! & 0o777, 0o700);
        for (const name of ["input.json", "launch.py", "clone.py"]) {
            assertEquals((await Deno.stat(outputs.clone_private + "/" + name)).mode! & 0o777, 0o600);
            assertEquals((await Deno.readTextFile(outputs.clone_private + "/" + name)).includes("fixture-private-credential"), false);
        }
        await assertRejects(() => Deno.stat(outputs.clone_private + "/git-credential"), Deno.errors.NotFound);
        // The first launch cannot silently reuse a previous run's staged release.
        assertEquals((await execute(render(stage.task.inputs.argv!, outputs, intent) as string[],
            render(stage.task.inputs.stdin!, outputs, intent) as string)).code, 1);
        assertEquals((await execute(clean)).code, 0);
        await assertRejects(() => Deno.stat(outputs.clone_private), Deno.errors.NotFound);
        // A failed SSH spawn or truncated payload created no launcher; cleanup still succeeds.
        const invalid = await execute(render(stage.task.inputs.argv!, outputs, intent) as string[], "invalid");
        assertEquals(invalid.code, 1);
        assertEquals((await execute(clean)).code, 0);
        const partial = await execute(render(stage.task.inputs.argv!, outputs, intent) as string[], "{}");
        assertEquals(partial.code, 1);
        await assertRejects(() => Deno.stat(outputs.clone_private + "/launch.py"), Deno.errors.NotFound);
        assertEquals((await execute(clean)).code, 0);
        const noInput = cel.evaluate(cleanup.task.inputs.argv!.slice(3, -2).trim(), {
            inputs: { intent }, steps: { input: { status: "failed" } },
            run: { id: folder.slice("/tmp/orbit-ansible-".length) },
        });
        assertEquals(noInput, clean);
        assertEquals((await execute(clean)).code, 0);
        assertEquals(cleanup.dependsOn, [{ step: "stop-container", condition: { type: "always" } }]);
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
