import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { convergeResult, phases, toolConvergeResult } from "../extensions/behaviors/operations/ansible-input/converge_result.ts";
import { codeDigest, convergeInput, phaseCode, phaseDigests, phaseInputs, roleDigests, rolePhaseCode, roleSiblings, roleSkippable, skippable, unsafeYaml } from "../extensions/behaviors/operations/ansible-input/converge_input.ts";
import { toolState } from "../extensions/behaviors/operations/ansible-input/converge_tool.ts";
import { ToolRefusal } from "../extensions/behaviors/operations/tools/contract.ts";
import { behavior as extension } from "../extensions/behaviors/operations/ansible-input/write.ts";
import { codeTree } from "./phase_code_fixture.ts";
import { entries, resolvePhases, shared as sharedFiles } from "../extensions/behaviors/operations/ansible-input/phase_code.ts";

/** The shipped code of every convergeInput call that does not test code. */
const shipped = await codeTree();
globalThis.addEventListener("unload", () => Deno.removeSync(shipped.tree, { recursive: true }));

/** The digest fields a converge intent carries (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change). */
const digestFixture = { recorded_digests: [] };

async function reports(folder: string, failure?: string) {
    for (const name of phases) {
        await Deno.writeTextFile(`${folder}/${name}.json`, JSON.stringify({
            stats: { target: { changed: 0, failures: name === failure ? 1 : 0, unreachable: 0 }, hub: { changed: 17, failures: 0, unreachable: 0 } }, plays: [{}],
        }));
        await Deno.writeTextFile(`${folder}/${name}-meta.json`, JSON.stringify({ exit_code: name === failure ? 2 : 0, elapsed_seconds: 1 }));
    }
    await Deno.writeTextFile(`${folder}/tools-result.json`, "[]");
    await Deno.writeTextFile(`${folder}/roles-result.json`, JSON.stringify({ browser_trust: [], t3_restart_deferred: [] }));
}

