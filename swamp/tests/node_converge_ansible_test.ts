import { assertEquals, assertMatch } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";

// Exercise real Ansible tasks locally, without an installer or a Node connection.
// Use the shape-gate Ansible environment to run this suite.
const taskPath = (name: string) => fileURLToPath(new URL(`../ansible/tasks/${name}.yml`, import.meta.url));
const recipe = JSON.parse(await Deno.readTextFile(new URL("../../apps/gateway/resources/machine-software/recipe.json", import.meta.url)));

Deno.test("generated converge vars preserve shell arguments, scalar strings and literal Jinja through Ansible", async () => {
    const { convergeInput } = await import("../extensions/behaviors/operations/ansible-input/converge_input.ts");
    const folder = await Deno.makeTempDir();
    try {
        const fixture = {
            // The real app-dev intent includes find's negation argument. Tagging it !unsafe breaks Ansible's YAML loader.
            argv: ["find", "/etc/php", "-name", "*.conf", "!", "-name", "orbit.conf"],
            values: ["on", "off", "yes", "no", "true", "false", "null", "1", "01", "", "[]", "{}"],
            templates: ["{{ lookup('pipe', 'false') }}", "{% if false %}literal{% endif %}", "{# literal comment #}"],
            enabled: true, empty: null, count: 7,
        };
        const ssh = { identity_file: "/unused", target: { host: "localhost", port: 22, user: "unused", host_keys: ["ssh-ed25519 fixture"] } };
        await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ node_id: 7, ssh, roles: [], fixture }));
        await Deno.writeTextFile(`${folder}/plans.json`, "{}");
        await convergeInput(folder, "/unused", "/unused");
        await Deno.writeTextFile(`${folder}/roundtrip.json`, JSON.stringify([{
            hosts: "localhost", connection: "local", gather_facts: false,
            tasks: [{ name: "Read generated literal values", "ansible.builtin.copy": {
                dest: `${folder}/result.json`, content: "{{ converge_intent.fixture | to_json }}", mode: "0600",
            } }],
        }]));
        const result = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", "-e", `@${folder}/vars.yml`, `${folder}/roundtrip.json`],
            env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default" }, stdout: "piped", stderr: "piped",
        }).output();
        assertEquals(result.code, 0, new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr));
        assertEquals(JSON.parse(await Deno.readTextFile(`${folder}/result.json`)), fixture);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

async function localPlay(vars: Record<string, unknown>, tasks: unknown[], unchanged = true) {
    const folder = await Deno.makeTempDir();
    try {
        const path = `${folder}/play.json`;
        await Deno.writeTextFile(path, JSON.stringify([{
            hosts: "localhost", connection: "local", gather_facts: false, vars, tasks,
        }]));
        const result = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", path], stdout: "piped", stderr: "piped",
            env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default" },
        }).output();
        const output = new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr);
        assertEquals(result.code, 0, output);
        if (unchanged) assertMatch(output, /changed=0\s/);
    } finally { await Deno.remove(folder, { recursive: true }); }
}

