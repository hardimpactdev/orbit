import { assertEquals, assertMatch } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";

// The fleet.agent_tools run (ADR 0255): its workflow, and the decisions of its playbook run against real Ansible on this machine.
const ansible = fileURLToPath(new URL("../ansible/", import.meta.url));
const playbook = `${ansible}operation-fleet-agent-tools.yml`;
type Task = Record<string, unknown>;
type Play = { name: string; tags: string[]; vars: Record<string, unknown>; tasks: Task[] };
const plays = parse(await Deno.readTextFile(playbook)) as Play[];
const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-fleet-agent-tools.yaml", import.meta.url))) as Record<string, any>;
const job = (name: string) => workflow.jobs.find((entry: Record<string, unknown>) => entry.name === name);
const step = (jobName: string, name: string) => job(jobName).steps.find((entry: Record<string, unknown>) => entry.name === name);

async function ansiblePlaybook(args: string[], env: Record<string, string>) {
    const result = await new Deno.Command("ansible-playbook", {
        args, stdout: "piped", stderr: "piped", env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default", ...env },
    }).output();
    return { code: result.code, output: new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr) };
}

/** Runs a play on this machine and returns its exit code and output. */
async function play(folder: string, vars: Record<string, unknown>, tasks: unknown[], env: Record<string, string> = {}) {
    const path = `${folder}/play.json`;
    await Deno.writeTextFile(path, JSON.stringify([{ hosts: "localhost", connection: "local", gather_facts: false, vars, tasks }]));
    return await ansiblePlaybook(["-i", "localhost,", path], env);
}

