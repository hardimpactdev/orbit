/** A small shipped converge tree: the bundle with one task file per phase and a shared one, the Tool helper and a Tool file it imports. */
export async function codeTree(): Promise<{ tree: string; code: [string, string, string] }> {
    const tree = await Deno.makeTempDir();
    const ansible = `${tree}/ansible`;
    const operations = `${tree}/extensions/behaviors/operations`;
    for (const path of [`${ansible}/tasks`, `${ansible}/library`, `${operations}/ansible-input`, `${operations}/tools`]) await Deno.mkdir(path, { recursive: true });
    const files: Record<string, string> = {
        "ansible.cfg": "[defaults]\n",
        "build_vars.py": "import json\n",
        "operation-node-converge.yml": [
            "- hosts: target",
            "  tasks:",
            ...["agent", "software", "roles", "tools", "network"].map((phase) =>
                `    - { name: ${phase}, ansible.builtin.import_tasks: tasks/${phase}.yml, tags: [${phase}] }`),
            "    - { name: check, ansible.builtin.import_tasks: tasks/check.yml, tags: [check] }",
            "",
        ].join("\n"),
        "operation-node-converge-local-dns.yml": "- ansible.builtin.import_playbook: operation-node-converge.yml\n",
        "tasks/agent.yml": "- { name: agent, ansible.builtin.import_tasks: shared.yml }\n",
        "tasks/software.yml": "- { name: fetch, orbit_artifact: {} }\n- { name: shared, ansible.builtin.include_tasks: shared.yml }\n",
        "tasks/roles.yml": "- ansible.builtin.command: { argv: [python3, \"{{ playbook_dir }}/helper.py\"] }\n",
        "tasks/tools.yml": "- ansible.builtin.debug: { msg: tools }\n",
        "tasks/network.yml": "- ansible.builtin.include_tasks: shared.yml\n- ansible.builtin.debug: { msg: network }\n",
        "tasks/shared.yml": "- ansible.builtin.debug: { msg: shared }\n",
        "tasks/check.yml": "- ansible.builtin.debug: { msg: check }\n",
        "tasks/unused.yml": "- ansible.builtin.debug: { msg: unused }\n",
        "helper.py": "import json\n",
        "library/orbit_artifact.py": "from ansible.module_utils.basic import AnsibleModule\n",
    };
    for (const [path, text] of Object.entries(files)) await Deno.writeTextFile(`${ansible}/${path}`, text);
    await Deno.writeTextFile(`${operations}/ansible-input/converge_tool.ts`, 'import "../tools/native.ts";');
    await Deno.writeTextFile(`${operations}/tools/native.ts`, "one");
    return { tree, code: [ansible, `${operations}/ansible-input/converge_tool.ts`, `${operations}/tools`] };
}
