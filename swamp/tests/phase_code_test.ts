import { assertEquals } from "jsr:@std/assert@1.0.19";
import { fileURLToPath } from "node:url";
import { phaseCodeDigests, roleSiblings } from "../extensions/behaviors/operations/ansible-input/converge_input.ts";
import { classify, named, outsideNames, phases, type Resolution, resolvePhases, shared, unresolved } from "../extensions/behaviors/operations/ansible-input/phase_code.ts";
import { codeTree } from "./phase_code_fixture.ts";

const shipped = fileURLToPath(new URL("../ansible", import.meta.url));

/** A phase's files; a phase that reads the whole bundle fails with its reason instead of a null read. */
function filesOf(resolution: Resolution, phase: string): string[] {
    if (resolution.whole) throw new Error(`${phase} reads the whole bundle: ${resolution.reason}`);
    return resolution.files;
}

// docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change
Deno.test("each phase of the shipped bundle resolves to its own bounded files, and shared files are in every phase", async () => {
    const resolved = await resolvePhases(shipped);
    for (const phase of phases) {
        const resolution = resolved[phase];
        assertEquals(resolution.whole, false, resolution.whole ? resolution.reason : phase);
        for (const file of shared) assertEquals(resolution.files!.includes(file), true, `${phase} ${file}`);
    }
    const has = (phase: typeof phases[number], file: string) => filesOf(resolved[phase], phase).includes(file);
    // The proof's probes: a network-only and a roles-only task file.
    assertEquals(phases.filter((phase) => has(phase, "tasks/node-converge-hub-prune.yml")), ["network"]);
    assertEquals(phases.filter((phase) => has(phase, "tasks/node-role-runtime.yml")), ["roles"]);
    assertEquals(phases.filter((phase) => has(phase, "tasks/node-converge-tool.yml")), ["tools"]);
    assertEquals(phases.filter((phase) => has(phase, "tasks/node-converge-base.yml")), ["agent"]);
    assertEquals(phases.filter((phase) => has(phase, "tasks/node-converge-apt.yml")), ["software"]);
});

