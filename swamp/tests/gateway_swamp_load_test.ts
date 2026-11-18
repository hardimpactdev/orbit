import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

const source = await Deno.readTextFile(
    new URL(
        "../workflows/workflow-operation-gateway-swamp-load.yaml",
        import.meta.url,
    ),
);
const workflow = parse(source) as {
    jobs: { steps: { name: string; task: { inputs: { run: string } } }[] }[];
};
const run =
    workflow.jobs[0].steps.find((step) => step.name === "load")!.task.inputs
        .run;

Deno.test("the release load compares only the commit's workflows, never a Schedule's", async () => {
    const filter = /^\s*tracked='([^']+)'$/m.exec(run)?.[1];
    assertEquals(typeof filter, "string");
    const listed = {
        results: [
            { id: "b", name: "operation-route-create" },
            { id: "s", name: "schedule-077c43f7-dd1c-4576-af12-b269e83cddfe" },
            { id: "a", name: "operation-schedule-create" },
        ],
    };
    const jq = new Deno.Command("jq", {
        args: ["-ec", filter!],
        stdin: "piped",
        stdout: "piped",
    }).spawn();
    const writer = jq.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(listed)));
    await writer.close();
    const output = await jq.output();
    assertEquals(output.success, true);
    assertEquals(JSON.parse(new TextDecoder().decode(output.stdout)), [
        ["a", "operation-schedule-create"],
        ["b", "operation-route-create"],
    ]);
    assertEquals(run.includes('jq -ec "$tracked"'), true);
});

type Step = {
    name: string;
    allowFailure?: boolean;
    task: { inputs: { run: string; env?: Record<string, string> } };
};
const check = ((workflow.jobs[0].steps as unknown) as Step[]).find((step) =>
    step.name === "Check the Ansible collections"
)!;

/** Runs the collections check against a requirements.yml and a pin of its own. */
async function checkCollections(pin: string) {
    const folder = await Deno.makeTempDir();
    await Deno.writeTextFile(
        `${folder}/requirements.yml`,
        "collections:\n  - {name: ansible.posix, version: 2.2.2}\n",
    );
    await Deno.writeTextFile(`${folder}/pin.yml`, pin);
    const done = await new Deno.Command("sh", {
        args: ["-c", check.task.inputs.run],
        env: {
            REQUIREMENTS: `${folder}/requirements.yml`,
            PIN: `${folder}/pin.yml`,
            NODE_ID: "209",
        },
        stdout: "piped",
        stderr: "piped",
    }).output();
    await Deno.remove(folder, { recursive: true });
    return {
        code: done.code,
        stdout: new TextDecoder().decode(done.stdout).trim(),
        stderr: new TextDecoder().decode(done.stderr).trim(),
    };
}

Deno.test("the release load checks the Ansible collections without failing the release, and installs nothing", () => {
    assertEquals(check.allowFailure, true);
    assertEquals(
        check.task.inputs.env?.PIN,
        "/opt/orbit/ansible/requirements.yml",
    );
    assertEquals(
        /ansible-galaxy|cp |install /.test(check.task.inputs.run),
        false,
    );
});

Deno.test("a pin equal to the release's requirements.yml passes", async () => {
    const run = await checkCollections(
        "collections:\n  - {name: ansible.posix, version: 2.2.2}\n",
    );
    assertEquals([run.code, run.stdout], [0, "current"]);
});

Deno.test("a pin from another requirements.yml fails the check with the converge that installs the collections", async () => {
    const run = await checkCollections(
        "collections:\n  - {name: community.general, version: 12.0.1}\n",
    );
    assertEquals(run.code, 1);
    assertEquals(
        run.stderr.endsWith("run: orbit node:role:add 209 swamp --converge"),
        true,
    );
});

type Checkout = {
    name: string;
    dependsOn?: { step: string; condition: { type: string } }[];
    task: { inputs: { run: string; env: Record<string, string> } };
};
const checkout = ((workflow.jobs[0].steps as unknown) as Checkout[]).find((
    step,
) => step.name === "checkout")!;

