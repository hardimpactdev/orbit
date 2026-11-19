import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";

// The converge's role check reads every service, file, folder and private file in one call
// (docs/reference/node-provisioning.md#check-many-files-in-one-read). It must accept a healthy role and fail each drift
// at the same task and item as a stat or `systemctl is-active` per item did. Use the shape-gate Ansible environment.
const tasks = fileURLToPath(new URL("../ansible/tasks/", import.meta.url));
/** The real role check without its Metrics, Swamp, CI and endpoint parts, which need Docker, Incus or a listener. */
const roleCheck = (parse(await Deno.readTextFile(`${tasks}node-converge-role-check.yml`)) as Array<Record<string, unknown>>).map((task) => {
    const block = task.block as Array<Record<string, unknown>> | undefined;
    if (!block) return task;
    return { ...task, block: block.filter((part) => ["check-files.yml", "check-runtime.yml"].includes(part["ansible.builtin.import_tasks"] as string))
        .map((part) => ({ ...part, "ansible.builtin.import_tasks": tasks + part["ansible.builtin.import_tasks"] })) };
});
const user = new TextDecoder().decode((await new Deno.Command("id", { args: ["-un"], stdout: "piped" }).output()).stdout).trim();
const group = new TextDecoder().decode((await new Deno.Command("id", { args: ["-gn"], stdout: "piped" }).output()).stdout).trim();
const sha256 = async (text: string) =>
    Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text))), (b) => b.toString(16).padStart(2, "0")).join("");

type Drift = (folder: string) => Promise<void> | void;

/** Run the real role check against a role under a test folder; `inactive` units report `inactive` from a stand-in systemctl. */
async function check(drift: Drift = () => {}, inactive: string[] = [], expect: Record<string, unknown> = {}) {
    const folder = await Deno.makeTempDir();
    try {
        await Deno.mkdir(`${folder}/bin`);
        await Deno.writeTextFile(`${folder}/bin/systemctl`, `#!/bin/sh\ncase " ${inactive.join(" ")} " in *" $3 "*) echo inactive; exit 3;; esac\necho active\n`);
        await Deno.chmod(`${folder}/bin/systemctl`, 0o755);
        await Deno.mkdir(`${folder}/etc/role`, { recursive: true });
        await Deno.chmod(`${folder}/etc/role`, 0o750);
        for (const [name, text, mode] of [["role.conf", "role\n", 0o644], ["runtime.conf", "runtime\n", 0o640], ["private.key", "secret\n", 0o600]] as const) {
            await Deno.writeTextFile(`${folder}/etc/role/${name}`, text);
            await Deno.chmod(`${folder}/etc/role/${name}`, mode);
        }
        await drift(folder);
        const file = (name: string, content: string, mode: string, extra = {}) =>
            ({ path: `${folder}/etc/role/${name}`, content, mode, owner: user, group, ...extra });
        const intent = {
            services: [{ name: "orbit-role.service" }],
            files: [file("role.conf", "role\n", "0644"), file("gone.conf", "", "0644", { ensure: "absent" })],
            directories: [{ path: `${folder}/etc/role`, owner: user, group, mode: "0750" }],
            runtime: {
                files: [file("runtime.conf", "runtime\n", "0640")],
                // The same folder twice, as two roles' intents can list it: one read serves both.
                directories: [{ path: `${folder}/etc/role`, owner: user, group, mode: "0750" }],
                secret_files: [{ path: `${folder}/etc/role/private.key`, secret: "role_key", owner: user, group }],
                units: [{ name: "orbit-role-worker.service", unit: "" }].slice(0, 0), groups: [], users: [], probes: [],
                metrics: null, swamp: null, ci_host: false, ...expect,
            },
        };
        await Deno.mkdir(`${folder}/role-7`);
        await Deno.writeTextFile(`${folder}/role-7/vars.yml`, JSON.stringify({ intent, containers: [], secrets: { role_key: "secret\n" } }));
        await Deno.writeTextFile(`${folder}/role-check.json`, JSON.stringify(roleCheck));
        await Deno.writeTextFile(`${folder}/play.json`, JSON.stringify([{
            hosts: "localhost", connection: "local", gather_facts: false,
            vars: { orbit_run: folder, role_plan: { assignment_id: 7 }, kind: "node.converge" },
            tasks: [{ name: "Check the role", "ansible.builtin.import_tasks": `${folder}/role-check.json` }],
        }]));
        const result = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", `${folder}/play.json`], stdout: "piped", stderr: "piped",
            env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default", PATH: `${folder}/bin:${Deno.env.get("PATH")}` },
        }).output();
        return { code: result.code, output: new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr), folder };
    } finally { await Deno.remove(folder, { recursive: true }); }
}