Deno.test("the Gateway reads the report step, and every phase is a bounded tag of the playbook", () => {
    const declared = Deno.readTextFileSync(new URL("../../apps/gateway/app/Domain/Fleet/direct-operations.php", import.meta.url));
    assertMatch(declared, /'fleet\.agent_tools' => \[\s*'workflow' => 'operation-fleet-agent-tools',[\s\S]*?'terminal_result' => \['job' => 'report', 'step' => 'Report the run', 'data' => 'fleet-agent-tools-report'\]/);
    assertEquals(step("report", "Report the run").task.behaviorName, "fleet-agent-tools-report");
    const phases = [step("hosts", "Update the agent CLIs"), step("router", "Stage the router release"), step("router", "Check the router"), step("report", "Collect the results")];
    assertEquals(phases.map((entry) => entry.task.inputs.run.match(/^timeout (\d+) .* --tags ([\w-]+) /).slice(1)),
        [["1200", "cli"], ["300", "router-stage"], ["300", "router-check"], ["60", "report"]]);
    assertEquals(plays.map((entry) => entry.tags), [["cli", "router-stage", "router-check"], ["cli"], ["router-stage"], ["router-check"], ["report"]]);
});

Deno.test("the hosts and the router run beside each other, and the report waits for both whatever they did", () => {
    assertEquals(job("hosts").dependsOn, [{ job: "input", condition: { type: "succeeded" } }]);
    assertEquals(job("router").dependsOn, [{ job: "input", condition: { type: "succeeded" } }]);
    assertEquals(job("report").dependsOn.slice(1), [{ job: "hosts", condition: { type: "always" } }, { job: "router", condition: { type: "always" } }]);
});

Deno.test("the router restarts through the Process restart workflow with the admitted intent, also to roll back", () => {
    for (const [name, flag, value] of [["Restart the router", "Read the stage", "restart"], ["Restart the release before", "Read the check", "rollback"]]) {
        const restart = step("router", name);
        assertEquals([restart.task.type, restart.task.workflowIdOrName, restart.task.inputs.kind, restart.task.inputs.intent],
            ["workflow", "operation-process-control", "process.restart", "${{ inputs.intent.router.restart }}"]);
        assertEquals(restart.guard, `\${{ steps["${flag}"].outputs.stdout.trim() != "${value}" }}`);
        // A failed restart still reaches the check, which flips the link back, and the report, which fails the run.
        assertEquals(restart.allowFailure, true);
    }
    assertEquals(step("router", "Check the router").dependsOn, [{ step: "Restart the router", condition: { type: "completed" } }]);
    assertEquals(step("router", "Check the router").task.inputs.env.ORBIT_RESTART, '${{ steps["Restart the router"].status }}');
});

Deno.test("a host updates the configured Vite+ globals it has and Grok's own install, and records each version", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const home = `${folder}/home`;
        await Deno.mkdir(`${home}/.local/share/vite-plus/bin`, { recursive: true });
        await Deno.mkdir(`${home}/.grok/bin`, { recursive: true });
        // A Vite+ with three globals; an install moves the named ones to the next version and logs its arguments.
        await Deno.writeTextFile(`${home}/state`, "@anthropic-ai/claude-code 2.1.322\n@openai/codex 0.167.0\npnpm 11.24.0\n");
        await Deno.writeTextFile(`${home}/.local/share/vite-plus/bin/vp`, `#!/bin/bash
set -e
state="$HOME/state"
if [ "$1" = list ]; then
  printf 'Package  Node version  Binaries\\n---  ---  ---\\n'
  while read -r name version; do printf '%s@%s  24.22.1  bin\\n' "$name" "$version"; done < "$state"
  exit 0
fi
echo "$*" >> "$HOME/installs"
shift 2
for package in "$@"; do
  name="\${package%@latest}"
  awk -v n="$name" '$1 == n { $2 = $2 "-next" } { print }' "$state" > "$state.new" && mv "$state.new" "$state"
done
`, { mode: 0o755 });
        await Deno.writeTextFile(`${home}/.grok/bin/grok`, `#!/bin/bash
if [ "$1" = update ]; then echo 1.0.78 > "$HOME/grok"; exit 0; fi
echo "grok $(cat "$HOME/grok" 2>/dev/null || echo 1.0.77) (abc) [stable]"
`, { mode: 0o755 });
        const cli = plays.find((entry) => entry.name === "Update the agent CLIs")!;
        const vars = { ...cli.vars, run: folder, brew: `${folder}/no-brew`, target: { node_id: 13, name: "shark" },
            intent: { packages: ["@anthropic-ai/claude-code", "@openai/codex", "@xai-official/grok", "opencode-ai"] } };
        const result = await play(folder, vars, cli.tasks, { HOME: home });
        assertEquals(result.code, 0, result.output);
        // Only the configured packages this host has, in one install; pnpm is not an agent tool.
        assertEquals((await Deno.readTextFile(`${home}/installs`)).trim(), "i -g @anthropic-ai/claude-code@latest @openai/codex@latest");
        assertEquals(JSON.parse(await Deno.readTextFile(`${folder}/host-13.json`)), {
            node_id: 13, name: "shark", outcome: "updated", error_code: null, tools: [
                { name: "@anthropic-ai/claude-code", method: "vite-plus", before: "2.1.322", after: "2.1.322-next", outcome: "updated" },
                { name: "@openai/codex", method: "vite-plus", before: "0.167.0", after: "0.167.0-next", outcome: "updated" },
                { name: "grok", method: "grok-update", before: "1.0.77", after: "1.0.78", outcome: "updated" },
            ],
        });
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a failed tool is recorded and the host's other tools still update", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const home = `${folder}/home`;
        await Deno.mkdir(`${home}/.vite-plus/bin`, { recursive: true });
        await Deno.mkdir(`${home}/.grok/bin`, { recursive: true });
        await Deno.writeTextFile(`${home}/.vite-plus/bin/vp`, "#!/bin/bash\necho 'registry down' >&2\nexit 1\n", { mode: 0o755 });
        await Deno.writeTextFile(`${home}/.grok/bin/grok`, "#!/bin/bash\necho 'grok 1.0.77 (abc) [stable]'\n", { mode: 0o755 });
        const cli = plays.find((entry) => entry.name === "Update the agent CLIs")!;
        const vars = { ...cli.vars, run: folder, brew: `${folder}/no-brew`, target: { node_id: 9, name: "beast" }, intent: { packages: ["@openai/codex"] } };
        const result = await play(folder, vars, cli.tasks, { HOME: home });
        assertEquals(result.code, 0, result.output);
        const record = JSON.parse(await Deno.readTextFile(`${folder}/host-9.json`));
        assertEquals([record.outcome, record.error_code], ["failed", "fleet.agent_tools_tool_failed"]);
        assertEquals(record.tools.map((tool: Record<string, string>) => [tool.name, tool.outcome]), [["vite-plus", "failed"], ["grok", "current"]]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("after a passed check the router keeps the new release and the two newest others", async () => {
    const check = plays.find((entry) => entry.name === "Check the router")!;
    const block = (check.tasks[0].block as Task[]);
    const prune = block.find((task) => task.name === "Remove the releases past the newest others")!;
    const folder = await Deno.makeTempDir();
    try {
        const releases = ["7.2.149", "8.0.4", "8.0.17", "8.0.63", "8.0.70"];
        for (const release of releases) await Deno.mkdir(`${folder}/releases/${release}`, { recursive: true });
        const find = block.find((task) => task.name === "Find the releases")!;
        const result = await play(folder, { router: { folder, keep: 2 }, staged: { latest: "8.0.70" } }, [find, prune]);
        assertEquals(result.code, 0, result.output);
        assertEquals([...Deno.readDirSync(`${folder}/releases`)].map((entry) => entry.name).sort(), ["8.0.17", "8.0.63", "8.0.70"]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a failed check flips current back to the release before and asks the workflow to restart it", async () => {
    const check = plays.find((entry) => entry.name === "Check the router")!;
    const rescue = check.tasks[0].rescue as Task[];
    const folder = await Deno.makeTempDir();
    try {
        await Deno.mkdir(`${folder}/releases/8.0.63`, { recursive: true });
        await Deno.mkdir(`${folder}/releases/8.0.70`, { recursive: true });
        await Deno.symlink("releases/8.0.70", `${folder}/current`);
        const staged = { node_id: 17, name: "services", before: "8.0.63", latest: "8.0.70", after: "8.0.70", outcome: "staged" };
        await Deno.writeTextFile(`${folder}/router.json`, JSON.stringify(staged));
        const vars = { ...check.vars, run: folder, router: { folder }, restart: "succeeded" };
        const result = await play(folder, vars, rescue);
        assertEquals(result.code, 0, result.output);
        assertEquals(await Deno.readLink(`${folder}/current`), "releases/8.0.63");
        assertEquals(await Deno.readTextFile(`${folder}/router-rollback`), "rollback");
        assertEquals(JSON.parse(await Deno.readTextFile(`${folder}/router.json`)),
            { ...staged, after: "8.0.63", outcome: "rolled_back", step: "check", error_code: "fleet.agent_tools_router_check_failed" });
    } finally { await Deno.remove(folder, { recursive: true }); }
});

/** Runs the report phase of the playbook on a run folder and returns its exit code and the report. */
async function report(folder: string, intent: Record<string, unknown>, files: Record<string, unknown>, rollbackRestart = "skipped") {
    await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify(intent));
    for (const [name, content] of Object.entries(files)) await Deno.writeTextFile(`${folder}/${name}`, JSON.stringify(content));
    const result = await ansiblePlaybook(["-i", "localhost,", "--tags", "report", playbook], { ORBIT_RUN: folder, ORBIT_ROLLBACK_RESTART: rollbackRestart });
    return { code: result.code, output: result.output, report: JSON.parse(await Deno.readTextFile(`${folder}/report.json`)) };
}

const hosts = [{ node_id: 9, name: "beast" }, { node_id: 13, name: "shark" }];
const router = { node_id: 17, name: "services" };

Deno.test("the report passes when every host and the router ended well", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const result = await report(folder, { hosts, router }, {
            "host-9.json": { node_id: 9, name: "beast", outcome: "updated", tools: [] },
            "host-13.json": { node_id: 13, name: "shark", outcome: "current", tools: [] },
            "router.json": { node_id: 17, name: "services", before: "8.0.63", latest: "8.0.63", after: "8.0.63", outcome: "current" },
        });
        assertEquals(result.code, 0, result.output);
        assertEquals(result.report.hosts.map((host: Record<string, unknown>) => host.outcome), ["current", "updated"]);
        assertEquals(result.report.router.outcome, "current");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the report fails the run after it wrote a missing host, a cut router or a failed rollback restart", async () => {
    const folder = await Deno.makeTempDir();
    try {
        // shark wrote no record: the phase never reached it.
        let result = await report(folder, { hosts, router: null }, { "host-9.json": { node_id: 9, name: "beast", outcome: "current", tools: [] } });
        assertEquals([result.code !== 0, result.report.hosts.length, result.report.router], [true, 1, null]);
        await Deno.remove(`${folder}/host-9.json`);
        // The link was flipped, but no check ran.
        result = await report(folder, { hosts: [], router }, { "router.json": { node_id: 17, name: "services", before: "8.0.63", latest: "8.0.70", outcome: "staged" } });
        assertEquals([result.code !== 0, result.report.router.outcome, result.report.router.error_code], [true, "failed", "fleet.agent_tools_router_failed"]);
        result = await report(folder, { hosts: [], router }, {
            "router.json": { node_id: 17, name: "services", before: "8.0.63", latest: "8.0.70", after: "8.0.63", outcome: "rolled_back",
                error_code: "fleet.agent_tools_router_check_failed" },
        }, "failed");
        assertEquals([result.code !== 0, result.report.router.outcome, result.report.router.error_code], [true, "rolled_back", "fleet.agent_tools_router_rollback_failed"]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});
