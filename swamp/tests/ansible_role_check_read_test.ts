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
        await Deno.mkdir(`${folder}/etc/role/conf.d`);
        await Deno.writeTextFile(`${folder}/etc/role/conf.d/extra.conf`, "extra\n");
        await Deno.chmod(`${folder}/etc/role/conf.d/extra.conf`, 0o644);
        await drift(folder);
        const file = (name: string, content: string, mode: string, extra = {}) =>
            ({ path: `${folder}/etc/role/${name}`, content, mode, owner: user, group, ...extra });
        const intent = {
            services: [{ name: "orbit-role.service" }],
            files: [file("role.conf", "role\n", "0644"), file("gone.conf", "", "0644", { ensure: "absent" }), file("conf.d/extra.conf", "extra\n", "0644")],
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

/** The check reads a run made, without the skipped ones. */
const reads = (output: string) =>
    output.split("TASK [Read the checked paths, units and commands in one call]").slice(1).filter((part) => /^(ok|changed): /m.test(part.split("TASK [")[0])).length;

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

Deno.test("a file in place of a public path's folder fails at that path, beside a private file", async () => {
    const { code, output } = await check(async (f) => {
        await Deno.remove(`${f}/etc/role/conf.d`, { recursive: true });
        await Deno.writeTextFile(`${f}/etc/role/conf.d`, "");
    });
    assert(code !== 0, output);
    failedAt(output, "Require every checked path", /\(item=[^)]*\/etc\/role\/conf\.d\/extra\.conf\)[\s\S]*Not a directory/);
    assert(!output.includes(await sha256("secret\n")), output);
});

Deno.test("a changed private file fails the check without printing it", async () => {
    const { code, output } = await check((f) => Deno.writeTextFile(`${f}/etc/role/private.key`, "other\n"));
    assert(code !== 0, output);
    failedAt(output, "Check private files", null);
    assert(!output.includes(await sha256("other\n")) && !output.includes(await sha256("secret\n")), output);
});

/** The CI plan and a CI host that matches it. */
const ciPlans = {
    ci: {
        pool: "orbit-ci", sourcePool: "orbit-instances", poolOwner: "user.orbit.ci-runner",
        project: "orbit-ci", acl: "orbit-ci-egress", network: "orbit-ci-br",
        projectConfig: { "features.profiles": "true", "features.networks": "false" },
        networkConfig: { "ipv4.nat": "true" },
        profile: { config: { "limits.cpu": "2" }, devices: {}, description: "Orbit CI runner" },
        egress: [{ action: "allow", destination: "10.0.0.0/8", state: "enabled" }],
    },
};
const ciHealthy = (): Record<string, unknown> => ({
    "/1.0/storage-pools": ["/1.0/storage-pools/orbit-ci"], "/1.0/instances?project=orbit-ci": [],
    "/1.0/storage-pools/orbit-ci": { driver: "zfs", config: { "user.orbit.ci-runner": "true" } }, "/1.0/projects": ["/1.0/projects/default", "/1.0/projects/orbit-ci"],
    "/1.0/network-acls": ["/1.0/network-acls/orbit-ci-egress"], "/1.0/networks": ["/1.0/networks/orbit-ci-br"],
    "/1.0/profiles/default?project=orbit-ci": ciPlans.ci.profile,
    "/1.0/network-acls/orbit-ci-egress": {
        description: "Orbit CI runner boundary", config: {}, ingress: [],
        egress: [{ action: "allow", destination: "10.0.0.0/8", state: "enabled", description: "" }],
    },
    "project orbit-ci features.profiles": "true", "project orbit-ci features.networks": "false", "network orbit-ci-br ipv4.nat": "true",
});
/** A CI host without the CI project, network and ACL, as before its first converge. */
const ciEmpty = (): Record<string, unknown> => ({
    "/1.0/storage-pools": ["/1.0/storage-pools/orbit-ci"], "/1.0/instances?project=orbit-ci": [],
    "/1.0/storage-pools/orbit-ci": { driver: "zfs", config: { "user.orbit.ci-runner": "true" } },
    "/1.0/projects": ["/1.0/projects/default"], "/1.0/network-acls": [], "/1.0/networks": [],
});

/**
 * Run the real CI tasks against a stand-in `incus` that answers from `state` and applies the creates, sets and edits to it,
 * as Incus does: a new project has the default profile and unset settings read as empty. `checkOnly` runs the check;
 * otherwise the apply runs without its `Start Incus` step.
 */
