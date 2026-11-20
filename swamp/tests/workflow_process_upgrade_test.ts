/** process.upgrade (docs/decisions/0273-upgrade-a-process-to-a-new-upstream-version.md): the shipped workflow's steps and guards, and its
 * phases run by real Ansible against a release folder with a stand-in systemctl, in the order the workflow runs them. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = { name: string; guard?: string; writes?: boolean; dependsOn?: { step: string; condition: { type: string } }[]; task: { inputs: { run?: string; env?: Record<string, string> } } };
const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-process-upgrade.yaml", import.meta.url))) as {
    autoContinue: boolean;
    jobs: { name: string; steps: Step[] }[];
};
const steps = workflow.jobs.flatMap((job) => job.steps);
const step = (name: string) => steps.find((candidate) => candidate.name === name)!;
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const shipped = new URL("../ansible/", import.meta.url).pathname.replace(/\/$/, "");
const phases = ["Read the installed release", "Download the release", "Unpack the release", "Check the binary", "Switch current", "Restart the Process", "Verify it runs", "Keep the previous release"];
const tags: Record<string, string> = Object.fromEntries(phases.concat("Switch back").map((name) => [name, /--tags (\S+)/.exec(step(name).task.inputs.run!)![1]]));

/** Whether a step's guard skips it, for the step statuses so far and the admitted version arguments. */
function skips(name: string, statuses: Record<string, string>, versionArgs: string[] = ["--help"]): boolean {
    const guard = step(name).guard;
    if (guard === undefined) return false;
    const context = { inputs: { intent: { upgrade: { version_args: versionArgs } } }, steps: Object.fromEntries(Object.entries(statuses).map(([key, status]) => [key, { status }])) };
    return Boolean(cel.evaluate(guard.slice(3, -2).trim(), context));
}

Deno.test("the upgrade runs one tagged phase per step, switches back only after a failure from the switch on, and takes no secret", () => {
    assertEquals(workflow.autoContinue, false);
    assertEquals(steps.map((candidate) => candidate.name), ["input", ...phases, "Switch back", "result", "cleanup"]);
    for (const name of [...phases, "Switch back"]) {
        assert(/^timeout \d+ \/opt\/orbit\/ansible\/venv\/bin\/ansible-playbook -i localhost, --tags [a-z]+ "\$ORBIT_ANSIBLE\/operation-process-upgrade\.yml"$/.test(step(name).task.inputs.run!), name);
    }
    assertEquals(Object.values(tags), ["read", "download", "unpack", "check", "switch", "restart", "verify", "keep", "rollback"]);
    assertEquals(step("result").dependsOn, [{ step: "Switch back", condition: { type: "always" } }]);
    assert(!JSON.stringify(workflow).includes("vault.get"));
    // A phase after a failed one is skipped; the binary check is skipped without version arguments.
    assert(skips("Download the release", { "Read the installed release": "failed" }));
    assert(!skips("Download the release", { "Read the installed release": "succeeded" }));
    assert(skips("Check the binary", { "Unpack the release": "succeeded" }, []));
    assert(skips("Switch current", { "Unpack the release": "failed", "Check the binary": "skipped" }));
    // The switch back runs after a failure of the switch, the restart, the check or the keep, never after one before the switch.
    for (const failed of ["Switch current", "Restart the Process", "Verify it runs", "Keep the previous release"]) {
        assert(!skips("Switch back", { [failed]: "failed" }), failed);
    }
    for (const failed of ["Read the installed release", "Download the release", "Unpack the release", "Check the binary"]) {
        assert(skips("Switch back", { [failed]: "failed", "Switch current": "skipped", "Restart the Process": "skipped", "Verify it runs": "skipped", "Keep the previous release": "skipped" }), failed);
    }
    assert(skips("Switch back", { "Switch current": "succeeded", "Restart the Process": "succeeded", "Verify it runs": "succeeded", "Keep the previous release": "succeeded" }));
});

const available = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;

type Fixture = { releases?: Record<string, "good" | "bad">; current?: string; bad?: boolean; sha?: string; versionText?: string; rollbackBreaks?: boolean };