/** The failed task and the item it names. */
function failedAt(output: string, task: string, item: RegExp | null) {
    const tasks = output.split("TASK [");
    const failed = tasks.filter((part) => /^(failed|fatal): /m.test(part));
    assertEquals(failed.length, 1, output);
    assertMatch(failed[0], new RegExp(`^${task}\\]`), output);
    if (item) assertMatch(failed[0], item, output);
}

Deno.test("a healthy role passes the batched check with one read, judging every item", async () => {
    const { code, output } = await check();
    assertEquals(code, 0, output);
    assertEquals(output.split("TASK [Read the checked paths, units and commands in one call]").length - 1, 1, output);
    assertMatch(output, /changed=0\s/);
    // No stat or systemctl call per item remains.
    assert(!output.includes("TASK [Read role file metadata]") && !output.includes("TASK [Read selected role services]"), output);
});

Deno.test("an inactive service fails the check at that service", async () => {
    const { code, output } = await check(() => {}, ["orbit-role.service"]);
    assert(code !== 0, output);
    failedAt(output, "Check role services", /\(item=orbit-role\.service\)[\s\S]*orbit-role\.service is inactive, not active\./);
});

const fileDrifts: Array<[string, Drift, string]> = [
    ["a missing file", (f) => Deno.remove(`${f}/etc/role/role.conf`), "role.conf"],
    ["changed content", (f) => Deno.writeTextFile(`${f}/etc/role/runtime.conf`, "drift\n"), "runtime.conf"],
    ["a changed mode", (f) => Deno.chmod(`${f}/etc/role/role.conf`, 0o600), "role.conf"],
    ["a file that must be absent", (f) => Deno.writeTextFile(`${f}/etc/role/gone.conf`, ""), "gone.conf"],
    ["a folder in place of a file", async (f) => { await Deno.remove(`${f}/etc/role/role.conf`); await Deno.mkdir(`${f}/etc/role/role.conf`); }, "role.conf"],
];
for (const [name, drift, path] of fileDrifts) {
    Deno.test(`${name} fails the check at that file`, async () => {
        const { code, output } = await check(drift);
        assert(code !== 0, output);
        failedAt(output, "Check role files", new RegExp(`\\(item=[^)]*/etc/role/${path.replace(".", "\\.")}\\)`));
    });
}

Deno.test("a changed folder mode fails the check at that folder", async () => {
    const { code, output } = await check((f) => Deno.chmod(`${f}/etc/role`, 0o755));
    assert(code !== 0, output);
    failedAt(output, "Check role folders", /\(item=[^)]*\/etc\/role\)/);
});

Deno.test("a changed private file fails the check without printing it", async () => {
    const { code, output } = await check((f) => Deno.writeTextFile(`${f}/etc/role/private.key`, "other\n"));
    assert(code !== 0, output);
    failedAt(output, "Check private files", null);
    assert(!output.includes(await sha256("other\n")) && !output.includes(await sha256("secret\n")), output);
});

/** Run the real CI check against a stand-in `incus` that answers from `state`; `change` alters the state first. */
async function ciCheck(change: (state: Record<string, unknown>) => void = () => {}) {
    const folder = await Deno.makeTempDir();
    try {
        const plans = {
            ci: {
                pool: "orbit-ci", project: "orbit-ci", acl: "orbit-ci-egress", network: "orbit-ci-br",
                projectConfig: { "features.profiles": "true", "features.networks": "false" },
                networkConfig: { "ipv4.nat": "true" },
                profile: { config: { "limits.cpu": "2" }, devices: {}, description: "Orbit CI runner" },
                egress: [{ action: "allow", destination: "10.0.0.0/8", state: "enabled" }],
            },
        };
        const state: Record<string, unknown> = {
            "/1.0/storage-pools": ["/1.0/storage-pools/orbit-ci"], "/1.0/projects": ["/1.0/projects/default", "/1.0/projects/orbit-ci"],
            "/1.0/network-acls": ["/1.0/network-acls/orbit-ci-egress"], "/1.0/networks": ["/1.0/networks/orbit-ci-br"],
            "/1.0/profiles/default?project=orbit-ci": plans.ci.profile,
            "/1.0/network-acls/orbit-ci-egress": {
                description: "Orbit CI runner boundary", config: {}, ingress: [],
                egress: [{ action: "allow", destination: "10.0.0.0/8", state: "enabled", description: "" }],
            },
            "project orbit-ci features.profiles": "true", "project orbit-ci features.networks": "false", "network orbit-ci-br ipv4.nat": "true",
        };
        change(state);
        await Deno.writeTextFile(`${folder}/state.json`, JSON.stringify(state));
        await Deno.mkdir(`${folder}/bin`);
        await Deno.writeTextFile(`${folder}/bin/incus`, `#!/usr/bin/env python3
import json, sys
state = json.load(open(${JSON.stringify(`${folder}/state.json`)}))
args = sys.argv[1:]
key = args[1] if args[0] == 'query' else ' '.join([args[0]] + args[2:])
if key not in state:
    sys.exit(1)
value = state[key]
print(value if isinstance(value, str) else json.dumps(value))
`);
        await Deno.chmod(`${folder}/bin/incus`, 0o755);
        await Deno.writeTextFile(`${folder}/play.json`, JSON.stringify([{
            hosts: "localhost", connection: "local", gather_facts: false, vars: { plans },
            tasks: [{ name: "Check CI", "ansible.builtin.import_tasks": `${tasks}ci.yml`, vars: { ci_check_only: true } }],
        }]));
        const result = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", `${folder}/play.json`], stdout: "piped", stderr: "piped",
            env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default", PATH: `${folder}/bin:${Deno.env.get("PATH")}` },
        }).output();
        return { code: result.code, output: new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr) };
    } finally { await Deno.remove(folder, { recursive: true }); }
}