Deno.test("converge reports each container without Chromium's trust store and why, and only those fixed reasons", async () => {
    const folder = await Deno.makeTempDir();
    try {
        await reports(folder);
        const listed = [
            { container: "orbit-instances/instance-7", reason: "version mismatch: 0.25.5-3 != 0.26.2-2" },
            { container: "orbit-instances/instance-8", reason: "path refused: /home/agent/.pki/nssdb/pkcs11.txt is symlink" },
            { container: "orbit-instances-nested/instance-9", reason: "no agent home" },
        ];
        await Deno.writeTextFile(`${folder}/roles-result.json`, JSON.stringify({ browser_trust: listed, t3_restart_deferred: ["orbit-instances/instance-7"] }));
        const result = await convergeResult(folder);
        assertEquals(result.browser_trust, listed);
        assertEquals(result.t3_restart_deferred, ["orbit-instances/instance-7"]);
        assertEquals(result.failed_phase, undefined);
        // Free text from a container never reaches the result: the roles phase fails instead of passing it on.
        await Deno.writeTextFile(`${folder}/roles-result.json`, JSON.stringify({ t3_restart_deferred: [], browser_trust: [{ container: "orbit-instances/instance-7", reason: "version mismatch: $(id) != 0.26.2-2" }] }));
        const refused = await convergeResult(folder);
        assertEquals([refused.failed_phase, refused.browser_trust], ["roles", []]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("converge reports all phases, live no-op counts and measured elapsed time; hub changes are not target changes", async () => {
    const folder = await Deno.makeTempDir();
    try {
        await reports(folder);
        const result = await convergeResult(folder);
        assertEquals(result.phases.map((phase) => phase.name), [...phases]);
        assertEquals(result.phases.map((phase) => phase.status), phases.map(() => "succeeded"));
        assertEquals(result.changed, 0);
        assertEquals(result.elapsed_seconds, 7);
        assertEquals(result.failed_phase, undefined);
        const completed = [{ id: 5, status: "installed" as const, installed_version: "1.10" }];
        await Deno.writeTextFile(`${folder}/tools-result.json`, JSON.stringify(completed));
        await Deno.writeTextFile(`${folder}/tools.json`, "");
        await Deno.writeTextFile(`${folder}/tools-meta.json`, JSON.stringify({ exit_code: 124, elapsed_seconds: 8900 }));
        const timedOut = await convergeResult(folder);
        assertEquals([timedOut.failed_phase, timedOut.tools], ["tools", completed]);
        assertEquals(await toolConvergeResult(folder), { changed: 0, tools: completed, succeeded: false });
    } finally { await Deno.remove(folder, { recursive: true }); }
});

for (const failure of phases) {
    Deno.test(`converge stops reporting success at ${failure} and preserves the first failure`, async () => {
        const folder = await Deno.makeTempDir();
        try {
            await reports(folder, failure);
            const result = await convergeResult(folder);
            const index = phases.indexOf(failure);
            assertEquals(result.failed_phase, failure);
            assertEquals(result.phases.map((phase) => phase.status), phases.map((_, i) => i < index ? "succeeded" : i === index ? "failed" : "skipped"));
            assertEquals(result.elapsed_seconds, index + 1);
        } finally { await Deno.remove(folder, { recursive: true }); }
    });
}

for (const content of ["{", JSON.stringify({ stats: {}, plays: [{}] }), JSON.stringify({ stats: { target: { changed: -1, failures: 0, unreachable: 0 } }, plays: [{}] })]) {
    Deno.test(`malformed callbacks cannot claim a no-op (${content})`, async () => {
        const folder = await Deno.makeTempDir();
        try {
            await reports(folder);
            await Deno.writeTextFile(`${folder}/agent.json`, content);
            assertEquals((await convergeResult(folder)).failed_phase, "agent");
        } finally { await Deno.remove(folder, { recursive: true }); }
    });
}

Deno.test("an isolated Tool failure retains both receipts without failing the phase or skipping network and check", async () => {
    const folder = await Deno.makeTempDir();
    try {
        await reports(folder);
        const tools = [{ id: 1, status: "failed", installed_version: null }, { id: 2, status: "installed", installed_version: "2.3.4" }];
        await Deno.writeTextFile(`${folder}/tools-result.json`, JSON.stringify(tools));
        const result = await convergeResult(folder);
        assertEquals(result.tools, tools);
        assertEquals(result.failed_phase, undefined);
        assertEquals(result.phases.map((phase) => phase.status), phases.map(() => "succeeded"));
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a tools phase that stops before it judges a Tool fails the converge with no Tool receipt", async () => {
    const folder = await Deno.makeTempDir();
    try {
        // The phase failed at its helper check: Ansible reports the failure and the playbook never wrote tools-result.json.
        await reports(folder, "tools");
        await Deno.remove(`${folder}/tools-result.json`);
        const result = await convergeResult(folder);
        assertEquals(result.tools, []);
        assertEquals([result.failed_phase, result.error_code], ["tools", "operation.native_failed"]);
        assertEquals(result.phases.slice(4).map((phase) => phase.status), ["failed", "skipped", "skipped"]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("private input is mode-protected, literal and limited to target plus recorded hub", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const ssh = { identity_file: "/test/identity", target: { host: "10.44.0.7", port: 22, user: "orbit", host_keys: ["ssh-ed25519 pinned"] } };
        const secret = "literal $HOME {{ lookup('pipe', 'false') }}\nsecond line\n";
        await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ ...digestFixture, node_id: 7, ssh, network: { hub: { hub_id: 2, ssh: { ...ssh, target: { ...ssh.target, host: "10.44.0.2" } } } }, roles: [] }));
        await Deno.writeTextFile(`${folder}/plans.json`, "{}");
        await Deno.writeTextFile(`${folder}/secret-agent_verifier`, secret);
        await convergeInput(folder, 1, "/unused", "/shipped/helper.ts", shipped.code);
        assertEquals(Object.keys(JSON.parse(await Deno.readTextFile(`${folder}/inventory.json`)).all.hosts), ["target", "hub"]);
        assertEquals(await Deno.readTextFile(`${folder}/known_hosts`), "10.44.0.2 ssh-ed25519 pinned\n10.44.0.7 ssh-ed25519 pinned\n");
        const vars = await Deno.readTextFile(`${folder}/vars.yml`);
        assertEquals(vars.includes(unsafeYaml(secret)), true);
        assertEquals(vars.includes('"kind": "node.converge"'), true);
        for (const name of ["inventory.json", "known_hosts", "vars.yml"]) assertEquals((await Deno.stat(`${folder}/${name}`)).mode! & 0o777, 0o600);
        await assertRejects(() => Deno.stat(`${folder}/secret-agent_verifier`), Deno.errors.NotFound);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("converge keeps the staged phase files after worker cache removal and keeps repeat digests stable", async () => {
    const cache = await Deno.makeTempDir();
    const folders = Array.from({ length: 2 }, () => `/tmp/orbit-ansible-${crypto.randomUUID()}`);
    const files = [
        "ansible/build_vars.py",
        "ansible/operation-node-converge.yml",
        "ansible/tasks/node-converge-tool.yml",
        "extensions/behaviors/operations/ansible-input/converge_tool.ts",
        "extensions/behaviors/operations/tools/native.ts",
        ...roleSiblings,
    ];
    const ctx = { extensionFile: (path: string) => `${cache}/${path}`, writeResource: () => Promise.resolve() };
    async function prepare(folder: string, operationId: number, recorded: Record<string, string>) {
        const ssh = { identity_file: "/test/identity", target: { host: "10.44.0.7", port: 22, user: "orbit", host_keys: ["ssh-ed25519 pinned"] } };
        await Deno.mkdir(folder, { mode: 0o700 });
        await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ node_id: 7, ssh, roles: [], recorded_digests: recorded }));
        await Deno.writeTextFile(`${folder}/plans.json`, "{}");
        return await extension.methods.convergeInput.execute({ folder, operation_id: operationId }, ctx);
    }
    try {
        for (const file of files) {
            await Deno.mkdir(`${cache}/${file.slice(0, file.lastIndexOf("/"))}`, { recursive: true });
            await Deno.writeTextFile(`${cache}/${file}`, file);
        }
        const [first, second] = folders;
        const prepared = await prepare(first, 41, {});
        const { digests } = JSON.parse(await Deno.readTextFile(`${first}/digests.json`));
        assertEquals((await prepare(second, 42, digests)).unchanged, [...skippable]);
        await Deno.remove(cache, { recursive: true });
        assertEquals(prepared.assets, `${first}/assets/ansible`);
        const { converge_tool_helper } = parse(await Deno.readTextFile(`${first}/vars.yml`)) as { converge_tool_helper: string };
        assertEquals(converge_tool_helper, `${first}/assets/operations/ansible-input/converge_tool.ts`);
        for (const file of files.filter((file) => !(roleSiblings as readonly string[]).includes(file))) {
            assertEquals(await Deno.readTextFile(`${first}/assets/${file.replace("extensions/behaviors/", "")}`), file);
        }
        // The role's files outside ansible/ keep their paths beside it, so `../` from the staged playbook and its files reaches them.
        for (const file of roleSiblings) assertEquals(await Deno.readTextFile(`${first}/assets/${file}`), file);

        const result = await extension.methods.convergeResult.execute({ folder: first }, ctx);
        assertEquals(JSON.parse(result.stdout).cleanup_complete, true);
        await assertRejects(() => Deno.stat(first), Deno.errors.NotFound);
    } finally {
        for (const folder of [cache, ...folders]) {
            await Deno.remove(folder, { recursive: true }).catch((error) => {
                if (!(error instanceof Deno.errors.NotFound)) throw error;
            });
        }
    }
});

for (const failure of [undefined, "roles"] as const) {
    Deno.test(`terminal result removes private input after ${failure ?? "success"} without publishing callback content`, async () => {
        const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
        await Deno.mkdir(folder, { mode: 0o700 });
        await reports(folder, failure);
        await Deno.writeTextFile(`${folder}/secret-agent_verifier`, "private-canary");
        let published: unknown;
        const result = await extension.methods.convergeResult.execute({ folder }, {
            extensionFile() { throw new Error("No assets should be read at cleanup"); },
            writeResource(_spec, _name, value) { published = value; return Promise.resolve(); },
        });
        const value = JSON.parse(result.stdout);
        assertEquals(value.outcome, failure ? "failed" : "succeeded");
        assertEquals(value.failed_phase, failure);
        assertEquals(value.cleanup_complete, true);
        assertEquals(JSON.stringify(published).includes("private-canary"), false);
        await assertRejects(() => Deno.stat(folder), Deno.errors.NotFound);
    });
}

for (const installed_version of [null, "", "2.3.4 private-text", "2.3.4\nprivate-text"]) {
    Deno.test(`an invalid installed Tool version cannot become terminal success (${JSON.stringify(installed_version)})`, async () => {
        const folder = await Deno.makeTempDir();
        try {
            await reports(folder);
            await Deno.writeTextFile(`${folder}/tools-result.json`, JSON.stringify([{ id: 1, status: "installed", installed_version }]));
            const result = await convergeResult(folder);
            assertEquals(result.failed_phase, "tools");
            assertEquals(result.tools, []);
            assertEquals(result.phases.slice(5).map((phase) => phase.status), ["skipped", "skipped"]);
        } finally { await Deno.remove(folder, { recursive: true }); }
    });
}

for (const failure of [undefined, "roles"] as const) {
    Deno.test(`cleanup failure is explicit and preserves ${failure ?? "the completed check as the first failure"}`, async () => {
        const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
        await Deno.mkdir(folder, { mode: 0o700 });
        await reports(folder, failure);
        const remove = Deno.remove;
        Deno.remove = () => Promise.reject(new Deno.errors.PermissionDenied("fixture cleanup refusal"));
        try {
            const output = await extension.methods.convergeResult.execute({ folder }, {
                extensionFile() { throw new Error("Cleanup needs no assets"); },
                writeResource() { return Promise.resolve(); },
            });
            const result = JSON.parse(output.stdout);
            assertEquals(result.outcome, "failed");
            assertEquals(result.cleanup_complete, false);
            assertEquals(result.failed_phase, failure ?? "check");
            assertEquals(result.error_code, "operation.native_failed");
        } finally {
            Deno.remove = remove;
            await remove(folder, { recursive: true });
        }
    });
}

Deno.test("failure before input preparation still publishes a failed terminal and accepts an absent private folder", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    const output = await extension.methods.convergeResult.execute({ folder }, {
        extensionFile() { throw new Error("Cleanup needs no assets"); },
        writeResource() { return Promise.resolve(); },
    });
    const result = JSON.parse(output.stdout);
    assertEquals(result.outcome, "failed");
    assertEquals(result.failed_phase, "input");
    assertEquals(result.cleanup_complete, true);
    assertEquals(result.phases.map((phase: { status: string }) => phase.status), ["failed", ...Array(6).fill("skipped")]);
});

const apt = { node_id: 7, user: "orbit", tool: { manager: "apt", package: "tree", version_constraint: "^2.2", protected: [], owned: true } };
const aptFacts = { version: { rc: 0, stdout: "apt 3.1.16 (amd64)" }, query: { rc: 0, stdout: "tree\tinstall ok installed\t2.2.1-1\n" } };
Deno.test("Node snapshot packages use direct HTTPS configuration, bounded retries and exact pins without removals", () => {
    // Retain the live package policy formerly covered beside the retired VM role.
    // deno-lint-ignore no-explicit-any
    const read = (name: string) => parse(Deno.readTextFileSync(new URL(`../ansible/${name}`, import.meta.url))) as Record<string, any>[];
    const target = read("operation-node-converge.yml").find((play) => play.hosts === "target")!;
    assertEquals([target.vars.apt_retries, target.vars.apt_delay], [4, 30]);
    assertEquals(target.module_defaults["ansible.builtin.apt"].lock_timeout, 300);
    assertEquals(target.vars.apt_index_environment, "{{ apt_environment }}");
    const config = read("tasks/node-converge-apt-config.yml");
    const sources = config.find((task) => task.name === "Point apt at the Ubuntu snapshot")!["ansible.builtin.copy"].content;
    assertEquals(sources.includes("URIs: https://snapshot.ubuntu.com/ubuntu/{{ recipe.ubuntu.snapshot }}/"), true);
    const direct = config.find((task) => task.name === "Write the apt configuration")!["ansible.builtin.copy"].content;
    assertEquals(direct.includes("Proxy"), false);
    const packages = read("tasks/node-converge-apt.yml");
    const operations = [...config, ...packages, ...packages.flatMap((task) => task.block ?? [])];
    const installs = operations.filter((task) => "ansible.builtin.apt" in task || String(task["ansible.builtin.command"]?.argv).includes("apt-get"));
    assertEquals(installs.map((task) => task.name).sort(), ["Install the Ubuntu packages", "Install the packages that come as files", "Read the package indexes"]);
    for (const task of operations) {
        assertEquals("ansible.builtin.shell" in task || "ansible.builtin.raw" in task, false, task.name);
    }
    for (const task of installs) {
        const index = task.name === "Read the package indexes";
        assertEquals(task.environment, index ? "{{ apt_index_environment }}" : "{{ apt_environment }}");
        if (index) {
            assertEquals(task["ansible.builtin.apt"], { update_cache: true, update_cache_retries: 12, update_cache_retry_max_delay: 30 });
        } else {
            assertEquals([task.retries, task.delay, task.until], ["{{ apt_retries }}", "{{ apt_delay }}", `${task.register} is succeeded`]);
        }
    }
    const ubuntu = installs.find((task) => task.name === "Install the Ubuntu packages")!["ansible.builtin.apt"];
    assertEquals([ubuntu.install_recommends, ubuntu.fail_on_autoremove, ubuntu.allow_downgrade], [false, true, true]);
    const deb = installs.find((task) => task.name === "Install the packages that come as files")!["ansible.builtin.command"].argv;
    for (const flag of ["--no-install-recommends", "--no-remove", "--allow-downgrades"]) assertEquals(deb.includes(flag), true, flag);
});

Deno.test("matching Tool inventory is a read-only no-op", () => {
    assertEquals(toolState("inspect", { ...apt, ...aptFacts }), { installed_version: "2.2.1-1", needs_write: false });
});
Deno.test("a present package is never taken over for a Tool Orbit does not own, whatever its last status", () => {
    for (const tool_status of ["installing", "failed", null]) {
        const unowned = { ...apt, tool: { ...apt.tool, owned: false, tool_status, installed_version: tool_status === "failed" ? "2.2.1-1" : null } };
        assertThrows(() => toolState("inspect", { ...unowned, ...aptFacts }), ToolRefusal, "tool.package_already_installed");
        const absent = { ...aptFacts, query: { rc: 1, stdout: "", stderr: "dpkg-query: no packages found matching tree" } };
        assertEquals(toolState("inspect", { ...unowned, ...absent }), { installed_version: null, needs_write: true });
    }
});
Deno.test("a Tool's source holds its plan's owned members and the run's installed Tools before publication, and adds itself only once verified", () => {
    const repository = { name: "proof", uris: ["https://packages.example.test/apt"], suites: ["stable"], components: ["main"],
        signing_key: { url: "https://packages.example.test/key.asc", fingerprint: "A".repeat(40) } };
    const pins = (packages: string) => (packages === "" ? "" : `Package: ${packages}\nPin: origin "packages.example.test"\nPin-Priority: 990\n\n`) +
        'Package: *\nPin: origin "packages.example.test"\nPin-Priority: 1\n';
    const unowned = toolState("pins", { ...apt, tool: { ...apt.tool, owned: false, repository, repository_packages: ["nano", "tree"] }, installed: ["ed"] });
    assertEquals(unowned, { repository_preferences: pins("ed nano tree"), repository_preferences_held: pins("ed nano") });
    const owned = toolState("pins", { ...apt, tool: { ...apt.tool, repository, repository_packages: ["tree"] }, installed: [] });
    assertEquals(owned, { repository_preferences: pins("tree"), repository_preferences_held: pins("tree") });
    const alone = toolState("pins", { ...apt, tool: { ...apt.tool, owned: false, repository, repository_packages: ["tree"] }, installed: [] });
    assertEquals(alone.repository_preferences_held, pins(""));
    const moved = toolState("pins", { ...apt, tool: { ...apt.tool, owned: false, repository, repository_packages: ["tree"],
        repository_origins: ["old.example.test", "packages.example.test"] }, installed: [] });
    assertEquals(moved.repository_preferences_held, pins("") + '\nPackage: *\nPin: origin "old.example.test"\nPin-Priority: 1\n');
    // Once this Tool is verified installed from the source, the preference is the confirmed source's alone.
    assertEquals(moved.repository_preferences, pins("tree"));
    // A sibling of the source this run already verified installed confirmed it: even a refused Tool's held preference names it alone, so
    // the last preference written agrees with the record a completed phase verifies.
    const confirmed = toolState("pins", { ...apt, tool: { ...apt.tool, owned: false, repository, repository_packages: [],
        repository_origins: ["old.example.test", "packages.example.test"] }, installed: ["nano"] });
    assertEquals(confirmed.repository_preferences_held, pins("nano"));
});
Deno.test("an out-of-constraint candidate is rejected before returning write argv", () => {
    assertThrows(() => toolState("candidate", { ...apt, ...aptFacts, candidate: { rc: 0, stdout: "tree:\n  Candidate: 3.0.0-1\n" } }));
});
Deno.test("a protected package cannot produce a machine plan", () => {
    assertThrows(() => toolState("plan", { ...apt, tool: { ...apt.tool, package: "openssh-server", protected: ["openssh-server"] } }));
});
Deno.test("a failed Tool postcondition is not installed", () => {
    assertThrows(() => toolState("verify", { ...apt, ...aptFacts, query: { rc: 0, stdout: "tree\tinstall ok installed\t3.0.0-1\n" } }));
});

Deno.test("the workflow has ordered phase invocations and an always-dependent private cleanup", () => {
    const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-node-converge-direct.yaml", import.meta.url))) as {
        jobs: { name: string; dependsOn?: { job: string; condition: { type: string } }[]; steps: { name: string; task: { inputs: { run?: string; env?: { ORBIT_ANSIBLE: string } } } }[] }[];
    };
    const jobs = workflow.jobs.filter((job) => phases.includes(job.name as typeof phases[number]));
    assertEquals(jobs.map((job) => job.name), [...phases]);
    for (let i = 0; i < jobs.length; i++) {
        const { run, env } = jobs[i].steps[0].task.inputs;
        assertEquals(env?.ORBIT_ANSIBLE, '${{ steps["Prepare private input"].outputs.assets }}');
        assertEquals(run?.match(/ansible-playbook/g)?.length, 1);
        assertEquals(run?.includes(`--tags ${phases[i]} `), true);
        assertEquals(run?.includes(`${phases[i]}-meta.json`), true);
        if (i) assertEquals(jobs[i].dependsOn, [{ job: phases[i - 1], condition: { type: "succeeded" } }]);
    }
    assertEquals(jobs.find((job) => job.name === "check")?.steps[0].name, "Check the Node");
    assertEquals(workflow.jobs.find((job) => job.name === "converge")?.dependsOn, [{ job: "check", condition: { type: "always" } }]);
});

// Captured with pinned ansible-core 2.20.11 and ansible.posix.json, then scrubbed
// of host paths, interpreter facts, UUIDs and timestamps. Mutations below keep
// its callback shape while covering other host results and privacy markers.
const commandReport = JSON.parse(Deno.readTextFileSync(new URL("./fixtures/node-converge-command.json", import.meta.url)));
const hiddenReport = JSON.parse(Deno.readTextFileSync(new URL("./fixtures/node-converge-hidden.json", import.meta.url)));
const privateMarkers = ["LEAK_MSG", "LEAK_STDOUT", "LEAK_STDERR", "LEAK_CMD", "LEAK_ARGS", "LEAK_ITEM", "LEAK_HIDDEN", "LEAK_RENDEREDSECRET1234567890", "LEAK_PATH", "LEAK_ROLE_PATH", "LEAK_EXCEPTION"];
const commandFailure = { task: "Fail a command", host: "target", reason: "command_failed", rc: 7 } as const;

async function failureReport(report: unknown, exitCode = 2) {
    const folder = await Deno.makeTempDir();
    try {
        await reports(folder);
        if (report !== undefined) await Deno.writeTextFile(`${folder}/roles.json`, typeof report === "string" ? report : JSON.stringify(report));
        if (report === undefined) await Deno.remove(`${folder}/roles.json`);
        await Deno.writeTextFile(`${folder}/roles-meta.json`, JSON.stringify({ exit_code: exitCode, elapsed_seconds: 1 }));
        const result = await convergeResult(folder);
        for (const marker of privateMarkers) assertEquals(JSON.stringify(result).includes(marker), false, marker);
        return result;
    } finally { await Deno.remove(folder, { recursive: true }); }
}

Deno.test("a failed role command exports only its bounded task and return code", async () => {
    assertEquals((await failureReport(commandReport)).failure, commandFailure);
    const report = structuredClone(commandReport);
    report.plays[0].tasks[0].task.role = "/private/LEAK_ROLE_PATH";
    assertEquals((await failureReport(report)).failure, commandFailure);
});

for (const [label, hostResult, expected] of [
    ["unreachable", { unreachable: true, msg: "LEAK_MSG" }, { reason: "unreachable" }],
    ["assert", { failed: true, action: "ansible.builtin.assert", assertion: "LEAK_ARGS", evaluated_to: false, msg: "LEAK_MSG" }, { reason: "assertion" }],
    ["fail", { failed: true, action: "ansible.builtin.fail", msg: "LEAK_MSG" }, { reason: "assertion" }],
    ["timeout", { failed: true, rc: 124, msg: "LEAK_MSG" }, { reason: "timeout" }],
    ["module", { failed: true, msg: "LEAK_MSG" }, { reason: "module_failed" }],
    ["unbounded rc", { failed: true, rc: 2147483648 }, { reason: "module_failed" }],
] as const) {
    Deno.test(`converge classifies ${label} without exporting host output`, async () => {
        const report = structuredClone(commandReport);
        report.plays[0].tasks[0].hosts.target = hostResult;
        assertEquals((await failureReport(report)).failure, { task: commandFailure.task, host: "target", ...expected });
    });
}

Deno.test("converge identifies the hub failure while target stats stay clean", async () => {
    const report = structuredClone(commandReport);
    report.plays[0].tasks[0].hosts = { hub: report.plays[0].tasks[0].hosts.target, target: { changed: false } };
    report.stats.hub = report.stats.target;
    report.stats.target = { changed: 0, failures: 0, unreachable: 0 };
    assertEquals((await failureReport(report)).failure, { ...commandFailure, host: "hub" });
});

Deno.test("no_log and censored tasks hide names and return codes", async () => {
    assertEquals((await failureReport(hiddenReport)).failure, { task: null, host: "target", reason: "hidden" });
    for (const scope of ["task", "result", "censored", "loop"]) {
        const report = structuredClone(commandReport);
        const row = report.plays[0].tasks[0];
        row.task.name = "LEAK_HIDDEN";
        row.task.role = "LEAK_HIDDEN";
        if (scope === "task") row.task.no_log = true;
        if (scope === "result") row.hosts.target._ansible_no_log = true;
        if (scope === "censored") row.hosts.target.censored = "LEAK_HIDDEN";
        if (scope === "loop") row.hosts.target.results = [{ _ansible_no_log: true, item: "LEAK_ITEM" }];
        assertEquals((await failureReport(report)).failure, { task: null, host: "target", reason: "hidden" });
    }
});

Deno.test("converge bounds task text and refuses host addresses", async () => {
    const report = structuredClone(commandReport);
    for (const name of ["\u001b\n\u2603 word", "word ".repeat(30), "probe : Fail a command"]) {
        report.plays[0].tasks[0].task.name = name;
        assertEquals((await failureReport(report)).failure, { ...commandFailure, task: null });
    }
    report.plays[0].tasks[0].task.name = "word ".repeat(24);
    assertEquals((await failureReport(report)).failure, { ...commandFailure, task: "word ".repeat(24) });
    report.plays[0].tasks[0].hosts = { "192.0.2.1": { failed: true, rc: 7 } };
    assertEquals(Object.hasOwn(await failureReport(report), "failure"), false);
});

Deno.test("unreadable or task-free failure reports never throw or invent a task", async () => {
    for (const report of [undefined, "{", {}, { stats: commandReport.stats, plays: [{}] },
        { stats: { target: { changed: 0, failures: 0, unreachable: 0 } }, plays: [{}] }]) {
        const result = await failureReport(report);
        assertEquals(result.failed_phase, "roles");
        assertEquals(Object.hasOwn(result, "failure"), false);
    }
});

Deno.test("successful converge results have no failure key", async () => {
    const report = structuredClone(commandReport);
    report.stats.target.failures = 0;
    report.plays[0].tasks[0].hosts.target = { changed: false };
    const result = await failureReport(report, 0);
    assertEquals(result.failed_phase, undefined);
    assertEquals(Object.hasOwn(result, "failure"), false);
});


const callbackFixture = (name: string) => JSON.parse(Deno.readTextFileSync(new URL(`./fixtures/node-converge-${name}.json`, import.meta.url)));
for (const [name, task] of [["ignored", "Fatal command"], ["rescued", "Fatal command"], ["always", "Fatal always command"]]) {
    Deno.test(`the last fatal record explains ${name} callback statistics`, async () => {
        const report = callbackFixture(name);
        assertEquals((await failureReport(report)).failure, { task, host: "target", reason: "command_failed", rc: 7 });
    });
}

Deno.test("a rescued callback without fatal statistics succeeds with no failure", async () => {
    const result = await failureReport(callbackFixture("rescued-success"), 0);
    assertEquals(result.failed_phase, undefined);
    assertEquals(Object.hasOwn(result, "failure"), false);
});

Deno.test("a censored unreachable callback identifies its failed host without leaking its name", async () => {
    assertEquals((await failureReport(callbackFixture("hidden-unreachable"), 4)).failure,
        { task: null, host: "target", reason: "hidden" });
});

Deno.test("an unreachable host is not explained by its earlier ignored failure", async () => {
    const report = structuredClone(commandReport);
    const ignored = report.plays[0].tasks[0];
    report.stats.target = { changed: 0, failures: 0, unreachable: 1 };
    report.plays[0].tasks = [ignored, { task: { name: "LEAK_HIDDEN" }, hosts: { target: { censored: "LEAK_MSG" } } }];
    assertEquals((await failureReport(report)).failure, { task: null, host: "target", reason: "hidden" });
    report.plays[0].tasks = [ignored, { task: { name: "Reach the host" }, hosts: { target: { unreachable: true, msg: "LEAK_MSG" } } }];
    assertEquals((await failureReport(report)).failure, { task: "Reach the host", host: "target", reason: "unreachable" });
});

for (const name of ["rendered-secret", "path"]) {
    Deno.test(`a ${name} callback retains the failure while withholding the name and private markers`, async () => {
        assertEquals((await failureReport(callbackFixture(name))).failure, { task: null, host: "target", reason: "assertion" });
    });
}

Deno.test("value-like names are withheld without changing the failure class", async () => {
    for (const name of ["Check secret=value", "Check user@example", "Check deadbeefdeadbeef", "Check abcdEFGH0123+abc", "Check abcDEF0123_-abcDEF",
        "Read .env", "Read C:\\private\\key", "Read ~root", "Check a:b", "Check \"x\"", "Check $HOME", "Check x_y"]) {
        const report = structuredClone(commandReport);
        report.plays[0].tasks[0].task.name = name;
        assertEquals((await failureReport(report)).failure, { ...commandFailure, task: null });
    }
});

Deno.test("only hosts with fatal statistics compete in play and task order, with hub winning ties", async () => {
    const report = structuredClone(commandReport);
    const target = report.plays[0].tasks[0];
    const hub = { task: { name: "Hub task" }, hosts: { hub: { failed: true, rc: 9 } } };
    report.stats.hub = { changed: 0, failures: 1, unreachable: 0 };
    report.plays[0].tasks = [target, hub];
    assertEquals((await failureReport(report)).failure, commandFailure);
    report.plays[0].tasks = [hub, target];
    assertEquals((await failureReport(report)).failure, { task: "Hub task", host: "hub", reason: "command_failed", rc: 9 });
    report.plays[0].tasks = [{ ...target, hosts: { ...target.hosts, ...hub.hosts } }];
    assertEquals((await failureReport(report)).failure, { ...commandFailure, host: "hub", rc: 9 });
    report.stats.hub.failures = 0;
    assertEquals((await failureReport(report)).failure, commandFailure);
});

Deno.test("the roles phase takes the intent's roles_seconds, 900 to 1800, as its timeout, 900 without it, and refuses any other bound before the command", async () => {
    const { Environment } = await import("npm:@marcbachmann/cel-js@7.6.1");
    const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-node-converge-direct.yaml", import.meta.url))) as {
        inputs: { properties: { intent: { properties: Record<string, unknown> } } };
        jobs: { steps: { name: string; task: { inputs: { run: string; env: Record<string, string> } } }[] }[];
    };
    assertEquals(workflow.inputs.properties.intent.properties.roles_seconds, { type: "integer", minimum: 900, maximum: 1800 });
    const step = workflow.jobs.flatMap((job) => job.steps).find((candidate) => candidate.name === "Converge roles")!;
    const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
    const bound = (intent: Record<string, unknown>) => String(cel.evaluate(step.task.inputs.env.ORBIT_ROLES_SECONDS.slice(3, -2).trim(), { inputs: { intent } }));
    const run = async (seconds: string) => {
        const folder = await Deno.makeTempDir();
        try {
            await Deno.mkdir(`${folder}/bin`);
            // The timeout records its argv instead of running the playbook.
            await Deno.writeTextFile(`${folder}/bin/timeout`, `#!/bin/sh\nprintf '%s\\n' "$@" > '${folder}/argv'\n`);
            await Deno.chmod(`${folder}/bin/timeout`, 0o755);
            const output = await new Deno.Command("sh", { args: ["-c", step.task.inputs.run], env: { PATH: `${folder}/bin:/usr/bin:/bin`,
                ORBIT_RUN: folder, ORBIT_ANSIBLE: folder, ORBIT_PLAYBOOK: "operation-node-converge.yml", ORBIT_ROLES_SECONDS: seconds } }).output();
            let argv: string[] = [];
            try { argv = Deno.readTextFileSync(`${folder}/argv`).trim().split("\n"); } catch { /* not reached */ }
            return { code: output.code, first: argv[0] ?? "" };
        } finally { await Deno.remove(folder, { recursive: true }); }
    };
    // An Instance fleet Node's app-dev converge (NodeDesiredConvergeInput::FleetHostRolesSeconds) and every other converge.
    assertEquals([bound({ roles_seconds: 1800 }), bound({})], ["1800", "900"]);
    assertEquals(await run(bound({ roles_seconds: 1800 })), { code: 0, first: "1800" });
    assertEquals(await run(bound({})), { code: 0, first: "900" });
    for (const seconds of ["899", "1801", "5000", "", "900; id"]) assertEquals(await run(seconds), { code: 2, first: "" }, seconds);
});

// docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change
Deno.test("a phase digest moves with what the phases read, and equals the record only when that did not move", async () => {
    const first = await phaseDigests({ recorded_digests: [] }, "1".repeat(64), skippable);
    assertEquals(Object.keys(first.digests), [...skippable]);
    assertEquals(first.unchanged, []);
    assertEquals(new Set(Object.values(first.digests)).size, skippable.length);
    const same = await phaseDigests({ recorded_digests: { agent: first.digests.agent, roles: first.digests.roles } }, "1".repeat(64), skippable);
    assertEquals(same.unchanged, ["agent", "roles"]);
    assertEquals((await phaseDigests({ recorded_digests: first.digests }, "2".repeat(64), skippable)).unchanged, []);
    await assertRejects(() => phaseDigests({ recorded_digests: { input: "1".repeat(64) } }, "1".repeat(64), skippable));
    await assertRejects(() => phaseDigests({ recorded_digests: { agent: "short" } }, "1".repeat(64), skippable));
});

Deno.test("the code digest moves with a file's content and name", async () => {
    const folder = await Deno.makeTempDir();
    try {
        await Deno.mkdir(`${folder}/ansible/tasks`, { recursive: true });
        await Deno.writeTextFile(`${folder}/ansible/tasks/a.yml`, "one");
        const before = await codeDigest([`${folder}/ansible`]);
        assertEquals(await codeDigest([`${folder}/ansible`]), before);
        await Deno.writeTextFile(`${folder}/ansible/tasks/a.yml`, "two");
        const changed = await codeDigest([`${folder}/ansible`]);
        assertEquals(changed === before, false);
        await Deno.rename(`${folder}/ansible/tasks/a.yml`, `${folder}/ansible/tasks/b.yml`);
        assertEquals((await codeDigest([`${folder}/ansible`])) === changed, false);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

/** A stand-in for the role materializer: each role's variables are its intent and its secrets, as build_vars.py writes them. */
const materializer = await Deno.makeTempFile({ suffix: ".py" });
await Deno.writeTextFile(materializer, `import pathlib, sys
folder = pathlib.Path(sys.argv[1])
secrets = sorted(f"{p.name}={p.read_text()}" for p in folder.glob("secret-*"))
for p in folder.glob("secret-*"): p.unlink()
(folder / "vars.yml").write_text((folder / "intent.json").read_text() + "\\n".join(secrets))
`);
globalThis.addEventListener("unload", () => Deno.removeSync(materializer));

/** Input preparation in a fresh run folder, as one Operation of the given ID; the intent names that Operation, as a role's runtime does. */
async function prepared(recorded: Record<string, string>, change: { operationId?: number; plans?: string; secret?: string; code?: [string, string, string]; leaf?: string; admitted?: number; intent?: Record<string, unknown> } = {}) {
    const folder = await Deno.makeTempDir();
    try {
        const operationId = change.operationId ?? 41;
        const ssh = { identity_file: "/test/identity", target: { host: "10.44.0.7", port: 22, user: "orbit", host_keys: ["ssh-ed25519 pinned"] } };
        const certificates = change.leaf === undefined ? {} : { certificates: { leaves: [{ node_id: 7, scope: "role-websocket", domain: "reverb.orbit", version: change.leaf }] } };
        const instances = change.admitted === undefined ? {} : { instances: { cidr: "10.46.0.0/24", bridge_admitted_at: change.admitted } };
        await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ node_id: 7, ssh, roles: [], container: `orbit-analytics-operation-${operationId}-createdb`,
            ...certificates, ...instances, ...change.intent, recorded_digests: recorded }));
        await Deno.writeTextFile(`${folder}/plans.json`, change.plans ?? JSON.stringify({ ci: { runner: "one" } }));
        await Deno.writeTextFile(`${folder}/secret-agent_verifier`, change.secret ?? "v");
        const phases = await convergeInput(folder, operationId, materializer, "/shipped/helper.ts", change.code ?? shipped.code);
        return { phases, file: JSON.parse(await Deno.readTextFile(`${folder}/digests.json`)), mode: (await Deno.stat(`${folder}/digests.json`)).mode! & 0o777,
            vars: parse(await Deno.readTextFile(`${folder}/vars.yml`)) as { converge_intent: Record<string, unknown> } };
    } finally { await Deno.remove(folder, { recursive: true }); }
}

Deno.test("input preparation writes the phase digests and the unchanged phases, and keeps the digest fields out of the Ansible variables", async () => {
    const first = await prepared({});
    const second = await prepared({ software: first.phases.digests.software });
    assertEquals(second.phases.unchanged, ["software"]);
    assertEquals(second.file, second.phases);
    assertEquals(second.mode, 0o600);
    assertEquals(Object.keys(second.vars.converge_intent).sort(), ["container", "node_id", "roles", "ssh"]);
});

Deno.test("a phase whose digest is unchanged reports unchanged with no work, and the result carries every digest", async () => {
    const folder = await Deno.makeTempDir();
    try {
        await reports(folder);
        const digests = Object.fromEntries(skippable.map((phase) => [phase, "a".repeat(64)]));
        await Deno.writeTextFile(`${folder}/digests.json`, JSON.stringify({ digests, unchanged: ["software", "tools"] }));
        // The workflow skipped these phases, so they left no callback.
        for (const name of ["software", "tools"]) await Deno.remove(`${folder}/${name}-meta.json`);
        await Deno.remove(`${folder}/tools-result.json`);
        const result = await convergeResult(folder);
        assertEquals(result.phases.map((phase) => phase.status), ["succeeded", "succeeded", "unchanged", "succeeded", "unchanged", "succeeded", "succeeded"]);
        assertEquals(result.phases.filter((phase) => phase.status === "unchanged").map((phase) => [phase.changed, phase.elapsed_seconds]), [[0, 0], [0, 0]]);
        assertEquals(result.tools, []);
        assertEquals(result.digests, digests);
        assertEquals(result.elapsed_seconds, 5);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("each skippable phase is guarded by the unchanged list of the input preparation; input and check always run", () => {
    const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-node-converge-direct.yaml", import.meta.url))) as {
        jobs: { name: string; steps: { name: string; guard?: string }[] }[];
    };
    for (const job of workflow.jobs.filter((job) => phases.includes(job.name as typeof phases[number]))) {
        assertEquals(job.steps[0].guard, (skippable as readonly string[]).includes(job.name)
            ? `\${{ "${job.name}" in steps["Prepare private input"].outputs.unchanged }}` : undefined);
    }
});

Deno.test("a changed plan or secret value moves the roles digest, and the Operation's own ID does not", async () => {
    const first = await prepared({});
    assertEquals((await prepared(first.phases.digests, { operationId: 42 })).phases.unchanged, [...skippable]);
    // plans.json carries the CI host spec and the ClickHouse files, which the roles phase applies.
    assertEquals((await prepared(first.phases.digests, { plans: JSON.stringify({ ci: { runner: "two" } }) })).phases.unchanged.includes("roles"), false);
    assertEquals((await prepared(first.phases.digests, { secret: "w" })).phases.unchanged.includes("roles"), false);
});

Deno.test("a repeat with another Operation ID and freshly written secrets skips every phase; a moved pin or role moves only its phase", async () => {
    const role = (domain: string) => ({ roles: [{ assignment_id: 5, role: "app-dev", runtime: {}, domain }], selected_roles: ["app-dev"] });
    const pins = (sha256: string) => ({ agent: { url: "https://example.test/cli-v0.1.0/orbit-agent", sha256 }, executor: { url: "https://example.test/cli-v0.1.0/orbit-executor", sha256 }, cli: null });
    const intent = { ...role("one.orbit"), ...pins("a".repeat(64)), recipe: "{}" };
    const first = await prepared({}, { intent });
    assertEquals((await prepared(first.phases.digests, { intent, operationId: 42, secret: "v" })).phases.unchanged, [...skippable]);
    // A release moves the agent and executor pins.
    assertEquals((await prepared(first.phases.digests, { intent: { ...intent, ...pins("b".repeat(64)) }, operationId: 42 })).phases.unchanged, ["software", "roles", "tools", "network"]);
    assertEquals((await prepared(first.phases.digests, { intent: { ...intent, ...role("two.orbit") }, operationId: 42 })).phases.unchanged, ["agent", "software", "tools", "network"]);
    // A field no phase claims, a recipe pin and a secret are read by every phase.
    assertEquals((await prepared(first.phases.digests, { intent: { ...intent, unclaimed: 1 } })).phases.unchanged, []);
    assertEquals((await prepared(first.phases.digests, { intent: { ...intent, recipe: "{\"x\":1}" } })).phases.unchanged, []);
    assertEquals((await prepared(first.phases.digests, { intent, secret: "w" })).phases.unchanged, []);
});

/** Every reference to the converge intent in a file: its field, or "*" for the whole intent. */
function intentFields(text: string): string[] {
    return [...text.matchAll(/(?<![.\w])(?:converge_)?intent\b(?:\.([a-z_]+)|\[['"]([a-z_]+)['"]\]|(?=\s*(?:\||\}\}|\)|,)))/g)].map((match) => match[1] ?? match[2] ?? "*");
}

Deno.test("an unproven apt source in a Tool plan moves the tools digest, so a skipped phase never hides its repair", async () => {
    const tool = (repository_verified: boolean) => ({ tools: [{ id: 3, manager: "apt", package: "tree", repository: { name: "proof" }, repository_verified }] });
    const verified = await prepared({}, { intent: { ...tool(true), recipe: "{}" } });
    assertEquals((await prepared(verified.phases.digests, { intent: { ...tool(true), recipe: "{}" }, operationId: 42 })).phases.unchanged, [...skippable]);
    const unproven = await prepared(verified.phases.digests, { intent: { ...tool(false), recipe: "{}" }, operationId: 42 });
    assertEquals(unproven.phases.unchanged.includes("tools"), false);
});

Deno.test("a phase's own intent fields and run folder entries are read by no other skippable phase", async () => {
    const root = new URL("../ansible", import.meta.url).pathname;
    const resolved = await resolvePhases(root);
    // Every phase reads the shared files: build_vars.py materializes the role folders, and the playbooks are checked task by task below.
    const shared = new Set(sharedFiles);
    const ownTags = (owner: string) => new Set([owner, "input", "check"]);
    for (const [owner, inputs] of Object.entries(phaseInputs)) {
        for (const phase of skippable.filter((phase) => phase !== owner)) {
            const resolution = resolved[phase];
            assertEquals(resolution.whole, false, `${phase} reads the whole bundle`);
            // Only a task or a template sees an Ansible variable; no module or plugin of the bundle reads the converge intent from the task's
            // variables, and a Python file's own `intent` is a module argument.
            for (const file of resolution.files!.filter((file) => !shared.has(file) && /\.(ya?ml|j2)$/.test(file))) {
                const text = await Deno.readTextFile(`${root}/${file}`);
                for (const field of intentFields(text)) assertEquals(field === "*" || inputs!.fields.includes(field), false, `${phase} reads intent.${field} in ${file}`);
                if (inputs!.entries) assertEquals(/\/role-\{\{/.test(text), false, `${phase} reads a role folder in ${file}`);
            }
        }
        // The converge playbooks: a task reads an owned field only under the owner's tag or a tag that never skips; a play aliases the whole intent.
        for (const playbook of new Set(Object.values(entries).flat())) {
            const plays = parse(await Deno.readTextFile(`${root}/${playbook}`)) as Record<string, unknown>[];
            for (const play of plays) {
                const { tasks, tags: playTags, ...rest } = play as { tasks?: Record<string, unknown>[]; tags?: string[] };
                const head = JSON.stringify(rest).replaceAll('"intent":"{{ converge_intent }}"', "");
                for (const field of intentFields(head)) assertEquals(field === "*" || (inputs!.fields.includes(field) && !(playTags ?? []).every((tag) => ownTags(owner).has(tag))), false, `${playbook} play ${play.name} reads intent.${field}`);
                for (const task of tasks ?? []) {
                    const tags = [...(playTags ?? []), ...((task.tags as string[] | undefined) ?? [])];
                    const text = JSON.stringify(task);
                    for (const field of intentFields(text)) {
                        assertEquals(field === "*", false, `${playbook} task ${task.name} reads the whole intent`);
                        if (inputs!.fields.includes(field)) assertEquals(tags.length > 0 && tags.every((tag) => ownTags(owner).has(tag)), true, `${playbook} task ${task.name} reads intent.${field} under ${tags}`);
                    }
                    if (inputs!.entries && /\/role-\{\{/.test(text)) assertEquals(tags.every((tag) => ownTags(owner).has(tag)), true, `${playbook} task ${task.name} reads a role folder`);
                }
            }
        }
    }
});

Deno.test("the version a capture mints for a certificate leaf does not move a digest; the leaf's domain does", async () => {
    const first = await prepared({}, { leaf: "0123456789abcdef" });
    assertEquals((await prepared(first.phases.digests, { leaf: "fedcba9876543210", operationId: 42 })).phases.unchanged, [...skippable]);
    assertEquals((await prepared(first.phases.digests, { leaf: "fedcba9876543210" })).phases.unchanged, [...skippable]);
    assertEquals((await prepared({}, { leaf: "0123456789abcdef" })).phases.digests.roles === (await prepared({})).phases.digests.roles, false);
});

Deno.test("the admission time of the Instance bridge does not move a digest; the bridge does", async () => {
    const first = await prepared({}, { admitted: 1763330000123456 });
    assertEquals((await prepared(first.phases.digests, { admitted: 1763330099654321 })).phases.unchanged, [...skippable]);
    assertEquals((await prepared({}, { admitted: 1763330000123456 })).phases.digests.roles === (await prepared({})).phases.digests.roles, false);
});

Deno.test("an Instance that comes or goes does not move the roles digest; a changed T3 drop-in or other role input does", async () => {
    const t3 = (container: string, program = "/opt/orbit-agent/current/bin/t3", content = "ExecStart=t3 serve") =>
        ({ project: "orbit-instances", container, program, files: [{ path: "/etc/systemd/system/t3.service.d/server.conf", mode: "0644", content }] });
    const named = (container: string, hostname: string) => ({ project: "orbit-instances", container, hostname, pretty: hostname });
    const host = (instances: Record<string, unknown>, domain = "one.orbit") => ({ roles: [{ assignment_id: 5, role: "app-dev", domain, runtime: { instances: {
        cidr: "10.46.0.0/24", ingress: [], ...instances } } }], selected_roles: ["app-dev"] });
    const before = host({ machine_names: [named("instance-1", "default.web"), named("instance-2", "a.web")], t3_files: [t3("instance-2")] });
    const first = await prepared({}, { intent: before });
    // Instance 2 went, Instances 3 and 4 came: the same drop-ins, other containers and names.
    const churned = host({ machine_names: [named("instance-1", "default.web"), named("instance-3", "b.web"), named("instance-4", "c.web")],
        t3_files: [t3("instance-3"), t3("instance-4")] });
    assertEquals((await prepared(first.phases.digests, { intent: churned, operationId: 42 })).phases.unchanged, [...skippable]);
    // Every phase still reads the lists: the roles phase writes them into the containers.
    assertEquals((await prepared(first.phases.digests, { intent: churned })).vars.converge_intent.roles, churned.roles);
    const moved = (intent: Record<string, unknown>) => prepared(first.phases.digests, { intent }).then((done) => done.phases.unchanged);
    assertEquals(await moved(host({ machine_names: [], t3_files: [t3("instance-2", undefined, "ExecStart=t3 serve --new")] })), ["agent", "software", "tools", "network"]);
    assertEquals(await moved(host({ machine_names: [], t3_files: [t3("instance-2", "/opt/orbit-agent/current/bin/conn-server")] })), ["agent", "software", "tools", "network"]);
    assertEquals(await moved(host({ machine_names: [], t3_files: [t3("instance-2")], ingress: [{ action: "allow" }] })), ["agent", "software", "tools", "network"]);
    assertEquals(await moved({ ...host({ machine_names: [], t3_files: [t3("instance-2")] }, "two.orbit") }), ["agent", "software", "tools", "network"]);
    // A field of a T3 entry that the projection does not know still counts.
    assertEquals(await moved(host({ machine_names: [], t3_files: [{ ...t3("instance-2"), user: "agent" }] })), ["agent", "software", "tools", "network"]);
});

Deno.test("a changed shipped Tool file that the Tool helper imports moves the tools digest", async () => {
    const tree = await Deno.makeTempDir();
    try {
        const operations = `${tree}/extensions/behaviors/operations`;
        await Deno.mkdir(`${tree}/ansible`, { recursive: true });
        await Deno.mkdir(`${operations}/ansible-input`, { recursive: true });
        await Deno.mkdir(`${operations}/tools`, { recursive: true });
        await Deno.writeTextFile(`${tree}/ansible/build_vars.py`, "builder");
        await Deno.writeTextFile(`${operations}/ansible-input/converge_tool.ts`, 'import "../tools/native.ts";');
        await Deno.writeTextFile(`${operations}/tools/native.ts`, "one");
        const code = phaseCode(`${tree}/ansible/build_vars.py`, `${operations}/ansible-input/converge_tool.ts`);
        const first = await prepared({}, { code });
        await Deno.writeTextFile(`${operations}/tools/native.ts`, "two");
        assertEquals((await prepared(first.phases.digests, { code })).phases.unchanged.includes("tools"), false);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

// docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change: one record for converge and role Operations.
Deno.test("a record of the other kind's phases never matches and never refuses the run", async () => {
    const role = await phaseDigests({ recorded_digests: [] }, "1".repeat(64), roleSkippable);
    assertEquals(Object.keys(role.digests), [...roleSkippable]);
    // A converge after a role Operation reads the role's record, and the reverse; "tools" is in both, but the kinds read different input.
    assertEquals((await phaseDigests({ recorded_digests: role.digests }, "2".repeat(64), skippable)).unchanged, []);
    const converge = await phaseDigests({ recorded_digests: [] }, "2".repeat(64), skippable);
    assertEquals((await phaseDigests({ recorded_digests: converge.digests }, "1".repeat(64), roleSkippable)).unchanged, []);
    await assertRejects(() => phaseDigests({ recorded_digests: { check: "1".repeat(64) } }, "1".repeat(64), roleSkippable));
});

Deno.test("a role Operation's digests skip every role phase only when its written input, kind and code did not move", async () => {
    const code = await Deno.makeTempDir();
    const role = async (recorded: Record<string, string>, { operationId = 7, vars = "kind: node.role_converge\nnote: operation-7\n" } = {}) => {
        const folder = await Deno.makeTempDir();
        try {
            await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ role: "app-prod", recorded_digests: recorded }));
            await Deno.writeTextFile(`${folder}/vars.yml`, vars.replaceAll("operation-7", `operation-${operationId}`));
            await Deno.writeTextFile(`${folder}/inventory.yml`, "{}");
            const phases = await roleDigests(folder, operationId, [code]);
            assertEquals(JSON.parse(await Deno.readTextFile(`${folder}/digests.json`)), phases);
            return phases;
        } finally { await Deno.remove(folder, { recursive: true }); }
    };
    try {
        await Deno.writeTextFile(`${code}/build_vars.py`, "one");
        const first = await role({});
        assertEquals(first.unchanged, []);
        assertEquals((await role(first.digests, { operationId: 8 })).unchanged, [...roleSkippable]);
        // The kind is an input: a role addition's record does not skip the first convergence.
        assertEquals((await role(first.digests, { vars: "kind: node.role_add\nnote: operation-7\n" })).unchanged, []);
        await Deno.writeTextFile(`${code}/build_vars.py`, "two");
        assertEquals((await role(first.digests)).unchanged, []);
    } finally { await Deno.remove(code, { recursive: true }); }
    // The plan of the Tool Managers runs after the digest, so its code is part of it.
    // So are the role's files outside ansible/, which a role Operation reads from the worker's bundle (release 2159).
    assertEquals(rolePhaseCode("/x/ansible/build_vars.py", "/x/ext/ansible-input/converge_tool.ts"),
        ["/x/ansible", "/x/ext/ansible-input/converge_tool.ts", "/x/ext/tools", "/x/ext/ansible-input/role_add_plans.ts",
            ...roleSiblings.map((path) => `/x/${path}`)]);
});

Deno.test("a node:add role run compares with the empty record its enrollment intent carries, and an intent with no record is refused", async () => {
    const role = async (intent: Record<string, unknown>) => {
        const folder = await Deno.makeTempDir();
        try {
            await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify(intent));
            await Deno.writeTextFile(`${folder}/vars.yml`, "kind: node.role_add\n");
            return await roleDigests(folder, 9, []);
        } finally { await Deno.remove(folder, { recursive: true }); }
    };
    // PHP sends the enrollment's empty record (NativeNodeRoleAddInput) as an empty list.
    assertEquals((await role({ role: "app-prod", recorded_digests: [] })).unchanged, []);
    await assertRejects(() => role({ role: "app-prod" }));
});

Deno.test("each skippable role phase is guarded by the role digests; every other role step runs as before", () => {
    const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-node-role-add.yaml", import.meta.url))) as {
        jobs: { name: string; steps: { name: string; guard?: string }[] }[];
    };
    const owner: Record<string, string> = { prerequisites: "prerequisites", tools_read: "tools", tools_plan: "tools", phase_13: "tools", node: "node", firewall: "firewall" };
    const skip = (phase: string) => `"${phase}" in steps["Compare phase digests"].outputs.unchanged`;
    for (const job of workflow.jobs) {
        for (const step of job.steps) {
            const phase = owner[job.name];
            if (phase === undefined) assertEquals(step.guard?.includes("Compare phase digests") ?? false, false, step.name);
            else assertEquals(step.guard?.includes(skip(phase)), true, step.name);
        }
    }
});

Deno.test("input preparation with an empty code list writes every phase digest and skips only on an equal record", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const ssh = { identity_file: "/test/identity", target: { host: "10.44.0.7", port: 22, user: "orbit", host_keys: ["ssh-ed25519 pinned"] } };
        const write = async (recorded: Record<string, string>) => {
            await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ node_id: 7, ssh, roles: [], recorded_digests: recorded }));
            await Deno.writeTextFile(`${folder}/plans.json`, "{}");
            for await (const entry of Deno.readDir(folder)) if (!["intent.json", "plans.json"].includes(entry.name)) await Deno.remove(`${folder}/${entry.name}`, { recursive: true });
            return await convergeInput(folder, 701, "/unused", "/fixture/converge_tool.ts", []);
        };
        const first = await write({});
        assertEquals([Object.keys(first.digests), first.unchanged], [[...skippable], []]);
        assertEquals((await write(first.digests)).unchanged, [...skippable]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the result names a phase whose digest always moves because code above the bundle cannot be read", async () => {
    const folder = await Deno.makeTempDir();
    try {
        await reports(folder);
        const digests = Object.fromEntries(skippable.map((phase) => [phase, "a".repeat(64)]));
        await Deno.writeTextFile(`${folder}/digests.json`, JSON.stringify({ digests, unchanged: [], moving: { roles: "../scripts/release-container.py cannot be read: NotFound" } }));
        const result = await convergeResult(folder);
        assertEquals(result.digests_moving, { roles: "../scripts/release-container.py cannot be read: NotFound" });
        await Deno.writeTextFile(`${folder}/digests.json`, JSON.stringify({ digests, unchanged: [] }));
        assertEquals("digests_moving" in await convergeResult(folder), false);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

// Release 2159: the nested app-prod converge failed at "Bundle the shared Vite+ selection rules on the Swamp Node", because the staged
// ansible/ had no ../../extensions beside it. Every path a staged file reaches outside ansible/ must be a staged sibling.
Deno.test("every file the converge's roles read outside ansible/ is staged beside it and shipped", async () => {
    const root = new URL("../", import.meta.url);
    const reached = new Set<string>();
    const follow = async (path: string) => {
        const text = await Deno.readTextFile(new URL(path, root));
        for (const [, spec] of text.matchAll(/from\s+"(\.{1,2}\/[^"]+)"/g)) {
            const target = new URL(spec, new URL(path, root)).pathname.slice(root.pathname.length);
            if (target.startsWith("ansible/")) continue;
            if (!reached.has(target)) { reached.add(target); await follow(target); }
        }
    };
    const walk = async (dir: string): Promise<void> => {
        for await (const entry of Deno.readDir(new URL(dir, root))) {
            const path = `${dir}/${entry.name}`;
            if (entry.isDirectory) await walk(path);
            else if (/\.ya?ml$/.test(entry.name)) {
                for (const [, rest] of (await Deno.readTextFile(new URL(path, root))).matchAll(/\{\{ playbook_dir \}\}\/\.\.\/([^"'\s}]+)/g)) reached.add(rest);
            } else if (entry.name.endsWith(".ts")) await follow(path);
        }
    };
    await walk("ansible");
    assertEquals([...reached].sort(), [...roleSiblings].sort());
    const manifest = parse(await Deno.readTextFile(new URL("manifest.yaml", root))) as Record<string, unknown>;
    const shipped = JSON.stringify(manifest);
    for (const path of roleSiblings) assertEquals(shipped.includes(`"${path}"`), true, `${path} is not in swamp/manifest.yaml`);
});

Deno.test("a changed release controller moves every role phase digest", async () => {
    const tree = await Deno.makeTempDir();
    const folder = await Deno.makeTempDir();
    try {
        const operations = `${tree}/extensions/behaviors/operations`;
        for (const path of ["ansible/build_vars.py", "extensions/behaviors/operations/ansible-input/converge_tool.ts",
            "extensions/behaviors/operations/ansible-input/role_add_plans.ts", "extensions/behaviors/operations/tools/native.ts", ...roleSiblings]) {
            await Deno.mkdir(`${tree}/${path.slice(0, path.lastIndexOf("/"))}`, { recursive: true });
            await Deno.writeTextFile(`${tree}/${path}`, path);
        }
        await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ role: "app-prod", recorded_digests: {} }));
        await Deno.writeTextFile(`${folder}/vars.yml`, "kind: node.role_converge\n");
        await Deno.writeTextFile(`${folder}/inventory.yml`, "{}");
        const code = rolePhaseCode(`${tree}/ansible/build_vars.py`, `${operations}/ansible-input/converge_tool.ts`);
        const first = await roleDigests(folder, 7, code);
        await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ role: "app-prod", recorded_digests: first.digests }));
        assertEquals((await roleDigests(folder, 8, code)).unchanged, [...roleSkippable]);
        await Deno.writeTextFile(`${tree}/scripts/production-release-phase`, "changed");
        assertEquals((await roleDigests(folder, 9, code)).unchanged, []);
    } finally {
        await Deno.remove(tree, { recursive: true });
        await Deno.remove(folder, { recursive: true });
    }
});