/** Runs the phases as the workflow does: in order until one fails; after a failure from the switch on, the switch back. */
async function upgrade(fixture: Fixture): Promise<{ ran: string[]; result: Record<string, unknown>; current: string; releases: string[]; restarts: number; folder: string; output: string }> {
    const root = Deno.makeTempDirSync();
    const folder = `${root}/app`;
    const sh = (script: string) => new Deno.Command("sh", { args: ["-c", script] }).outputSync();
    for (const [version, kind] of Object.entries(fixture.releases ?? { "8.0.17": "good", "8.0.63": "good" })) {
        Deno.mkdirSync(`${folder}/releases/${version}`, { recursive: true });
        Deno.writeTextFileSync(`${folder}/releases/${version}/cli-proxy-api`, `#!/bin/sh\necho "CLIProxyAPI Version: ${version}"\n`);
        Deno.chmodSync(`${folder}/releases/${version}/cli-proxy-api`, 0o755);
        if (kind === "bad") Deno.writeTextFileSync(`${folder}/releases/${version}/bad`, "");
    }
    Deno.symlinkSync(fixture.current ?? "releases/8.0.63", `${folder}/current`);
    // The new release: an archive with the binary, which crashes at once in a `bad` release.
    Deno.mkdirSync(`${root}/asset`);
    Deno.writeTextFileSync(`${root}/asset/cli-proxy-api`, `#!/bin/sh\necho "CLIProxyAPI Version: ${fixture.versionText ?? "8.0.80"}"\n`);
    Deno.chmodSync(`${root}/asset/cli-proxy-api`, 0o755);
    if (fixture.bad) Deno.writeTextFileSync(`${root}/asset/bad`, "");
    assert(sh(`tar -C '${root}/asset' -czf '${root}/CLIProxyAPI_8.0.80_linux_amd64.tar.gz' .`).success);
    const sha = fixture.sha ?? new TextDecoder().decode(sh(`sha256sum '${root}/CLIProxyAPI_8.0.80_linux_amd64.tar.gz'`).stdout).split(" ")[0];
    // systemctl: a restart counts; `show` reads the release `current` names, whose unit restarts on every read when it is bad. With
    // `rollbackBreaks`, every release counts as bad once the switch back began.
    Deno.mkdirSync(`${root}/bin`);
    Deno.writeTextFileSync(`${root}/bin/systemctl`, `#!/bin/sh
state='${root}/state'
case "$1" in
  restart) echo restart >> "$state.calls"; [ -e '${root}/rollback' ] && [ -e "$state.calls" ] && [ "$(wc -l < "$state.calls")" -gt 1 ] && touch "$state.broken"; exit 0 ;;
  show) n=$(cat "$state.n" 2>/dev/null || echo 0)
    case "$2" in --property=*) ;; *) printf 'Id=orbit-process-103-ai-router.service\\nLoadState=loaded\\nActiveState=active\\nSubState=running\\nUnitFileState=enabled\\nNRestarts=%s\\n' "$n"; exit 0 ;; esac
    if [ -e '${folder}/current/bad' ] || [ -e "$state.broken" ]; then n=$((n + 1)); echo "$n" > "$state.n"; fi
    printf 'Id=orbit-process-103-ai-router.service\\nLoadState=loaded\\nActiveState=active\\nSubState=running\\nUnitFileState=enabled\\nNRestarts=%s\\n' "$n"; exit 0 ;;
  *) exit 0 ;;
esac
`);
    Deno.chmodSync(`${root}/bin/systemctl`, 0o755);
    if (fixture.rollbackBreaks) Deno.writeTextFileSync(`${root}/rollback`, "");
    Deno.mkdirSync(`${root}/run`);
    Deno.writeTextFileSync(`${root}/run/intent.json`, JSON.stringify({
        ssh_execution: { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } },
        upgrade: { process_id: 103, name: "orbit-process-103-ai-router.service", folder, binary: "cli-proxy-api", version: "8.0.80",
            asset: "CLIProxyAPI_8.0.80_linux_amd64.tar.gz", url: `file://${root}/CLIProxyAPI_8.0.80_linux_amd64.tar.gz`, sha256: sha, version_args: ["--help"] },
    }));
    const ran: string[] = [];
    let output = "";
    const phase = async (name: string) => {
        ran.push(name);
        const done = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", "--tags", tags[name], "-e", "ansible_connection=local", "-e", "ansible_become=false",
                "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", "-e", "verify_delay=0", `${shipped}/operation-process-upgrade.yml`],
            env: { ORBIT_RUN: `${root}/run`, PATH: `${root}/bin:${Deno.env.get("PATH")}`, ANSIBLE_HOME: `${root}/ansible`, ANSIBLE_NOCOLOR: "1",
                ANSIBLE_CONFIG: `${shipped}/ansible.cfg`, ANSIBLE_COLLECTIONS_PATH: Deno.env.get("ANSIBLE_COLLECTIONS_PATH") ?? "" },
        }).output();
        output += new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr);
        return done.success;
    };
    const statuses: Record<string, string> = {};
    for (const name of phases) {
        if (skips(name, statuses)) { statuses[name] = "skipped"; continue; }
        statuses[name] = await phase(name) ? "succeeded" : "failed";
    }
    if (!skips("Switch back", statuses)) await phase("Switch back");
    const releases = [...Deno.readDirSync(`${folder}/releases`)].map((entry) => entry.name).sort();
    const restarts = (() => { try { return Deno.readTextFileSync(`${root}/state.calls`).trim().split("\n").length; } catch { return 0; } })();
    return { ran, result: JSON.parse(Deno.readTextFileSync(`${root}/run/result.json`)), current: Deno.readLinkSync(`${folder}/current`), releases, restarts, folder: root, output };
}

