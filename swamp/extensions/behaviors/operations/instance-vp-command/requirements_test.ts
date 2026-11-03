import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { behavior, material, workspaceLinks } from "./requirements.ts";
import { vpPreparationJobs } from "./steps.ts";
const runtime = {
    store: "/home/app/.vite-plus",
    vp_version: "0.2.1",
    node_version: "24.2.0",
    package_manager: "pnpm" as const,
    package_manager_version: "10.0.0",
    package_manager_hash: null,
};
const ctx = { writeResource: () => Promise.resolve(undefined) };
Deno.test("existing incompatible CLI refuses before project install", async () => {
    await assertRejects(
        () =>
            behavior.methods.cli.execute({
                runtime,
                observation: { stdout: "vp 0.1.0" },
            }, ctx),
        Error,
        "deployment.vp_store_failed",
    );
    await behavior.methods.cli.execute({
        runtime,
        observation: { stdout: "vp 1.4.0" },
    }, ctx);
    await behavior.methods.cli.execute({
        runtime,
        observation: { stdout: "vp v1.4.0\r\n\r\nLocal vite-plus:\r\n  vite-plus Not found\r\nEnvironment:\r\n  Node.js v26.13.0\r\n" },
    }, ctx);
    await assertRejects(() => behavior.methods.cli.execute({
        runtime,
        observation: { stdout: "invalid version\nvp v1.4.0\n" },
    }, ctx), Error, "deployment.vp_store_failed");
});
Deno.test("read-only inventory requires both exact installed versions", async () => {
    const inventory = {
        stdout: JSON.stringify({
            node: [{ version: "24.2.0" }],
            package_managers: { pnpm: [{ version: "10.0.0" }] },
        }),
    };
    assertEquals(
        (await behavior.methods.verify.execute({ runtime, inventory }, ctx))
            .ready,
        true,
    );
    await assertRejects(() =>
        behavior.methods.verify.execute({
            runtime,
            inventory: {
                stdout: JSON.stringify({
                    node: [{ version: "24.2.0" }],
                    package_managers: { pnpm: [] },
                }),
            },
        }, ctx)
    );
});
Deno.test("VP graph gates machine installer to absent manager and uses read-only proof", () => {
    const jobs = vpPreparationJobs("vp", "inputs.intent", "before");
    const steps = jobs[2].steps as Record<string, any>[];
    const manager = steps.find((row) => row.name === "vp-manager")!;
    assertEquals(manager.forEach.in.includes(".manager_required"), true);
    assertEquals(manager.task.type, "workflow");
    assertEquals(manager.task.workflowIdOrName, "operation-tool-manager-setup");
    const install = steps.find((row) => row.name === "vp-install")!;
    assertEquals(install.dependsOn[0].step, "vp-cli-ready");
    const inventory = steps.find((row) => row.name === "vp-inventory")!;
    assertEquals(inventory.task.inputs.argv.slice(-3), [
        "env",
        "list",
        "--json",
    ]);
    assertEquals(steps.every((row) => (row.labels as { role?: unknown } | undefined)?.role === "swamp"), true);
    for (const step of steps.filter((row) => row.forEach && row.task.type === "behavior")) {
        assertEquals(
            step.task.behaviorName.includes("string(self._index)"),
            true,
        );
    }
});

Deno.test("each manifest is one read-only exec whose absent file is empty output", () => {
    const jobs = vpPreparationJobs("vp", "inputs.intent", "before");
    const capture = jobs[0].steps as Record<string, any>[];
    const reads = capture.filter((row) => String(row.name).startsWith("vp-read-"));
    assertEquals(reads.map((row) => row.name), ["vp-read-package", "vp-read-pnpm_lock", "vp-read-npm_lock", "vp-read-node_version", "vp-read-nvmrc"]);
    for (const read of reads) {
        assertEquals(read.task.methodName, "exec");
        assertEquals(read.task.inputs.argv.includes('"-exec", "cat", "--", "{}", "+"'), true);
        assertEquals("script" in read.task.inputs, false);
    }
    assertEquals(jobs.flatMap((job) => job.steps as Record<string, any>[]).some((row) => row.task.methodName === "script" && row.task.inputs?.interpreter === "python3" && String(row.name).startsWith("vp-read")), false);
});

Deno.test("only in-application pnpm links of a vite-plus project are read", () => {
    const pkg = JSON.stringify({ devDependencies: { "vite-plus": "link:packages/vp" } });
    const lock = "version: link:packages/vp\nversion: 'link:../outside'\nversion: link:./packages/vp\n";
    assertEquals(workspaceLinks("/srv/app", pkg, lock), [
        { link: "link:packages/vp", directory: "/srv/app/packages/vp" },
        { link: "link:./packages/vp", directory: "/srv/app/packages/vp" },
    ]);
    assertEquals(workspaceLinks("/srv/app", "{}", lock), []);
    assertEquals(workspaceLinks("/srv/app", "", lock), []);
});