async function ciCheck(change: (state: Record<string, unknown>) => void = () => {}, checkOnly = true, start = ciHealthy) {
    const folder = await Deno.makeTempDir();
    try {
        const state = start();
        change(state);
        await Deno.writeTextFile(`${folder}/state.json`, JSON.stringify(state));
        await Deno.mkdir(`${folder}/bin`);
        await Deno.writeTextFile(`${folder}/bin/incus`, `#!/usr/bin/env python3
import json, sys
path = ${JSON.stringify(`${folder}/state.json`)}
state = json.load(open(path))
args = sys.argv[1:]
project = 'default'
if args[0] == '--project':
    project, args = args[1], args[2:]
def save(code=0):
    json.dump(state, open(path, 'w'))
    sys.exit(code)
def add(collection, name, value=None):
    state['/1.0/' + collection].append('/1.0/' + collection + '/' + name)
    if value is not None:
        state['/1.0/' + collection + '/' + name] = value
if args[0] == 'query':
    if args[1] not in state:
        sys.exit('Error: Not Found')
    value = state[args[1]]
    print(value if isinstance(value, str) else json.dumps(value))
elif args[:2] == ['project', 'create']:
    add('projects', args[2])
    state['/1.0/profiles/default?project=' + args[2]] = {'config': {}, 'devices': {}, 'description': 'Default Incus profile'}
    save()
elif args[:3] == ['network', 'acl', 'create']:
    add('network-acls', args[3], {'description': '', 'config': {}, 'ingress': [], 'egress': []})
    save()
elif args[:3] == ['network', 'acl', 'edit']:
    state['/1.0/network-acls/' + args[3]] = json.load(sys.stdin)
    save()
elif args[:2] == ['network', 'create']:
    add('networks', args[2])
    save()
elif args[:2] == ['profile', 'edit']:
    state['/1.0/profiles/' + args[2] + '?project=' + project] = json.load(sys.stdin)
    save()
elif args[1] == 'get':
    collection = {'project': 'projects', 'network': 'networks'}[args[0]]
    if '/1.0/' + collection + '/' + args[2] not in state['/1.0/' + collection]:
        sys.exit('Error: ' + args[0] + ' not found')
    print(state.get(' '.join([args[0]] + args[2:]), ''))
elif args[1] == 'set':
    state[' '.join([args[0]] + args[2:4])] = args[4]
    save()
else:
    sys.exit('unexpected incus ' + ' '.join(args))
`);
        await Deno.chmod(`${folder}/bin/incus`, 0o755);
        // The CI pool step runs files/ci-host-pool.py, which Ansible finds beside the playbook.
        await Deno.symlink(fileURLToPath(new URL("../ansible/files", import.meta.url)), `${folder}/files`);
        const ci = (parse(await Deno.readTextFile(`${tasks}ci.yml`)) as Array<Record<string, unknown>>)
            .filter((task) => task.name !== "Start Incus")
            .map((task) => task["ansible.builtin.import_tasks"] ? { ...task, "ansible.builtin.import_tasks": tasks + task["ansible.builtin.import_tasks"] } : task);
        await Deno.writeTextFile(`${folder}/ci.json`, JSON.stringify(ci));
        await Deno.writeTextFile(`${folder}/play.json`, JSON.stringify([{
            hosts: "localhost", connection: "local", gather_facts: false, vars: { plans: ciPlans },
            tasks: [{ name: "CI", "ansible.builtin.import_tasks": `${folder}/ci.json`, vars: { ci_check_only: checkOnly } }],
        }]));
        const result = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", `${folder}/play.json`], stdout: "piped", stderr: "piped",
            env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default", PATH: `${folder}/bin:${Deno.env.get("PATH")}` },
        }).output();
        return {
            code: result.code, output: new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr),
            state: JSON.parse(await Deno.readTextFile(`${folder}/state.json`)) as Record<string, unknown>,
        };
    } finally { await Deno.remove(folder, { recursive: true }); }
}

Deno.test("an apply on an empty CI host creates the project, network and ACL, then sets and checks them", async () => {
    const { code, output, state } = await ciCheck(() => {}, false, ciEmpty);
    assertEquals(code, 0, output);
    // Settings are read after the creates, and the profile and ACL after the settings, as one call per item did:
    // read before them, the new project's settings could not be read.
    assertEquals(reads(output), 3, output);
    const healthy = ciHealthy();
    for (const key of ["/1.0/projects", "/1.0/networks", "/1.0/network-acls", "/1.0/profiles/default?project=orbit-ci",
        "project orbit-ci features.profiles", "project orbit-ci features.networks", "network orbit-ci-br ipv4.nat"]) {
        assertEquals(state[key], healthy[key], key);
    }
    assertEquals((state["/1.0/network-acls/orbit-ci-egress"] as Record<string, unknown>).egress, ciPlans.ci.egress);
    const again = await ciCheck((s) => Object.assign(s, state));
    assertEquals(again.code, 0, again.output);
});

