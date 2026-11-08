import { assertEquals, assertMatch } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";

// Exercise real Ansible tasks locally, without an installer or a Node connection.
// Use the shape-gate Ansible environment to run this suite.
const taskPath = (name: string) => fileURLToPath(new URL(`../ansible/tasks/${name}.yml`, import.meta.url));
const recipe = JSON.parse(await Deno.readTextFile(new URL("../../apps/gateway/resources/machine-software/recipe.json", import.meta.url)));
async function localPlay(vars: Record<string, unknown>, tasks: unknown[]) {
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
        assertMatch(output, /changed=0\s/);
    } finally { await Deno.remove(folder, { recursive: true }); }
}
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