Deno.test("selection material treats empty reads as absent and prefers the machine store", () => {
    const raw = {
        package: JSON.stringify({ devDependencies: { "vite-plus": "link:packages/vp" } }),
        pnpm_lock: "lock", npm_lock: "", node_version: "24.2.0\n", nvmrc: "",
        home: "/home/app\n",
        store: "/home/app/.vite-plus/bin/vp\n/opt/orbit/vite-plus/bin/vp\n",
        links: [{ link: "link:packages/vp", directory: "/srv/app/packages/vp" }],
        workspaces: [{ step: "vp-read-workspace-0", stdout: JSON.stringify({ name: "vite-plus", version: "0.2.1" }) }],
    };
    const result = material(raw);
    assertEquals(result.store, "/opt/orbit/vite-plus");
    assertEquals(result.manager_required, false);
    assertEquals(result.locks, { "pnpm-lock.yaml": "lock" });
    assertEquals(result.workspaces, { "link:packages/vp": "0.2.1" });
    assertEquals([result[".node-version"], result[".nvmrc"]], ["24.2.0\n", null]);
    const bare = material({ ...raw, store: "" });
    assertEquals([bare.store, bare.manager_required], ["/home/app/.local/share/vite-plus", true]);
});

Deno.test("the store probe prunes every directory off the candidate paths", () => {
    const capture = vpPreparationJobs("vp", "inputs.intent", "before")[0].steps as Record<string, any>[];
    const argv = capture.find((row) => row.name === "vp-store")!.task.inputs.argv as string[];
    assertEquals(argv.slice(0, 3), ["find", "/opt", "."]);
    assertEquals(argv.at(-1), "-prune");
    assertEquals(argv.includes("-maxdepth"), false);
    for (const path of ["/opt", "/opt/orbit", "/opt/orbit/vite-plus/bin", ".", "./.local/share/vite-plus/bin"]) {
        assertEquals(argv.includes(path), true);
    }
});

Deno.test("production keeps its environment cache private while using the Node CLI", async () => {
    for (const store of ["/opt/orbit/vite-plus/bin/vp\n", ""]) {
        const result = await behavior.methods.capture.execute({
            production: true,
            package: JSON.stringify({devDependencies: {"vite-plus": "0.2.1"}, packageManager: "pnpm@10.0.0"}),
            pnpm_lock: "lockfileVersion: '9.0'\nimporters:\n  .:\n    devDependencies:\n      vite-plus:\n        version: 0.2.1\n",
            npm_lock: "", node_version: "24.2.0", nvmrc: "", home: "/home/orbit-app-7\n",
            store, links: [], workspaces: [],
        }, ctx);
        assertEquals(result.runtime?.store, "/home/orbit-app-7/.local/share/vite-plus");
        assertEquals(result.runtime?.binary, "/opt/orbit/vite-plus/bin/vp");
        assertEquals(result.manager_required, store === "");
    }
});

Deno.test("no VP dependency publishes a ready no-op without installation evidence", async () => {
    const { context, getWrittenResources } = createModelTestContext();
    const selected = await behavior.methods.capture.execute({
        package: "{}", pnpm_lock: "", npm_lock: "", node_version: "", nvmrc: "",
        home: "/home/app\n", store: "/opt/orbit/vite-plus/bin/vp\n", links: [], workspaces: [],
    }, context);
    assertEquals(selected.runtime, null);
    const steps = vpPreparationJobs("vp", "inputs.intent", "before")[2].steps as Record<string, any>[];
    for (const name of ["cli", "cli-ready", "install", "inventory", "verify"]) {
        assertEquals(steps.find((step) => step.name === "vp-" + name)!.forEach.in,
            '${{ steps["vp-requirements"].outputs.runtime == null ? [] : [steps["vp-requirements"].outputs.runtime] }}');
    }
    const result = await behavior.methods.result.execute({
        name: "vp",
        steps: { "vp-requirements": { status: "succeeded", outputs: selected } },
    }, context);
    assertEquals(result, { ready: true, runtime: null });
    assertEquals(getWrittenResources().map((row) => row.data), [selected, result]);
});

Deno.test("VP result refuses failed selection and missing installation proof", async () => {
    const cases: Parameters<typeof behavior.methods.result.execute>[0]["steps"][] = [
        {},
        { "vp-requirements": { status: "failed", outputs: { ready: true, runtime: null } } },
        { "vp-requirements": { status: "succeeded", outputs: { ready: true, runtime } } },
    ];
    for (const steps of cases) {
        const { context, getWrittenResources } = createModelTestContext();
        await assertRejects(() => behavior.methods.result.execute({ name: "vp", steps }, context));
        assertEquals(getWrittenResources(), []);
    }
});