/** Runs a Git command in a folder and returns its trimmed stdout. */
async function git(folder: string, ...args: string[]) {
    const done = await new Deno.Command("git", {
        args: ["-C", folder, ...args],
        env: {
            GIT_AUTHOR_NAME: "t",
            GIT_AUTHOR_EMAIL: "t@t",
            GIT_COMMITTER_NAME: "t",
            GIT_COMMITTER_EMAIL: "t@t",
        },
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(done.success, true, new TextDecoder().decode(done.stderr));
    return new TextDecoder().decode(done.stdout).trim();
}

/** Runs the checkout script from a clone at the newer of two commits, toward `target`. */
async function runCheckout(target: "older" | "newer" | "back", force: boolean, offline = false) {
    const folder = await Deno.makeTempDir();
    const origin = `${folder}/origin`;
    await Deno.mkdir(origin);
    await git(origin, "init", "--quiet");
    await git(origin, "commit", "--quiet", "--allow-empty", "-m", "older");
    const older = await git(origin, "rev-parse", "HEAD");
    await git(origin, "commit", "--quiet", "--allow-empty", "-m", "newer");
    const newer = await git(origin, "rev-parse", "HEAD");
    await git(folder, "clone", "--quiet", origin, "checkout");
    const checkoutPath = `${folder}/checkout`;
    if (offline) await git(checkoutPath, "remote", "set-url", "origin", `${folder}/absent`);
    if (target === "newer") {
        await git(checkoutPath, "checkout", "--quiet", "--detach", older);
    }
    const sha = target === "newer" ? newer : older;
    const before = await git(checkoutPath, "rev-parse", "HEAD");
    const run = checkout.task.inputs.run.replace(
        "/home/orbit/apps/orbit-monorepo-staging/checkout",
        checkoutPath,
    );
    const done = await new Deno.Command("sh", {
        args: ["-c", run],
        env: {
            RELEASE_SHA: sha,
            FORCE: String(force),
            RETAINED: String(offline),
            PATH: Deno.env.get("PATH")!,
        },
        stdout: "piped",
        stderr: "piped",
    }).output();
    const head = await git(checkoutPath, "rev-parse", "HEAD");
    await Deno.remove(folder, { recursive: true });
    return {
        code: done.code,
        stdout: new TextDecoder().decode(done.stdout).trim(),
        stderr: new TextDecoder().decode(done.stderr).trim(),
        before,
        head,
        sha,
    };
}

Deno.test("a forced rollback release moves the serve checkout back to an ancestor commit", async () => {
    const run = await runCheckout("back", true);
    assertEquals(run.code, 0, run.stderr);
    assertEquals(run.stdout, run.before);
    assertEquals(run.head, run.sha);
});

Deno.test("an unforced load refuses an ancestor commit and leaves the checkout alone", async () => {
    const run = await runCheckout("back", false);
    assertEquals(run.code, 1);
    assertEquals(run.stderr.startsWith("gateway.release_downgrade: "), true);
    assertEquals(run.head, run.before);
});

Deno.test("an unforced load moves the serve checkout forward", async () => {
    const run = await runCheckout("newer", false);
    assertEquals(run.code, 0, run.stderr);
    assertEquals(run.head, run.sha);
});

Deno.test("the release passes its force flag to the load", async () => {
    const release = parse(
        await Deno.readTextFile(
            new URL(
                "../workflows/workflow-operation-gateway-release.yaml",
                import.meta.url,
            ),
        ),
    ) as {
        jobs: {
            steps: {
                name: string;
                task: { inputs?: { intent?: Record<string, unknown> } };
            }[];
        }[];
    };
    const load = release.jobs.flatMap((job) => job.steps).find((step) =>
        step.name === "Load Swamp on Node 209"
    )!;
    assertEquals(load.task.inputs?.intent?.force, "${{ inputs.intent.force }}");
    assertEquals(load.task.inputs?.intent?.gateway_ssh, "${{ inputs.intent.ssh }}");
});

Deno.test("the checkout reads the release commit and the force flag from the load intent", () => {
    assertEquals(checkout.task.inputs.env, {
        RELEASE_SHA: "${{ inputs.intent.sha }}",
        FORCE: "${{ string(inputs.intent.force) }}",
        RETAINED: "${{ string(has(inputs.intent.retained) && inputs.intent.retained) }}",
    });
});

Deno.test("retained rollback checks out the sealed commit with an unavailable origin", async () => {
    const result = await runCheckout("back", true, true);
    assertEquals(result.code, 0);
});

Deno.test("a resumed load checks the exact Gateway target before changing the staging checkout", async () => {
    const steps = workflow.jobs[0].steps as unknown as {
        name: string;
        dependsOn?: { step: string; condition: { type: string } }[];
        task: { inputs: { argv?: string[] } };
    }[];
    const gate = steps.find((step) => step.name === "Check the release target");
    assertEquals(checkout.dependsOn, [
        { step: "Check the release target", condition: { type: "succeeded" } },
    ]);
    const folder = await Deno.makeTempDir();
    try {
        const mirror = `${folder}/shared/orbit.git`;
        const state = `${folder}/.orbit`;
        const journal = `${folder}/var/gateway-release-switch`;
        const libexec = `${folder}/libexec`;
        const controller = "swamp/scripts/gateway-release-container.sh";
        const activate =
            "swamp/workflows/workflow-production-release-activate.yaml";
        const marker = "# orbit-gateway-recovery-protocol: 2";
        await Deno.mkdir(`${mirror}/swamp/scripts`, { recursive: true });
        await Deno.mkdir(`${mirror}/swamp/workflows`);
        await Deno.mkdir(state);
        await Deno.mkdir(libexec);
        await Deno.writeTextFile(`${state}/gateway-release.lock`, "");

        function hostPaths(script: string) {
            return script.replaceAll("/home/orbit", folder)
                .replaceAll("/var/lib/orbit", `${folder}/var`)
                .replaceAll("/usr/local/libexec", libexec);
        }
        const dispatcher = await Deno.readTextFile(
            new URL("../../bin/gateway-release-phase", import.meta.url),
        );
        await Deno.writeTextFile(
            `${libexec}/orbit-gateway-release-phase`,
            hostPaths(dispatcher),
            { mode: 0o700 },
        );
        await git(mirror, "init", "--quiet");
        async function commit(
            name: string,
            controllerMarker: boolean,
            workflowMarker: boolean,
        ) {
            for (
                const [path, present] of [[controller, controllerMarker], [
                    activate,
                    workflowMarker,
                ]] as const
            ) {
                await Deno.writeTextFile(
                    `${mirror}/${path}`,
                    `${present ? marker : ""}\n# ${name}\n`,
                );
            }
            await git(mirror, "add", ".");
            await git(mirror, "commit", "--quiet", "-m", name);
            return await git(mirror, "rev-parse", "HEAD");
        }
        const old = await commit("both old", false, false);
        const oldController = await commit("old controller", false, true);
        const oldWorkflow = await commit("old workflow", true, false);
        const retained = await commit("compatible retained", true, true);
        const live = await commit("compatible live", true, true);
        await git(folder, "clone", "--quiet", mirror, "staging");
        const staging = `${folder}/staging`;
        await Deno.writeTextFile(
            `${state}/gateway-containers.json`,
            JSON.stringify({ live, old: retained }),
        );
        const checkArgv = gate!.task.inputs.argv!;

        async function enterLoad(sha: string) {
            const env = { PATH: `${folder}:${Deno.env.get("PATH")}` };
            const check = await new Deno.Command(checkArgv[0], {
                args: checkArgv.slice(1).map((arg) => {
                    if (arg === "${{ inputs.intent.sha }}") return sha;
                    if (arg === "${{ string(inputs.operation_id) }}") {
                        return "42";
                    }
                    return hostPaths(arg);
                }),
                env,
            }).output();
            if (!check.success) return check;
            return await new Deno.Command("sh", {
                args: [
                    "-c",
                    checkout.task.inputs.run.replace(
                        "/home/orbit/apps/orbit-monorepo-staging/checkout",
                        staging,
                    ),
                ],
                env: {
                    ...env,
                    RELEASE_SHA: sha,
                    FORCE: "true",
                    RETAINED: "true",
                },
            }).output();
        }

        for (
            const [sha, missing] of [[old, controller], [
                oldController,
                controller,
            ], [oldWorkflow, activate]]
        ) {
            const result = await enterLoad(sha);
            assertEquals(result.code, 1);
            assertEquals(
                new TextDecoder().decode(result.stderr).trim(),
                `gateway.release_recovery_incompatible: ${sha} lacks container fix-forward protocol 2 in ${missing}`,
            );
            assertEquals(await git(staging, "rev-parse", "HEAD"), live);
        }
        for (const sha of [retained, live]) {
            const result = await enterLoad(sha);
            assertEquals(
                result.code,
                0,
                new TextDecoder().decode(result.stderr),
            );
            assertEquals(await git(staging, "rev-parse", "HEAD"), sha);
        }

        await Deno.remove(`${state}/gateway-containers.json`);
        await Deno.mkdir(journal, { recursive: true });
        await Deno.writeTextFile(`${journal}/operation`, "42\n");
        await Deno.writeTextFile(`${journal}/candidate`, retained);
        for (const sha of [retained, live]) {
            const refused = await enterLoad(sha);
            assertEquals(refused.code, 1);
            assertEquals(await git(staging, "rev-parse", "HEAD"), live);
        }
        await Deno.remove(`${journal}/operation`);
        await Deno.remove(`${libexec}/orbit-gateway-release-phase`);
        const host = await enterLoad(old);
        assertEquals(host.code, 0, new TextDecoder().decode(host.stderr));
        assertEquals(await git(staging, "rev-parse", "HEAD"), old);
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});