// The guard: every include, import, lookup, local source and module a reached file names is in its phase's set. A new include that
// the resolver cannot follow fails here, or turns its phase to the whole bundle, which the test above refuses.
Deno.test("every bundle file that a reached file names is in that phase's set", async () => {
    const resolved = await resolvePhases(shipped);
    const files: string[] = [];
    for await (const entry of Deno.readDir(`${shipped}/tasks`)) files.push(`tasks/${entry.name}`);
    for (const phase of phases) {
        const set = new Set(filesOf(resolved[phase], phase));
        // A file no phase names is in every set whole; it is not loaded by a name, so nothing it includes is.
        for (const file of [...set].filter((file) => !resolved[phase].everyPhase.includes(file))) {
            if (shared.includes(file) && file.endsWith(".yml")) continue;
            const text = await Deno.readTextFile(`${shipped}/${file}`);
            assertEquals(unresolved(file, text), null);
            for (const match of text.matchAll(/(?:import_tasks|include_tasks|file):\s*["']?([A-Za-z0-9_./-]+\.ya?ml)/g)) {
                const target = files.find((candidate) => candidate === match[1] || candidate === `tasks/${match[1]}`) ?? match[1];
                if (!target.includes("{{")) assertEquals(set.has(target) || !files.includes(target), true, `${phase}: ${file} includes ${target}`);
            }
        }
    }
});

Deno.test("a reference the resolver cannot name makes only its phase read the whole bundle", async () => {
    const { tree, code } = await codeTree();
    try {
        const [ansible] = code;
        const before = await resolvePhases(ansible);
        // The files nothing reaches (the check phase's file and an unused one) and the implicit module are in every phase.
        assertEquals(before.network.files, ["ansible.cfg", "build_vars.py", "library/orbit_artifact.py", "operation-node-converge-local-dns.yml", "operation-node-converge.yml",
            "tasks/check.yml", "tasks/network.yml", "tasks/shared.yml", "tasks/unused.yml"]);
        assertEquals(filesOf(before.software, "software").includes("library/orbit_artifact.py"), true);
        assertEquals(filesOf(before.roles, "roles").includes("helper.py"), true);
        assertEquals(filesOf(before.agent, "agent").includes("tasks/network.yml"), false);
        await Deno.writeTextFile(`${ansible}/tasks/tools.yml`, '- ansible.builtin.include_tasks: "tool-{{ kind }}.yml"\n');
        const after = await resolvePhases(ansible);
        assertEquals(after.tools.whole, true);
        assertEquals(phases.filter((phase) => after[phase].whole), ["tools"]);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

Deno.test("unresolved() refuses dynamic file names and accepts literal, playbook and run-folder names", () => {
    const refused = [
        ["tasks/a.yml", "- ansible.builtin.import_tasks: \"{{ name }}.yml\"\n"],
        ["tasks/a.yml", "- ansible.builtin.template: { src: \"{{ item }}\", dest: /x }\n"],
        ["tasks/a.yml", "- ansible.builtin.debug: { msg: \"{{ lookup('ansible.builtin.file', path) }}\" }\n"],
        ["tasks/a.yml", "- ansible.builtin.debug: { msg: \"{{ role_path }}\" }\n"],
        ["tasks/a.yml", "- ansible.builtin.debug: { msg: \"{{ playbook_dir ~ '/' ~ name }}\" }\n"],
        ["a.py", "import os\nHERE = os.path.dirname(__file__)\n"],
    ];
    for (const [file, text] of refused) assertEquals(unresolved(file, text) === null, false, text);
    const accepted = [
        "- ansible.builtin.debug: { msg: \"{{ lookup('ansible.builtin.file', playbook_dir ~ '/a.json') }}\" }\n",
        "- ansible.builtin.debug: { msg: \"{{ lookup('ansible.builtin.file', orbit_run ~ '/intent.json') }}\" }\n",
        "- ansible.builtin.copy: { src: \"{{ item.src }}\", dest: /x, remote_src: true }\n",
        "- ansible.builtin.copy: { src: \"{{ playbook_dir }}/{{ item.src }}\", dest: /x }\n  loop: [{ src: a.yml }]\n",
        "- vars: { result_file: \"{{ lookup('ansible.builtin.env', 'ORBIT_RUN') ~ '/r.json' }}\" }\n  ansible.builtin.debug: { msg: \"{{ lookup('ansible.builtin.file', result_file) }}\" }\n",
    ];
    for (const text of accepted) assertEquals(unresolved("tasks/a.yml", text), null, text);
});

Deno.test("names follow paths, file names and Python module names, not full-line comments", () => {
    const byPath = new Map([["tasks/a.yml", ["tasks/a.yml"]], ["a.yml", ["tasks/a.yml"]], ["b.yml", ["tasks/b.yml"]], ["library/m.py", ["library/m.py"]], ["m.py", ["library/m.py"]]]);
    const byStem = new Map([["m", ["library/m.py"]]]);
    assertEquals(named("- import_tasks: a.yml\n- m: {}\n# b.yml\n", byPath, byStem).sort(), ["library/m.py", "tasks/a.yml"]);
    assertEquals(named("src: ../tasks/a.yml", byPath, byStem), ["tasks/a.yml"]);
    assertEquals(named("aa.yml mm", byPath, byStem), []);
});

Deno.test("a change to one phase's file moves only that phase's code digest; a shared file or the Tool helper's code moves its phases", async () => {
    const { tree, code } = await codeTree();
    try {
        const [ansible, , tools] = code;
        let last = await phaseCodeDigests(code);
        const moved = async () => {
            const now = await phaseCodeDigests(code);
            const changed = phases.filter((phase) => now[phase] !== last[phase]);
            last = now;
            return changed;
        };
        await Deno.writeTextFile(`${ansible}/tasks/network.yml`, "- ansible.builtin.include_tasks: shared.yml\n- ansible.builtin.debug: { msg: probe }\n");
        assertEquals(await moved(), ["network"]);
        await Deno.writeTextFile(`${ansible}/tasks/unused.yml`, "# probe\n");
        assertEquals(await moved(), ["agent", "software", "roles", "tools", "network"]);
        await Deno.writeTextFile(`${tools}/native.ts`, "two");
        assertEquals(await moved(), ["roles", "tools"]);
        // Only the phases that reach the shared task file.
        await Deno.writeTextFile(`${ansible}/tasks/shared.yml`, "- ansible.builtin.debug: { msg: probe }\n");
        assertEquals(await moved(), ["agent", "software", "network"]);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

Deno.test("an ansible.cfg change moves every phase", async () => {
    const { tree, code } = await codeTree();
    try {
        const first = await phaseCodeDigests(code);
        await Deno.writeTextFile(`${code[0]}/ansible.cfg`, "[defaults]\nforks = 2\n");
        const now = await phaseCodeDigests(code);
        assertEquals(phases.filter((phase) => now[phase] === first[phase]), []);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

Deno.test("a statically imported file with a task tagged in a list, and a play's handlers, reach the phase", async () => {
    const { tree, code } = await codeTree();
    try {
        const [ansible] = code;
        await Deno.writeTextFile(`${ansible}/tasks/agent.yml`, "- name: tagged\n  ansible.builtin.include_tasks: tasks/check.yml\n  tags:\n    - network\n");
        await Deno.writeTextFile(`${ansible}/operation-node-converge.yml`, (await Deno.readTextFile(`${ansible}/operation-node-converge.yml`))
            .replace("  tasks:\n", "  handlers:\n    - { name: reload, ansible.builtin.import_tasks: tasks/unused.yml }\n  tasks:\n"));
        const resolved = await resolvePhases(ansible);
        assertEquals(filesOf(resolved.network, "network").includes("tasks/agent.yml") && filesOf(resolved.network, "network").includes("tasks/check.yml"), true);
        for (const phase of phases) assertEquals(filesOf(resolved[phase], phase).includes("tasks/unused.yml"), true, phase);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

Deno.test("a caller with no shipped code gets one no-code digest per phase, and a changed input still moves every phase", async () => {
    const digests = await phaseCodeDigests([]);
    assertEquals(new Set(Object.values(digests)).size, 1);
    assertEquals(Object.keys(digests), [...phases]);
    const { tree, code } = await codeTree();
    try {
        const shippedDigests = await phaseCodeDigests(code);
        for (const phase of phases) assertEquals(shippedDigests[phase] === digests[phase], false, phase);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

// The reviewer's three reproductions at a576e851: each moved no digest of a phase whose input changed.
Deno.test("a bundle file that no phase names, such as the collection pins, moves every phase digest", async () => {
    const { tree, code } = await codeTree();
    try {
        await Deno.writeTextFile(`${code[0]}/requirements.yml`, "collections:\n  - { name: ansible.posix, version: 1.5.4 }\n");
        const first = await phaseCodeDigests(code);
        await Deno.writeTextFile(`${code[0]}/requirements.yml`, "collections:\n  - { name: ansible.posix, version: 2.0.0 }\n");
        const now = await phaseCodeDigests(code);
        assertEquals(phases.filter((phase) => now[phase] !== first[phase]), [...phases]);
        for (const phase of phases) assertEquals(filesOf((await resolvePhases(code[0]))[phase], phase).includes("requirements.yml"), true, phase);
    } finally { await Deno.remove(tree, { recursive: true }); }
    // The shipped collection pins are in every phase.
    const resolved = await resolvePhases(shipped);
    for (const phase of phases) assertEquals(filesOf(resolved[phase], phase).includes("requirements.yml"), true, phase);
});

Deno.test("a templated include in an entry playbook makes its phase read the whole bundle", async () => {
    const { tree, code } = await codeTree();
    try {
        const playbook = `${code[0]}/operation-node-converge.yml`;
        await Deno.writeTextFile(playbook, (await Deno.readTextFile(playbook)) + '    - { name: dynamic, ansible.builtin.include_tasks: "net-{{ kind }}.yml", tags: [network] }\n');
        const resolved = await resolvePhases(code[0]);
        assertEquals(phases.filter((phase) => resolved[phase].whole), ["network"]);
        const first = await phaseCodeDigests(code);
        await Deno.writeTextFile(`${code[0]}/tasks/net-new.yml`, "- ansible.builtin.debug: { msg: new }\n");
        const now = await phaseCodeDigests(code);
        assertEquals(now.network === first.network, false);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

Deno.test("a changed file inside a copied folder moves the phase that copies it", async () => {
    const { tree, code } = await codeTree();
    try {
        const [ansible] = code;
        await Deno.mkdir(`${ansible}/files/net`, { recursive: true });
        await Deno.writeTextFile(`${ansible}/files/net/a.conf`, "one\n");
        await Deno.mkdir(`${ansible}/files/glob`, { recursive: true });
        await Deno.writeTextFile(`${ansible}/files/glob/a.txt`, "one\n");
        await Deno.writeTextFile(`${ansible}/tasks/network.yml`, "- ansible.builtin.copy: { src: net/, dest: /etc/net/ }\n");
        await Deno.writeTextFile(`${ansible}/tasks/agent.yml`, "- ansible.builtin.copy: { src: \"{{ item }}\", dest: /etc/ }\n  with_fileglob: [\"glob/*.txt\"]\n");
        const resolved = await resolvePhases(ansible);
        assertEquals(phases.filter((phase) => filesOf(resolved[phase], phase).includes("files/net/a.conf")), ["network"]);
        assertEquals(filesOf(resolved.agent, "agent").includes("files/glob/a.txt"), true);
        const first = await phaseCodeDigests(code);
        await Deno.writeTextFile(`${ansible}/files/net/a.conf`, "two\n");
        const now = await phaseCodeDigests(code);
        assertEquals(phases.filter((phase) => now[phase] !== first[phase]), ["network"]);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

// docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change: the four classes of a bundle file.
Deno.test("each shipped bundle file is in exactly one class, and another Operation's playbooks are in no phase", async () => {
    const classes = await classify(shipped);
    const all = [...classes.a.keys(), ...classes.b, ...classes.c, ...classes.d];
    assertEquals(all.length, new Set(all).size);
    const resolved = await resolvePhases(shipped);
    for (const file of ["instance-container-power.yml", "instance-container.yml", "tasks/instance-container-phase.yml", "tasks/instance-container-run-phase.yml", "tasks/executor-power-relay.yml"]) {
        assertEquals(classes.c.includes(file), true, file);
        for (const phase of phases) assertEquals(filesOf(resolved[phase], phase).includes(file), false, `${phase} ${file}`);
    }
    for (const file of ["ansible.cfg", "requirements.yml", "build_vars.py", "library/orbit_artifact.py"]) assertEquals(classes.b.includes(file), true, file);
});

Deno.test("a change only in another Operation's playbook and the task file it alone includes moves no converge digest", async () => {
    const { tree, code } = await codeTree();
    try {
        const [ansible] = code;
        await Deno.writeTextFile(`${ansible}/instance-container-power.yml`, "- hosts: target\n  tasks:\n    - ansible.builtin.include_tasks: tasks/instance-power-only.yml\n");
        await Deno.writeTextFile(`${ansible}/tasks/instance-power-only.yml`, "- ansible.builtin.debug: { msg: one }\n");
        const classes = await classify(ansible);
        assertEquals(classes.c.sort(), ["instance-container-power.yml", "tasks/instance-power-only.yml"]);
        const first = await phaseCodeDigests(code);
        await Deno.writeTextFile(`${ansible}/instance-container-power.yml`, "# changed\n- hosts: target\n  tasks:\n    - ansible.builtin.include_tasks: tasks/instance-power-only.yml\n");
        await Deno.writeTextFile(`${ansible}/tasks/instance-power-only.yml`, "- ansible.builtin.debug: { msg: two }\n");
        const now = await phaseCodeDigests(code);
        assertEquals(phases.filter((phase) => now[phase] !== first[phase]), []);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

Deno.test("a task file that another Operation and a converge phase both include moves that phase", async () => {
    const { tree, code } = await codeTree();
    try {
        const [ansible] = code;
        await Deno.writeTextFile(`${ansible}/instance-container-power.yml`, "- hosts: target\n  tasks:\n    - ansible.builtin.include_tasks: tasks/power-shared.yml\n");
        await Deno.writeTextFile(`${ansible}/tasks/power-shared.yml`, "- ansible.builtin.debug: { msg: one }\n");
        await Deno.writeTextFile(`${ansible}/tasks/network.yml`, "- ansible.builtin.include_tasks: power-shared.yml\n");
        assertEquals((await classify(ansible)).a.get("tasks/power-shared.yml"), ["network"]);
        const first = await phaseCodeDigests(code);
        await Deno.writeTextFile(`${ansible}/tasks/power-shared.yml`, "- ansible.builtin.debug: { msg: two }\n");
        const now = await phaseCodeDigests(code);
        assertEquals(phases.filter((phase) => now[phase] !== first[phase]), ["network"]);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

Deno.test("a new file in filter_plugins moves every phase digest", async () => {
    const { tree, code } = await codeTree();
    try {
        const first = await phaseCodeDigests(code);
        await Deno.mkdir(`${code[0]}/filter_plugins`);
        await Deno.writeTextFile(`${code[0]}/filter_plugins/orbit.py`, "class FilterModule: pass\n");
        assertEquals((await classify(code[0])).b.includes("filter_plugins/orbit.py"), true);
        const now = await phaseCodeDigests(code);
        assertEquals(phases.filter((phase) => now[phase] === first[phase]), []);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

Deno.test("a bare fileglob counts every bundle file it can match, so a matched file moves the phase that globs it", async () => {
    const { tree, code } = await codeTree();
    try {
        const [ansible] = code;
        await Deno.mkdir(`${ansible}/files`);
        await Deno.writeTextFile(`${ansible}/files/incus-network-heal.py`, "one\n");
        // Another Operation copies the file by name, so without the glob it is in no converge digest.
        await Deno.writeTextFile(`${ansible}/instance-heal.yml`, "- hosts: target\n  tasks:\n    - ansible.builtin.copy: { src: incus-network-heal.py, dest: /x }\n");
        await Deno.writeTextFile(`${ansible}/tasks/agent.yml`, "- ansible.builtin.copy: { src: \"{{ item }}\", dest: /usr/local/lib/ }\n  with_fileglob: [\"incus-network-*.py\"]\n");
        assertEquals((await classify(ansible)).a.get("files/incus-network-heal.py"), ["agent"]);
        const first = await phaseCodeDigests(code);
        await Deno.writeTextFile(`${ansible}/files/incus-network-heal.py`, "two\n");
        const now = await phaseCodeDigests(code);
        assertEquals(phases.filter((phase) => now[phase] !== first[phase]), ["agent"]);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

Deno.test("a template's literal include joins its phase, and an include by a variable name makes the phase read the whole bundle", async () => {
    const { tree, code } = await codeTree();
    try {
        const [ansible] = code;
        await Deno.mkdir(`${ansible}/templates`);
        await Deno.writeTextFile(`${ansible}/templates/child.j2`, "one\n");
        await Deno.writeTextFile(`${ansible}/templates/parent.j2`, "{% include 'child.j2' %}\n");
        await Deno.writeTextFile(`${ansible}/instance-child.yml`, "- hosts: target\n  tasks:\n    - ansible.builtin.template: { src: child.j2, dest: /x }\n");
        await Deno.writeTextFile(`${ansible}/tasks/roles.yml`, "- ansible.builtin.template: { src: parent.j2, dest: /etc/parent }\n");
        assertEquals((await classify(ansible)).a.get("templates/child.j2"), ["roles"]);
        let last = await phaseCodeDigests(code);
        const moved = async () => {
            const now = await phaseCodeDigests(code);
            const changed = phases.filter((phase) => now[phase] !== last[phase]);
            last = now;
            return changed;
        };
        await Deno.writeTextFile(`${ansible}/templates/child.j2`, "two\n");
        assertEquals(await moved(), ["roles"]);
        await Deno.writeTextFile(`${ansible}/templates/parent.j2`, "{% include child_template %}\n");
        assertEquals((await resolvePhases(ansible)).roles.whole, true);
        assertEquals(await moved(), ["roles"]);
        await Deno.writeTextFile(`${ansible}/templates/child.j2`, "three\n");
        assertEquals(await moved(), ["roles"]);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

Deno.test("unresolved() accepts literal Jinja includes, imports and extends and refuses any other name", () => {
    for (const text of ["{% include 'a.j2' %}", "{%- include \"a.j2\" ignore missing -%}", "{% import 'm.j2' as m %}", "{% from 'm.j2' import x %}",
        "{% extends 'base.j2' %}", "{% include ['a.j2', 'b.j2'] %}"]) assertEquals(unresolved("templates/t.j2", text), null, text);
    for (const text of ["{% include name %}", "{% include 'a' ~ kind ~ '.j2' %}", "{% import macros as m %}", "{% extends parent %}"]) {
        assertEquals(unresolved("templates/t.j2", text) === null, false, text);
    }
});

Deno.test("a loop item source whose values the task lists literally joins its phase; an unbounded one makes the phase read the whole bundle", async () => {
    const { tree, code } = await codeTree();
    try {
        const [ansible] = code;
        await Deno.mkdir(`${ansible}/files`);
        await Deno.writeTextFile(`${ansible}/files/release-phase.py`, "one\n");
        await Deno.writeTextFile(`${ansible}/instance-release.yml`, "- hosts: target\n  tasks:\n    - ansible.builtin.copy: { src: files/release-phase.py, dest: /x }\n");
        await Deno.writeTextFile(`${ansible}/tasks/roles.yml`, [
            "- ansible.builtin.copy: { src: \"{{ item.src }}\", dest: \"/usr/local/libexec/{{ item.name }}\" }",
            "  loop:",
            "    - { src: files/release-phase.py, name: orbit-release-phase }",
            "    - { src: \"{{ playbook_dir }}/../scripts/outside\", name: orbit-outside }",
            "",
        ].join("\n"));
        assertEquals((await classify(ansible)).a.get("files/release-phase.py"), ["roles"]);
        const first = await phaseCodeDigests(code);
        await Deno.writeTextFile(`${ansible}/files/release-phase.py`, "two\n");
        const now = await phaseCodeDigests(code);
        assertEquals(phases.filter((phase) => now[phase] !== first[phase]), ["roles"]);
        await Deno.writeTextFile(`${ansible}/tasks/roles.yml`, "- ansible.builtin.copy: { src: \"{{ item.src }}\", dest: /x }\n  loop: \"{{ release_files }}\"\n");
        assertEquals((await resolvePhases(ansible)).roles.whole, true);
        await Deno.writeTextFile(`${ansible}/tasks/roles.yml`, "- ansible.builtin.copy: { src: \"{{ item.src }}\", dest: /x }\n  with_items: [{ src: \"{{ name }}.py\" }]\n");
        assertEquals((await resolvePhases(ansible)).roles.whole, true);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

Deno.test("a Python file may read its own source by __file__, but a path beside it makes the phase read the whole bundle", () => {
    assertEquals(unresolved("files/a.py", "source = Path(__file__).read_text()\n"), null);
    for (const text of ["HERE = Path(__file__).parent / 'b.py'\n", "b = Path(__file__) / '..' / 'b.py'\n", "b = os.path.join(os.path.dirname(__file__), 'b.py')\n",
        "b = Path(__file__).with_name('b.py')\n"]) assertEquals(unresolved("files/a.py", text) === null, false, text);
});

// docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change: files a phase names above the bundle.
Deno.test("a release script the roles phase copies from above the bundle moves the roles digest only", async () => {
    const { tree, code } = await codeTree();
    try {
        // The shipped bundle and the scripts beside it, as the extension holds them.
        const root = `${tree}/shipped`;
        await Deno.mkdir(root, { recursive: true });
        await new Deno.Command("cp", { args: ["-r", shipped, `${root}/ansible`] }).output();
        await new Deno.Command("cp", { args: ["-r", fileURLToPath(new URL("../scripts", import.meta.url)), `${root}/scripts`] }).output();
        for (const sibling of roleSiblings.filter((path) => path.startsWith("extensions/"))) {
            await Deno.mkdir(`${root}/${sibling.slice(0, sibling.lastIndexOf("/"))}`, { recursive: true });
            await Deno.copyFile(fileURLToPath(new URL(`../${sibling}`, import.meta.url)), `${root}/${sibling}`);
        }
        const shippedCode: [string, string, string] = [`${root}/ansible`, code[1], code[2]];
        assertEquals((await resolvePhases(`${root}/ansible`)).roles.outside.includes("../scripts/release-container.py"), true);
        const moving = {};
        const first = await phaseCodeDigests(shippedCode, moving);
        assertEquals(moving, {});
        await Deno.writeTextFile(`${root}/scripts/release-container.py`, "\n# changed\n", { append: true });
        const now = await phaseCodeDigests(shippedCode);
        assertEquals(phases.filter((phase) => now[phase] !== first[phase]), ["roles"]);
        // A module that a module the roles phase imports imports in turn moves it too.
        await Deno.writeTextFile(`${root}/extensions/behaviors/operations/instance-deploy/vp_requirements.ts`, "\n// changed\n", { append: true });
        const later = await phaseCodeDigests(shippedCode);
        assertEquals(phases.filter((phase) => later[phase] !== now[phase]), ["roles"]);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

// The files a converge stages beside the bundle (write.ts) are the files its phases name above it: one list serves both.
Deno.test("the files the phases name above the shipped bundle are the staged role siblings", async () => {
    const resolved = await resolvePhases(shipped);
    const outside = new Set(phases.flatMap((phase) => resolved[phase].outside));
    assertEquals([...outside].sort(), roleSiblings.map((path) => `../${path}`).sort());
});

Deno.test("a staged sibling that no phase names moves every phase digest", async () => {
    const { tree, code } = await codeTree();
    try {
        await Deno.mkdir(`${tree}/scripts`);
        await Deno.writeTextFile(`${tree}/scripts/unnamed`, "one\n");
        const staged: [string, string, string, string] = [...code, `${tree}/scripts/unnamed`];
        const first = await phaseCodeDigests(staged);
        await Deno.writeTextFile(`${tree}/scripts/unnamed`, "two\n");
        const now = await phaseCodeDigests(staged);
        assertEquals(phases.filter((phase) => now[phase] === first[phase]), []);
    } finally { await Deno.remove(tree, { recursive: true }); }
});

Deno.test("a file named above the bundle that cannot be read gives its phase a digest that always moves, and says why", async () => {
    const { tree, code } = await codeTree();
    try {
        const [ansible] = code;
        await Deno.writeTextFile(`${ansible}/tasks/network.yml`, "- ansible.builtin.copy: { src: \"{{ playbook_dir }}/../scripts/missing\", dest: /x }\n");
        assertEquals((await resolvePhases(ansible)).network.outside, ["../scripts/missing"]);
        const moving: Record<string, string> = {};
        const first = await phaseCodeDigests(code, moving);
        const second = await phaseCodeDigests(code);
        assertEquals(phases.filter((phase) => second[phase] !== first[phase]), ["network"]);
        assertEquals(Object.keys(moving), ["network"]);
        assertEquals(moving.network.startsWith("../scripts/missing cannot be read"), true);
        // A Node path such as a link target is not a controller file.
        assertEquals(outsideNames("tasks/php.yml", "- ansible.builtin.file: { src: ../../mods-available/x.ini, dest: /etc/x, state: link }\n"), []);
        assertEquals(outsideNames("tasks/a.yml", "- ansible.builtin.copy: { src: ../../scripts/x, dest: /x }\n"), ["../scripts/x"]);
        assertEquals(outsideNames("tasks/a.yml", "- ansible.builtin.copy: { src: ../files/x, dest: /x }\n"), []);
    } finally { await Deno.remove(tree, { recursive: true }); }
});
