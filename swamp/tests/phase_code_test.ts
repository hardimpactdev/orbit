import { assertEquals } from "jsr:@std/assert@1.0.19";
import { fileURLToPath } from "node:url";
import { phaseCodeDigests } from "../extensions/behaviors/operations/ansible-input/converge_input.ts";
import { named, phases, resolvePhases, shared, unresolved } from "../extensions/behaviors/operations/ansible-input/phase_code.ts";
import { codeTree } from "./phase_code_fixture.ts";

const shipped = fileURLToPath(new URL("../ansible", import.meta.url));

// docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change
Deno.test("each phase of the shipped bundle resolves to its own bounded files, and shared files are in every phase", async () => {
    const resolved = await resolvePhases(shipped);
    for (const phase of phases) {
        const resolution = resolved[phase];
        assertEquals(resolution.whole, false, resolution.whole ? resolution.reason : phase);
        for (const file of shared) assertEquals(resolution.files!.includes(file), true, `${phase} ${file}`);
    }
    const has = (phase: typeof phases[number], file: string) => resolved[phase].files!.includes(file);
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
        const set = new Set(resolved[phase].files!);
        for (const file of set) {
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
        assertEquals(before.network.files, ["ansible.cfg", "build_vars.py", "operation-node-converge-local-dns.yml", "operation-node-converge.yml", "tasks/network.yml", "tasks/shared.yml"]);
        assertEquals(before.software.files!.includes("library/orbit_artifact.py"), true);
        assertEquals(before.roles.files!.includes("helper.py"), true);
        // A file no phase names, and the check phase's file, are in no digest: nothing a skippable phase runs loads them.
        for (const phase of phases) assertEquals([before[phase].files!.includes("tasks/unused.yml"), before[phase].files!.includes("tasks/check.yml")], [false, false]);
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
        const first = await phaseCodeDigests(code);
        const moved = async () => {
            const now = await phaseCodeDigests(code);
            return phases.filter((phase) => now[phase] !== first[phase]);
        };
        await Deno.writeTextFile(`${ansible}/tasks/network.yml`, "- ansible.builtin.include_tasks: shared.yml\n- ansible.builtin.debug: { msg: probe }\n");
        assertEquals(await moved(), ["network"]);
        await Deno.writeTextFile(`${ansible}/tasks/unused.yml`, "# probe\n");
        assertEquals(await moved(), ["network"]);
        await Deno.writeTextFile(`${tools}/native.ts`, "two");
        assertEquals(await moved(), ["roles", "tools", "network"]);
        await Deno.writeTextFile(`${ansible}/tasks/shared.yml`, "- ansible.builtin.debug: { msg: probe }\n");
        assertEquals(await moved(), ["agent", "software", "roles", "tools", "network"]);
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
        assertEquals(resolved.network.files!.includes("tasks/agent.yml") && resolved.network.files!.includes("tasks/check.yml"), true);
        for (const phase of phases) assertEquals(resolved[phase].files!.includes("tasks/unused.yml"), true, phase);
    } finally { await Deno.remove(tree, { recursive: true }); }
});
