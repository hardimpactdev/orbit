import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { convergeResult, phases } from "../extensions/behaviors/operations/ansible-input/converge_result.ts";
import { convergeInput, unsafeYaml } from "../extensions/behaviors/operations/ansible-input/converge_input.ts";
import { toolState } from "../extensions/behaviors/operations/ansible-input/converge_tool.ts";
import { behavior as extension } from "../extensions/behaviors/operations/ansible-input/write.ts";

async function reports(folder: string, failure?: string) {
    for (const name of phases) {
        await Deno.writeTextFile(`${folder}/${name}.json`, JSON.stringify({
            stats: { target: { changed: 0, failures: name === failure ? 1 : 0, unreachable: 0 }, hub: { changed: 17, failures: 0, unreachable: 0 } }, plays: [{}],
        }));
        await Deno.writeTextFile(`${folder}/${name}-meta.json`, JSON.stringify({ exit_code: name === failure ? 2 : 0, elapsed_seconds: 1 }));
    }
    await Deno.writeTextFile(`${folder}/tools-result.json`, "[]");
}

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

Deno.test("a partial Tool failure retains both receipts and stops later phases", async () => {
    const folder = await Deno.makeTempDir();
    try {
        await reports(folder, "tools");
        const tools = [{ id: 1, status: "failed", installed_version: null }, { id: 2, status: "installed", installed_version: "2.3.4" }];
        await Deno.writeTextFile(`${folder}/tools-result.json`, JSON.stringify(tools));
        const result = await convergeResult(folder);
        assertEquals(result.tools, tools);
        assertEquals(result.failed_phase, "tools");
        assertEquals(result.phases[5].status, "skipped");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("private input is mode-protected, literal and limited to target plus recorded hub", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const ssh = { identity_file: "/test/identity", target: { host: "10.44.0.7", port: 22, user: "orbit", host_keys: ["ssh-ed25519 pinned"] } };
        const secret = "literal $HOME {{ lookup('pipe', 'false') }}\nsecond line\n";
        await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ node_id: 7, ssh, network: { hub: { hub_id: 2, ssh: { ...ssh, target: { ...ssh.target, host: "10.44.0.2" } } } }, roles: [] }));
        await Deno.writeTextFile(`${folder}/plans.json`, "{}");
        await Deno.writeTextFile(`${folder}/secret-agent_verifier`, secret);
        await convergeInput(folder, "/unused", "/shipped/helper.ts");
        assertEquals(Object.keys(JSON.parse(await Deno.readTextFile(`${folder}/inventory.json`)).all.hosts), ["target", "hub"]);
        assertEquals(await Deno.readTextFile(`${folder}/known_hosts`), "10.44.0.2 ssh-ed25519 pinned\n10.44.0.7 ssh-ed25519 pinned\n");
        const vars = await Deno.readTextFile(`${folder}/vars.yml`);
        assertEquals(vars.includes(unsafeYaml(secret)), true);
        assertEquals(vars.includes('"kind": !unsafe "node.converge"'), true);
        for (const name of ["inventory.json", "known_hosts", "vars.yml"]) assertEquals((await Deno.stat(`${folder}/${name}`)).mode! & 0o777, 0o600);
        await assertRejects(() => Deno.stat(`${folder}/secret-agent_verifier`), Deno.errors.NotFound);
    } finally { await Deno.remove(folder, { recursive: true }); }
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

const apt = { node_id: 7, user: "orbit", tool: { manager: "apt", package: "tree", version_constraint: "^2.2", protected: [] } };
const aptFacts = { version: { rc: 0, stdout: "apt 3.1.16 (amd64)" }, query: { rc: 0, stdout: "tree\tinstall ok installed\t2.2.1-1\n" } };
Deno.test("matching Tool inventory is a read-only no-op", () => {
    assertEquals(toolState("inspect", { ...apt, ...aptFacts }), { installed_version: "2.2.1-1", needs_write: false });
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
        jobs: { name: string; dependsOn?: { job: string; condition: { type: string } }[]; steps: { name: string; task: { inputs: { run?: string } } }[] }[];
    };
    const jobs = workflow.jobs.filter((job) => phases.includes(job.name as typeof phases[number]));
    assertEquals(jobs.map((job) => job.name), [...phases]);
    for (let i = 0; i < jobs.length; i++) {
        const run = jobs[i].steps[0].task.inputs.run!;
        assertEquals(run.match(/ansible-playbook/g)?.length, 1);
        assertEquals(run.includes(`--tags ${phases[i]} `), true);
        assertEquals(run.includes(`${phases[i]}-meta.json`), true);
        if (i) assertEquals(jobs[i].dependsOn, [{ job: phases[i - 1], condition: { type: "succeeded" } }]);
    }
    assertEquals(workflow.jobs.find((job) => job.name === "converge")?.dependsOn, [{ job: "check", condition: { type: "always" } }]);
});