Deno.test("peer DNS routing domains stay literal and a second application changes nothing", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const state = `${folder}/domains`;
        const resolvectl = `${folder}/resolvectl`;
        await Deno.writeTextFile(state, "Link 2 (orbit): ~stale\n");
        // ~root is a valid routing domain and also an existing account on Linux and macOS.
        // The real command module must not turn it into that account's home directory.
        await Deno.writeTextFile(resolvectl, `#!/bin/sh\nset -eu\nif [ "$#" -eq 2 ]; then\n  cat '${state}'\nelse\n  shift 2\n  printf 'Link 2 (orbit): %s\\n' "$*" > '${state}'\nfi\n`);
        await Deno.chmod(resolvectl, 0o755);
        const tasks = parse(await Deno.readTextFile(taskPath("node-converge-network"))) as Array<Record<string, unknown>>;
        const peer = tasks.find((task) => task.name === "Reconcile the peer after its hub has completed")!;
        const dns = structuredClone((peer.block as Array<Record<string, unknown>>).filter((task) =>
            ["Read live split DNS domains", "Select the recorded DNS domains"].includes(task.name as string)
        ));
        for (const task of dns) {
            const command = task["ansible.builtin.command"] as { argv: string | string[] };
            if (Array.isArray(command.argv)) command.argv[0] = resolvectl;
            else command.argv = command.argv.replace("'resolvectl'", `'${resolvectl}'`);
        }
        const vars = { intent: { network: { apply: { peer: { domains: ["root"] } } } } };
        await localPlay(vars, dns, false);
        assertEquals(await Deno.readTextFile(state), "Link 2 (orbit): ~root\n");
        await localPlay(vars, dns);
        assertEquals(await Deno.readTextFile(state), "Link 2 (orbit): ~root\n");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

for (const state of ["matching", "unavailable", "plan error", "invalid digest"]) {
    Deno.test(`input ${state === "matching" ? "accepts the matching" : "rejects the " + state} base CLI without writes`, async () => {
        const plays = parse(await Deno.readTextFile(new URL("../ansible/operation-node-converge.yml", import.meta.url))) as Array<{ tasks: Array<Record<string, unknown>> }>;
        const input = plays[1].tasks.find((task) => task.name === "Require the pinned Linux platform")!;
        await localPlay({
            recipe, ansible_facts: { distribution: "Ubuntu", distribution_version: recipe.ubuntu.release, architecture: "x86_64" },
            secrets: { agent_verifier: "b".repeat(64) },
            intent: {
                agent: { url: "https://fixtures.invalid/agent", sha256: "a".repeat(64) },
                cli: {
                    skip_reason: state === "unavailable" ? "release_unavailable" : null,
                    plan_error: state === "plan error" ? "fleet.cli_plan_failed" : null,
                    release: state === "unavailable" ? null : { url: "https://fixtures.invalid/cli", sha256: state === "invalid digest" ? "invalid" : "c".repeat(64) },
                },
            },
        }, [
            { name: "Record no input rejection yet", "ansible.builtin.set_fact": { rejected: false } },
            { name: "Validate actual base input", block: [input], rescue: [{ name: "Record the input rejection", "ansible.builtin.set_fact": { rejected: true } }] },
            { name: "Require the correct input outcome", "ansible.builtin.assert": { that: `rejected == ${state === "matching" ? "false" : "true"}`, quiet: true } },
        ]);
    });
}
const cases = [
    { name: "router does not select PHP or unrelated JavaScript runtimes", roles: ["router"], tools: [], checks: ["php_versions == []", "'php-common' not in tool_by_name", "'vp' not in tool_by_name", "'deno' not in tool_by_name", "'rustc' not in tool_by_name", "'caddy' in tool_by_name"] },
    { name: "app-dev selects its two PHP versions and JavaScript pins", roles: ["app-dev"], tools: [], checks: ["php_versions | sort == ['8.4', '8.5']", "'php-common' in tool_by_name", "'php8.4-fpm' in tool_by_name", "'php8.5-fpm' in tool_by_name", "'vp' in tool_by_name", "'chrome-headless-shell' not in tool_by_name"] },
    { name: "app-prod selects the recorded browser artifact", roles: ["app-prod"], tools: [], checks: ["php_versions == ['8.5']", "'php8.4-fpm' not in tool_by_name", "tool_by_name['chrome-headless-shell'].sha256 == recipe.tools['chrome-headless-shell'].artifacts.amd64.sha256", "'chrome-headless-shell' not in (deb_tools | map(attribute='name') | list)"] },
    { name: "a roleless recorded Composer Tool selects its manager and Git", roles: [], tools: [{ manager: "composer" }], checks: ["php_versions == []", "'composer' in tool_by_name", "'git' in tool_by_name", "'unzip' in tool_by_name", "'vp' not in tool_by_name", "'composer' in (ubuntu_tools | map(attribute='name') | list)"] },
    { name: "the Swamp Node selects the pinned Deno of the Tool helper and unzip for its archive", roles: ["swamp"], tools: [], checks: ["tool_by_name['deno'].sha256 == recipe.tools.deno.artifacts.amd64.sha256", "'unzip' in (ubuntu_tools | map(attribute='name') | list)", "'vp' not in tool_by_name"] },
    { name: "a roleless recorded VP Tool selects the locked JavaScript manager", roles: [], tools: [{ manager: "vp" }], checks: ["php_versions == []", "'vp' in tool_by_name", "'node' in tool_by_name", "'pnpm' in tool_by_name", "'bun' in tool_by_name", "'vp-cli' in tool_by_name", "'composer' not in tool_by_name"] },
];
for (const test of cases) {
    Deno.test(test.name, () => localPlay({ recipe, intent: { selected_roles: test.roles, tools: test.tools } }, [
        { name: "Select admitted software", "ansible.builtin.import_tasks": taskPath("node-converge-software-plan") },
        { name: "Require the scope contract", "ansible.builtin.assert": { that: test.checks, quiet: true } },
    ]));
}
for (const matches of [true, false]) {
    Deno.test(`the final software check ${matches ? "accepts a matching" : "rejects a drifted"} installed package without writes`, () => localPlay({
        intent: { selected_roles: [], tools: [{ manager: "apt" }] },
        recipe: {
            common: ["base-files"], roles: {}, php: { versions: {}, shared: [] },
            tools: { "base-files": { source: "ubuntu", package: "base-files", version: matches ? "{{ ansible_facts.packages['base-files'][0].version }}" : "not-the-admitted-pin" } },
        },
    }, [
        { name: "Read local fixture package metadata", "ansible.builtin.package_facts": { manager: "apt" } },
        { name: "Record no rejection yet", "ansible.builtin.set_fact": { rejected: false } },
        {
            name: "Run the real end check",
            block: [{ name: "Check admitted software", "ansible.builtin.import_tasks": taskPath("node-converge-software-check") }],
            rescue: [{ name: "Recognize only the expected pin refusal", "ansible.builtin.set_fact": { rejected: "{{ ansible_failed_task.name == 'Require every selected package pin' }}" } }],
        },
        { name: "Require the correct check outcome", "ansible.builtin.assert": { that: `rejected == ${matches ? "false" : "true"}`, quiet: true } },
    ]));
}

Deno.test("repository trust requires exactly one primary fingerprint, not a matching subkey or one of several keys", async () => {
    const tasks = parse(await Deno.readTextFile(taskPath("node-converge-tool-repository"))) as Array<Record<string, unknown>>;
    const repair = tasks.find((task) => task.name === "Repair a missing or different repository signing key")!;
    const block = repair.block as Array<Record<string, unknown>>;
    const trust = block.find((task) => task.name === "Require exactly the recorded primary key")!;
    const condition = (trust["ansible.builtin.assert"] as { that: string }).that;
    const fingerprint = "A".repeat(40);
    const other = "B".repeat(40);
    const key = (kind: string, fpr: string) => `${kind}:-:2048:1:1234:1234::::::scESC:\nfpr:::::::::${fpr}:\nuid:-::::1234::PUBLIC::Fixture public key::::::::::0:\n`;
    await localPlay({ ...(repair.vars as object), tool: { repository: { signing_key: { fingerprint } } } }, [
        { name: "Accept the sole admitted primary with unrelated subkey", "ansible.builtin.assert": { that: condition, quiet: true }, vars: { repository_candidate: { stdout: key("pub", fingerprint) + key("sub", other) } } },
        { name: "A matching trusted key needs no candidate download or publication", vars: { repository_key: { rc: 0, stdout: key("pub", fingerprint) } }, "ansible.builtin.assert": { that: `not (${repair.when})`, quiet: true } },
        ...[key("pub", other) + key("sub", fingerprint), key("pub", fingerprint) + key("pub", other), key("pub", fingerprint) + key("pub", fingerprint), ""].map((candidate) => ({
            name: "Reject a mismatched, multiple or absent primary", vars: { repository_candidate: { stdout: candidate } },
            "ansible.builtin.assert": { that: `not (${condition})`, quiet: true },
        })),
    ]);
    const trustIndex = block.indexOf(trust);
    const publishIndex = block.findIndex((task) => task.name === "Publish only the verified repository key");
    assertEquals(publishIndex > trustIndex, true);
});

Deno.test("role phases accept generated extra vars without shadowing each role's intent", async () => {
    const { convergeInput } = await import("../extensions/behaviors/operations/ansible-input/converge_input.ts");
    const folder = await Deno.makeTempDir();
    try {
        const ssh = { identity_file: "/unused", target: { host: "localhost", port: 22, user: "unused", host_keys: ["ssh-ed25519 fixture"] } };
        const runtime = { analytics: null, websocket: null, gateway: null, gateway_dns: null, metrics: null, swamp: null, ci_host: false,
            groups: [], users: [], directories: [], files: [], secret_files: [], units: [], probes: [] };
        const roles = [1, 2].map((id) => ({ assignment_id: id, role: `fixture-${id}`, node_id: 7, ssh,
            publications: [], exporters: [], prometheus: null, runtime, php: [], directories: [], services: [],
            files: [{ path: `${folder}/role-${id}.txt`, content: `role ${id}\n`, owner: Deno.env.get("USER"), group: "", mode: "0600" }] }));
        const group = new TextDecoder().decode((await new Deno.Command("id", { args: ["-gn"] }).output()).stdout).trim();
        for (const role of roles) role.files[0].group = group;
        await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ node_id: 7, ssh, network: null, user: "unused", recipe: "{}", roles }));
        await Deno.writeTextFile(`${folder}/plans.json`, "{}");
        await convergeInput(folder, fileURLToPath(new URL("../ansible/build_vars.py", import.meta.url)), "/unused");
        const plays = parse(await Deno.readTextFile(new URL("../ansible/operation-node-converge.yml", import.meta.url))) as Array<Record<string, unknown>>;
        const production = plays[1];
        const tasks = (production.tasks as Array<Record<string, unknown>>).filter((task) => ["Reconcile selected recorded roles", "Check the selected role runtime"].includes(task.name as string));
        // Run the production includes and play vars through the same -e @vars.yml precedence as Swamp.
        await Deno.writeTextFile(`${folder}/phase.json`, JSON.stringify([{
            ...production, hosts: "localhost", connection: "local", become: false, tasks,
            handlers: [{ name: "Reload units", "ansible.builtin.debug": { msg: "Fixture has no system services" } }],
        }]));
        // Relative includes must resolve beside the production playbook.
        await Deno.symlink(fileURLToPath(new URL("../ansible/tasks", import.meta.url)), `${folder}/tasks`);
        // Production also has a playbook with the same basename as the runtime's renewal tasks.
        await Deno.symlink(fileURLToPath(new URL("../ansible/swamp-worker-renewal.yml", import.meta.url)), `${folder}/swamp-worker-renewal.yml`);
        for (const pass of [1, 2]) {
            const result = await new Deno.Command("ansible-playbook", {
                args: ["-i", "localhost,", "-e", `@${folder}/vars.yml`, "-e", `orbit_run=${folder}`, "--tags", "roles,check", `${folder}/phase.json`],
                env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default" }, stdout: "piped", stderr: "piped",
            }).output();
            const output = new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr);
            assertEquals(result.code, 0, output);
            if (pass === 2) assertMatch(output, /changed=0\s/);
            for (const id of [1, 2]) assertEquals(await Deno.readTextFile(`${folder}/role-${id}.txt`), `role ${id}\n`);
        }
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("JavaScript repair replaces stale extracted runtimes and then skips matching state", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const allTasks = parse(await Deno.readTextFile(taskPath("task-vm-javascript"))) as Array<Record<string, unknown>>;
        const install = allTasks.find((task) => task.name === "Install the JavaScript tools")!;
        const block = install.block as Array<Record<string, unknown>>;
        const unpack = JSON.parse(JSON.stringify(block.find((task) => task.name === "Unpack the runtimes"))
            .replaceAll("/var/cache/orbit/js", `${folder}/cache`));
        const currentUser = Deno.env.get("USER")!;
        const currentGroup = new TextDecoder().decode((await new Deno.Command("id", { args: ["-gn"] }).output()).stdout).trim();
        const fixtureRecipe = structuredClone(recipe);
        const scope = `${folder}/scope`;
        const nodeHome = `${scope}/js_runtime/node/${recipe.tools.node.version}`;
        const entries = [
            { name: "node", dest: nodeHome, member: "node-dist/bin/node", binary: "bin/node" },
            { name: "vp-cli", dest: `${scope}/recipe/vp-cli`, member: "vp", binary: "vp" },
            { name: "pnpm", dest: `${scope}/recipe/pnpm`, member: "package/pnpm", binary: "package/pnpm" },
            { name: "bun", dest: `${scope}/recipe/bun`, member: "bun-linux-x64/bun", binary: "bun-linux-x64/bun" },
        ];
        await Deno.mkdir(`${folder}/cache`);
        for (const entry of entries) {
            const source = `${folder}/source-${entry.name}`;
            const candidate = `${source}/${entry.member}`;
            const extracted = `${entry.dest}/${entry.binary}`;
            await Deno.mkdir(candidate.slice(0, candidate.lastIndexOf("/")), { recursive: true });
            await Deno.writeTextFile(candidate, "pinned runtime\n");
            await Deno.mkdir(extracted.slice(0, extracted.lastIndexOf("/")), { recursive: true });
            await Deno.writeTextFile(extracted, "stale runtime\n");
            fixtureRecipe.tools[entry.name].artifacts.amd64.url = `https://fixtures.invalid/${entry.name}.tgz`;
            const archived = await new Deno.Command("tar", { args: ["-czf", `${folder}/cache/${entry.name}.tgz`, "-C", source, entry.member.split("/")[0]] }).output();
            assertEquals(archived.code, 0);
        }
        const checks = entries.map((entry) => ({
            name: `Read repaired ${entry.name}`, "ansible.builtin.slurp": { src: `${entry.dest}/${entry.binary}` }, register: "repaired",
        })).flatMap((read) => [read, {
            name: "Require the pinned extracted content", "ansible.builtin.assert": { that: "repaired.content | b64decode | trim == 'pinned runtime'", quiet: true },
        }]);
        const vars = { recipe: fixtureRecipe, scope, node_home: nodeHome, orbit_user: currentUser };
        const fixtureUnpack = JSON.parse(JSON.stringify(unpack).replaceAll('"group":"{{ orbit_user }}"', `"group":"${currentGroup}"`));
        await localPlay(vars, [
            { name: "Repair mismatched runtimes", when: install.when, vars: { js_current: false }, block: [fixtureUnpack] },
            ...checks,
        ], false);
        await localPlay(vars, [
            { name: "Skip matching runtimes", when: install.when, vars: { js_current: true }, block: [fixtureUnpack] },
            ...checks,
        ]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("node.add and node.converge apply the same kernel policy from the intent", async () => {
    const base = parse(await Deno.readTextFile(taskPath("node-converge-base"))) as Array<Record<string, unknown>>;
    const machine = (parse(await Deno.readTextFile(new URL("../ansible/operation-node-add.yml", import.meta.url))) as Array<{ tasks: Array<Record<string, unknown>> }>)[1];
    const imports = [base.find((task) => task.name === "Reconcile the kernel policy")!, machine.tasks.find((task) => task.name === "Apply the kernel policy")!];
    assertEquals(imports.map((task) => [String(task["ansible.builtin.import_tasks"]).replace(/^tasks\//, ""), task.vars]), [
        ["node-kernel-policy.yml", { kernel_policy: "{{ intent.kernel }}" }], ["node-kernel-policy.yml", { kernel_policy: "{{ intent.kernel }}" }],
    ]);
});

Deno.test("the kernel policy keeps a matching module file, such as one written by hand, and repairs a drifted one once", async () => {
    const folder = await Deno.makeTempDir();
    try {
        // Run the real task as a non-root user: only the ownership the fixture cannot set is left out.
        const tasks = parse(await Deno.readTextFile(taskPath("node-kernel-policy"))) as Array<Record<string, unknown>>;
        const copy = structuredClone(tasks.find((task) => task.name === "Write the kernel module policy")!);
        const module = copy["ansible.builtin.copy"] as Record<string, unknown>;
        delete module.owner;
        delete module.group;
        const path = `${folder}/orbit-no-sctp.conf`;
        const vars = { kernel_policy: { files: [{ path, content: "install sctp /bin/false\n" }], sysctl: [] } };
        await Deno.writeTextFile(path, "install sctp /bin/false\n");
        await Deno.chmod(path, 0o644);
        await localPlay(vars, [copy]);
        await Deno.writeTextFile(path, "install sctp /bin/true\n");
        await localPlay(vars, [copy], false);
        assertEquals(await Deno.readTextFile(path), "install sctp /bin/false\n");
        await localPlay(vars, [copy]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the Gateway role and bootstrap create /run/orbit from the tmpfiles rule before the scheduler starts", async () => {
    const runtime = parse(await Deno.readTextFile(taskPath("gateway-runtime"))) as Array<Record<string, unknown>>;
    const create = runtime.find((task) => task.name === "Create the Gateway runtime folder")!;
    assertEquals(create["ansible.builtin.command"], { argv: ["systemd-tmpfiles", "--create", "{{ rt.gateway.tmpfiles }}"] });
    const bootstrap = (parse(await Deno.readTextFile(taskPath("gateway-swamp"))) as Array<Record<string, unknown>>).map((task) => task.name);
    const [write, apply, start] = ["Write the Gateway runtime folder rule", "Create the Gateway runtime folder", "Start the recorder"].map((name) => bootstrap.indexOf(name));
    assertEquals([write >= 0, write < apply, apply < start], [true, true, true]);
});

Deno.test("node.add and node.converge apply the same update policy from the intent", async () => {
    const base = parse(await Deno.readTextFile(taskPath("node-converge-base"))) as Array<Record<string, unknown>>;
    const machine = (parse(await Deno.readTextFile(new URL("../ansible/operation-node-add.yml", import.meta.url))) as Array<{ tasks: Array<Record<string, unknown>> }>)[1];
    const imports = [base.find((task) => task.name === "Reconcile the update policy")!, machine.tasks.find((task) => task.name === "Apply the update policy")!];
    assertEquals(imports.map((task) => [String(task["ansible.builtin.import_tasks"]).replace(/^tasks\//, ""), task.vars]), [
        ["node-update-policy.yml", { update_policy: "{{ intent.updates }}" }], ["node-update-policy.yml", { update_policy: "{{ intent.updates }}" }],
    ]);
});

Deno.test("the update policy creates the missing folders and writes each file with its mode once, and sets the daily timers from the intent", async () => {
    const folder = await Deno.makeTempDir();
    try {
        // Run the real file task as a non-root user: only the ownership the fixture cannot set is left out.
        const tasks = parse(await Deno.readTextFile(taskPath("node-update-policy"))) as Array<Record<string, unknown>>;
        const [mkdir, copy] = ["Create the update policy folders", "Write the update policy"].map((name) => structuredClone(tasks.find((task) => task.name === name)!));
        for (const module of [mkdir["ansible.builtin.file"], copy["ansible.builtin.copy"]] as Array<Record<string, unknown>>) {
            delete module.owner;
            delete module.group;
        }
        const files = [{ path: `${folder}/52orbit-unattended-upgrades`, content: "Unattended-Upgrade::Automatic-Reboot \"false\";\n", mode: "0644" },
            { path: `${folder}/lib/orbit/reboot-due`, content: "#!/bin/sh\n", mode: "0755" }];
        await localPlay({ update_policy: { files } }, [mkdir, copy], false);
        assertEquals((await Deno.stat(`${folder}/lib/orbit/reboot-due`)).mode! & 0o777, 0o755);
        await localPlay({ update_policy: { files } }, [mkdir, copy]);
        const timers = tasks.find((task) => task.name === "Set the daily update timers")!["ansible.builtin.systemd_service"] as Record<string, unknown>;
        assertEquals([timers.enabled, timers.state], ["{{ update_policy.daily }}", "{{ 'started' if update_policy.daily else 'stopped' }}"]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

// The Tool helper is TypeScript that the tools phase runs on the Swamp Node. Production prepares the input inside the compiled Swamp binary:
// there `Deno.execPath()` names Swamp, which runs no script. This stand-in refuses `run` as that binary does.
const toolHelper = fileURLToPath(new URL("../extensions/behaviors/operations/ansible-input/converge_tool.ts", import.meta.url));
async function compiledSwamp(folder: string) {
    const path = `${folder}/swamp`;
    await Deno.writeTextFile(path, "#!/bin/sh\necho \"error: unknown command '$1'\" >&2\nexit 2\n");
    await Deno.chmod(path, 0o755);
    return path;
}
async function toolsPhase(folder: string, runtime: string) {
    const plays = parse(await Deno.readTextFile(new URL("../ansible/operation-node-converge.yml", import.meta.url))) as Array<{ tasks: Array<Record<string, unknown>> }>;
    const tasks = structuredClone(plays[1].tasks.filter((task) => (task.tags as string[]).includes("tools")));
    for (const task of tasks) {
        const include = task["ansible.builtin.include_tasks"] as { file: string } | undefined;
        if (include) include.file = taskPath("node-converge-tool");
    }
    // A protected package is refused by the helper's own policy, before any machine read: one Tool failure with a working helper.
    const tool = { id: 3, manager: "apt", package: "tree", version_constraint: "^2.2", protected: ["tree"], repository: null };
    await Deno.writeTextFile(`${folder}/play.json`, JSON.stringify([{
        hosts: "localhost", connection: "local", gather_facts: false, tasks,
        vars: { intent: { node_id: 7, user: "orbit", tools: [tool] }, converge_deno: runtime, converge_tool_helper: toolHelper, orbit_run: folder },
    }]));
    const result = await new Deno.Command("ansible-playbook", {
        args: ["-i", "localhost,", `${folder}/play.json`], stdout: "piped", stderr: "piped",
        env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default" },
    }).output();
    const recorded = await Deno.readTextFile(`${folder}/tools-result.json`).then(JSON.parse, () => null);
    return { code: result.code, output: new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr), recorded };
}

Deno.test("the Tool helper runs with the pinned Deno of the Swamp Node, not with the binary that prepares the input", async () => {
    const folder = await Deno.makeTempDir();
    const execPath = Deno.execPath;
    try {
        const swamp = await compiledSwamp(folder);
        const ssh = { identity_file: "/unused", target: { host: "localhost", port: 22, user: "unused", host_keys: ["ssh-ed25519 fixture"] } };
        await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ node_id: 7, ssh, roles: [] }));
        await Deno.writeTextFile(`${folder}/plans.json`, "{}");
        Deno.execPath = () => swamp;
        const { convergeInput } = await import("../extensions/behaviors/operations/ansible-input/converge_input.ts");
        await convergeInput(folder, "/unused", toolHelper);
        const vars = parse(await Deno.readTextFile(`${folder}/vars.yml`)) as { converge_deno: string; converge_tool_helper: string };
        const install = parse(await Deno.readTextFile(taskPath("task-vm-deno"))) as Array<{ block?: Array<Record<string, unknown>> }>;
        const launcher = install.flatMap((task) => task.block ?? []).find((task) => task.name === "Publish Deno")!["ansible.builtin.file"] as { dest: string };
        assertEquals(vars.converge_deno, launcher.dest);
        assertEquals(vars.converge_tool_helper, toolHelper);
        // The software phase installs that launcher on the Swamp Node, and the end check reads it back.
        for (const name of ["node-converge-software", "node-converge-software-check"]) {
            const tasks = parse(await Deno.readTextFile(taskPath(name))) as Array<Record<string, unknown>>;
            assertEquals(tasks.filter((task) => task.when === "'deno' in tool_by_name").length, 1, name);
        }
    } finally {
        Deno.execPath = execPath;
        await Deno.remove(folder, { recursive: true });
    }
});

// The compiled Swamp binary is what main named as the runtime; a missing launcher is a Swamp Node whose software phase has not run yet.
for (const runtime of ["the compiled Swamp binary", "a missing Deno launcher"]) {
    Deno.test(`a Tool helper that cannot run with ${runtime} fails the tools phase and marks no Tool failed`, async () => {
        const folder = await Deno.makeTempDir();
        try {
            const path = runtime === "the compiled Swamp binary" ? await compiledSwamp(folder) : `${folder}/deno`;
            const phase = await toolsPhase(folder, path);
            assertEquals(phase.code, 2, phase.output);
            assertMatch(phase.output, /TASK \[Require a working Tool helper\][^]*The Tool helper does not run with \S+ on the Swamp Node, so no Tool was judged/);
            assertEquals(phase.recorded, null);
        } finally { await Deno.remove(folder, { recursive: true }); }
    });
}

Deno.test("with a working Tool helper, a refused Tool is marked failed alone and the tools phase succeeds", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const phase = await toolsPhase(folder, Deno.execPath());
        assertEquals(phase.code, 0, phase.output);
        assertEquals(phase.recorded, [{ id: 3, status: "failed", installed_version: null }]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});