const cleanup = (root: string) => Deno.removeSync(root, { recursive: true });

Deno.test({ name: "an upgrade unpacks the checked release, switches the relative link, restarts once and keeps only the release before", ignore: !available, async fn() {
    const run = await upgrade({});
    try {
        assertEquals(run.result, { before: "8.0.63", after: "8.0.80", outcome: "upgraded", pruned: ["8.0.17"] }, run.output);
        assertEquals(run.current, "releases/8.0.80");
        assertEquals(run.releases, ["8.0.63", "8.0.80"]);
        assertEquals(run.restarts, 1);
        assertEquals(run.ran, phases);
        assertEquals(Deno.readTextFileSync(`${run.folder}/app/releases/8.0.80/.orbit-sha256`).length, 64);
    } finally {
        cleanup(run.folder);
    }
} });

Deno.test({ name: "a current that is not a relative link to a release fails with process.artifact_layout_invalid before any change", ignore: !available, async fn() {
    for (const current of ["/opt/elsewhere", "releases/9.9.9", "../releases/8.0.63"]) {
        const run = await upgrade({ current });
        try {
            assertEquals(run.result.error_code, "process.artifact_layout_invalid", run.output);
            assertEquals(run.ran, ["Read the installed release"]);
            assertEquals(run.current, current);
            assertEquals(run.releases, ["8.0.17", "8.0.63"]);
            assertEquals(run.restarts, 0);
        } finally {
            cleanup(run.folder);
        }
    }
} });

Deno.test({ name: "a download whose checksum or binary does not match fails before the switch and leaves current alone", ignore: !available, async fn() {
    for (const fixture of [{ sha: "0".repeat(64) }, { versionText: "8.0.79" }]) {
        const run = await upgrade(fixture);
        try {
            assertEquals(run.result.error_code, "process.upgrade_failed", run.output);
            assertEquals(run.current, "releases/8.0.63");
            assertEquals(run.restarts, 0);
            assert(!run.ran.includes("Switch back"));
        } finally {
            cleanup(run.folder);
        }
    }
} });

Deno.test({ name: "a release that restarts after the switch is switched back to the release before and fails with process.upgrade_rolled_back", ignore: !available, async fn() {
    const run = await upgrade({ bad: true });
    try {
        assertEquals(run.result, { error_code: "process.upgrade_rolled_back", before: "8.0.63", after: "8.0.80", outcome: "rolled_back", pruned: [] }, run.output);
        assertEquals(run.current, "releases/8.0.63");
        assertEquals(run.releases, ["8.0.17", "8.0.63", "8.0.80"]);
        assertEquals(run.restarts, 2);
        assertEquals(run.ran.slice(-2), ["Verify it runs", "Switch back"]);
    } finally {
        cleanup(run.folder);
    }
} });

Deno.test({ name: "a switch back whose release does not run either fails with process.rollback_failed", ignore: !available, async fn() {
    const run = await upgrade({ bad: true, rollbackBreaks: true });
    try {
        assertEquals(run.result.error_code, "process.rollback_failed", run.output);
        assertEquals(run.current, "releases/8.0.63");
    } finally {
        cleanup(run.folder);
    }
} });

Deno.test({ name: "an upgrade to the installed release changes nothing and ends unchanged", ignore: !available, async fn() {
    const run = await upgrade({ current: "releases/8.0.80", releases: { "8.0.63": "good", "8.0.80": "good" } });
    try {
        assertEquals(run.result, { before: "8.0.80", after: "8.0.80", outcome: "unchanged", pruned: [] }, run.output);
        assertEquals(run.releases, ["8.0.63", "8.0.80"]);
        assertEquals(run.restarts, 0);
    } finally {
        cleanup(run.folder);
    }
} });