Deno.test("an apply on a healthy CI host changes nothing", async () => {
    const { code, output } = await ciCheck(() => {}, false);
    assertEquals(code, 0, output);
    assertMatch(output, /changed=0\s/);
});

Deno.test("healthy CI state passes the CI check with one read", async () => {
    const { code, output } = await ciCheck();
    assertEquals(code, 0, output);
    assertEquals(reads(output), 1, output);
    assertMatch(output, /changed=0\s/);
});

const ciDrifts: Array<[string, (state: Record<string, unknown>) => void, string, RegExp]> = [
    ["a missing CI project", (s) => {
        s["/1.0/projects"] = ["/1.0/projects/default"];
        delete s["/1.0/profiles/default?project=orbit-ci"];
    }, "Require every CI read", /\(item=project orbit-ci features\.profiles\)[\s\S]*could not be read \(exit 1\): Error: project not found/],
    ["a missing CI network", (s) => { s["/1.0/networks"] = []; }, "Require every CI read",
        /\(item=network orbit-ci-br ipv4\.nat\)[\s\S]*could not be read \(exit 1\): Error: network not found/],
    ["a missing CI ACL", (s) => { s["/1.0/network-acls"] = []; delete s["/1.0/network-acls/orbit-ci-egress"]; }, "Require every CI read",
        /\(item=acl\)[\s\S]*acl could not be read \(exit 1\): Error: Not Found/],
    ["a changed CI setting", (s) => { s["project orbit-ci features.networks"] = "true"; }, "Check CI settings", /'key': 'features\.networks'/],
    ["a changed CI profile", (s) => { s["/1.0/profiles/default?project=orbit-ci"] = { config: {}, devices: {}, description: "Orbit CI runner" }; },
        "Check the CI profile", /Assertion failed/],
    ["changed CI egress", (s) => { s["/1.0/network-acls/orbit-ci-egress"] = { description: "Orbit CI runner boundary", config: {}, ingress: [], egress: [] }; },
        "Check CI egress", /Assertion failed/],
    ["a CI profile that cannot be read", (s) => { delete s["/1.0/profiles/default?project=orbit-ci"]; }, "Require every CI read",
        /\(item=profile\)[\s\S]*profile could not be read \(exit 1\): Error: Not Found/],
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
                    check_read_commands: {
                        echo: ["echo", "one"], missing: [`${folder}/missing`], failing: ["false"], unrunnable: [`${folder}/target`],
                    },
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
        assertEquals(read.commands.echo, { rc: 0, stdout: "one\n", stderr: "" });
        assertEquals(read.commands.failing, { rc: 1, stdout: "", stderr: "" });
        assertEquals([read.commands.missing.rc, read.commands.missing.stdout], [127, ""]);
        // A program that cannot start, such as a launcher without its execute bit, is that command's result, not the read's.
        assertEquals([read.commands.unrunnable.rc, read.commands.unrunnable.stdout], [126, ""]);
        assertMatch(read.commands.unrunnable.stderr, /Permission denied/);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

/** The software check's tasks named `names`, run with `vars`; `dpkg` is the dpkg database the real `dpkg-query` reads. */
async function softwareCheck(names: string[], vars: Record<string, unknown>, dpkg = "") {
    const folder = await Deno.makeTempDir();
    try {
        const all = parse(await Deno.readTextFile(`${tasks}node-converge-software-check.yml`)) as Array<Record<string, unknown>>;
        const picked = names.map((name) => all.find((task) => task.name === name)!)
            .map((task) => task["ansible.builtin.import_tasks"] ? { ...task, "ansible.builtin.import_tasks": tasks + task["ansible.builtin.import_tasks"] } : task);
        await Deno.mkdir(`${folder}/bin`);
        await Deno.mkdir(`${folder}/dpkg/updates`, { recursive: true });
        await Deno.mkdir(`${folder}/dpkg/info`);
        await Deno.writeTextFile(`${folder}/dpkg/status`, dpkg);
        await Deno.writeTextFile(`${folder}/bin/dpkg-query`, `#!/bin/sh\nexec /usr/bin/dpkg-query --admindir=${folder}/dpkg "$@"\n`);
        await Deno.writeTextFile(`${folder}/bin/dpkg`, `#!/bin/sh\necho amd64\n`);
        await Deno.chmod(`${folder}/bin/dpkg-query`, 0o755);
        await Deno.chmod(`${folder}/bin/dpkg`, 0o755);
        await Deno.writeTextFile(`${folder}/software.json`, JSON.stringify(picked));
        await Deno.writeTextFile(`${folder}/play.json`, JSON.stringify([{
            hosts: "localhost", connection: "local", gather_facts: false, vars,
            tasks: [{ name: "Software", "ansible.builtin.import_tasks": `${folder}/software.json` }],
        }]));
        const result = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", `${folder}/play.json`], stdout: "piped", stderr: "piped",
            env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default", PATH: `${folder}/bin:${Deno.env.get("PATH")}` },
        }).output();
        return { code: result.code, output: new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr) };
    } finally { await Deno.remove(folder, { recursive: true }); }
}