Deno.test("healthy CI state passes the CI check with one read", async () => {
    const { code, output } = await ciCheck();
    assertEquals(code, 0, output);
    assertEquals(output.split("TASK [Read the checked paths, units and commands in one call]").length - 1, 1, output);
    assertMatch(output, /changed=0\s/);
});

const ciDrifts: Array<[string, (state: Record<string, unknown>) => void, string, RegExp]> = [
    ["a missing CI project", (s) => { s["/1.0/projects"] = ["/1.0/projects/default"]; }, "Check CI resources", /'collection': 'projects'/],
    ["a changed CI setting", (s) => { s["project orbit-ci features.networks"] = "true"; }, "Check CI settings", /'key': 'features\.networks'/],
    ["a changed CI profile", (s) => { s["/1.0/profiles/default?project=orbit-ci"] = { config: {}, devices: {}, description: "Orbit CI runner" }; },
        "Check the CI profile", /Assertion failed/],
    ["changed CI egress", (s) => { s["/1.0/network-acls/orbit-ci-egress"] = { description: "Orbit CI runner boundary", config: {}, ingress: [], egress: [] }; },
        "Check CI egress", /Assertion failed/],
    ["a CI setting that cannot be read", (s) => { delete s["network orbit-ci-br ipv4.nat"]; }, "Require every CI read",
        /\(item=network orbit-ci-br ipv4\.nat\)[\s\S]*network orbit-ci-br ipv4\.nat could not be read \(exit 1\)\./],
];
for (const [name, change, task, item] of ciDrifts) {
    Deno.test(`${name} fails the CI check at that item`, async () => {
        const { code, output } = await ciCheck(change);
        assert(code !== 0, output);
        failedAt(output, task, item);
    });
}

Deno.test("the check read follows a link when asked and runs each command once, a missing program giving 127", async () => {
    const folder = await Deno.makeTempDir();
    try {
        await Deno.writeTextFile(`${folder}/target`, "cli\n");
        await Deno.symlink(`${folder}/target`, `${folder}/link`);
        await Deno.writeTextFile(`${folder}/play.json`, JSON.stringify([{
            hosts: "localhost", connection: "local", gather_facts: false,
            tasks: [
                { name: "Read", "ansible.builtin.import_tasks": `${tasks}check-read.yml`, vars: {
                    check_read_paths: [`${folder}/link`], check_read_followed: [`${folder}/link`],
                    check_read_commands: { echo: ["echo", "one"], missing: [`${folder}/missing`], failing: ["false"] },
                } },
                { name: "Show", "ansible.builtin.debug": { msg: "RESULT{{ check_read | to_json }}RESULT" } },
            ],
        }]));
        const result = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", `${folder}/play.json`], stdout: "piped", stderr: "piped", env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default" },
        }).output();
        const output = new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr);
        assertEquals(result.code, 0, output);
        const read = JSON.parse(JSON.parse(`"${output.split("RESULT")[1]}"`));
        assertEquals([read.paths[`${folder}/link`].islnk, read.paths[`${folder}/link`].checksum], [true, undefined]);
        assertEquals([read.followed[`${folder}/link`].isreg, read.followed[`${folder}/link`].checksum], [true, await sha256("cli\n")]);
        assertEquals(read.commands, { echo: { rc: 0, stdout: "one\n" }, missing: { rc: 127, stdout: "" }, failing: { rc: 1, stdout: "" } });
    } finally { await Deno.remove(folder, { recursive: true }); }
});