const packageRead = ["Read the selected package state and tool versions after all mutations", "Require the package read",
    "Keep the installed package versions", "Require every selected package pin"];
const dpkgEntry = (name: string, arch: string, status: string, version = "2.39-0ubuntu8") =>
    `Package: ${name}\nStatus: install ok ${status}\nPriority: optional\nSection: libdevel\nMaintainer: Ubuntu\nArchitecture: ${arch}\n` +
    (arch === "all" ? "" : "Multi-Arch: same\n") + `Version: ${version}\n` +
    // dpkg keeps the trigger that a package in a triggers state waits for.
    (status === "triggers-pending" ? "Triggers-Pending: ldconfig\n" : status === "triggers-awaited" ? "Triggers-Awaited: libc-bin\n" : "") +
    "Description: fixture\n\n";
/** Check a `libc6-dev` pin of 2.39-0ubuntu8 against a dpkg database, as package_facts named apt's packages. */
const pinCheck = (dpkg: string) =>
    softwareCheck(packageRead, { tool_by_name: {}, ubuntu_tools: [{ name: "libc6-dev", package: "libc6-dev", version: "2.39-0ubuntu8" }], deb_tools: [] }, dpkg);

for (const status of ["installed", "unpacked", "half-installed", "half-configured", "triggers-awaited", "triggers-pending"]) {
    Deno.test(`a native package that is ${status} meets its pin, as apt's current version did`, async () => {
        const { code, output } = await pinCheck(dpkgEntry("libc6-dev", "amd64", status));
        assertEquals(code, 0, output);
    });
}

const pinMisses: Array<[string, string]> = [
    ["only a foreign architecture", dpkgEntry("libc6-dev", "i386", "installed")],
    ["only its configuration files", dpkgEntry("libc6-dev", "amd64", "config-files")],
    ["no package", dpkgEntry("libc6-dev", "amd64", "not-installed").replace("install ok", "purge ok")],
    ["another version", dpkgEntry("libc6-dev", "amd64", "installed", "2.39-0ubuntu7")],
];
for (const [name, dpkg] of pinMisses) {
    Deno.test(`a package with ${name} misses its pin`, async () => {
        const { code, output } = await pinCheck(dpkg);
        assert(code !== 0, output);
        failedAt(output, "Require every selected package pin", /\(item=libc6-dev\)/);
    });
}

Deno.test("an Architecture: all package and a foreign pin both meet their pins", async () => {
    const { code, output } = await softwareCheck(packageRead, {
        tool_by_name: {}, deb_tools: [],
        ubuntu_tools: [{ name: "tzdata", package: "tzdata", version: "2025b" }, { name: "libc6-dev:i386", package: "libc6-dev:i386", version: "2.39-0ubuntu8" }],
    }, dpkgEntry("tzdata", "all", "installed", "2025b") + dpkgEntry("libc6-dev", "i386", "installed"));
    assertEquals(code, 0, output);
});

const versionReads: Array<[string, string, string, Record<string, unknown>]> = [
    ["browser", "chrome-headless-shell", "Google Chrome for Testing 140.0.1", { "chrome-headless-shell": { version: "140.0.1" } }],
    ["Deno", "deno", "deno 2.5.0 (stable)", { deno: { version: "2.5.0" } }],
];
for (const [name, tool, stdout, recipeTools] of versionReads) {
    const key = tool === "deno" ? "deno" : "browser";
    const run = (rc: number) =>
        softwareCheck([`Require the selected ${name} version read`, `Require the selected ${name} pin`], {
            tool_by_name: { [tool]: {} }, recipe: { tools: recipeTools }, check_read: { commands: { [key]: { rc, stdout, stderr: "crashed" } } },
        });
    Deno.test(`a ${name} that prints its pin and exits 0 meets it`, async () => {
        const { code, output } = await run(0);
        assertEquals(code, 0, output);
    });
    Deno.test(`a ${name} that prints its pin but exits 17 fails its read`, async () => {
        const { code, output } = await run(17);
        assert(code !== 0, output);
        failedAt(output, `Require the selected ${name} version read`, /exited 17: crashed/);
    });
}
