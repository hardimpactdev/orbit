/**
 * The seed listener of a CI Node (ansible/tasks/proof-seed-listener.yml, docs/reference/node-provisioning.md#the-seed-listener), run
 * by Ansible against a stub `incus` that keeps the listener address and the trust list in files and logs each change.
 */
import { assertEquals } from "jsr:@std/assert@1";
import { fileURLToPath } from "node:url";

const tasks = fileURLToPath(new URL("../ansible/tasks/proof-seed-listener.yml", import.meta.url));
const listen = fileURLToPath(new URL("../ansible/tasks/proof-seed-listen.yml", import.meta.url));
const hasAnsible = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const certificate = "-----BEGIN CERTIFICATE-----\nDEFAULT\n-----END CERTIFICATE-----";
const other = "-----BEGIN CERTIFICATE-----\nOTHER\n-----END CERTIFICATE-----";

type Entry = { name: string; fingerprint: string; certificate: string; restricted: boolean; projects: string[] };

async function node(listener: string, trust: Entry[]) {
    const folder = await Deno.makeTempDir();
    await Deno.mkdir(`${folder}/bin`);
    await Deno.writeTextFile(`${folder}/listener`, listener);
    await Deno.writeTextFile(`${folder}/trust.json`, JSON.stringify(trust));
    await Deno.writeTextFile(`${folder}/bin/incus`, `#!/bin/sh
case "$1 $2" in
  "config get") cat '${folder}/listener'; echo;;
  "query --") cat '${folder}/trust.json';;
  *) echo "$*" >> '${folder}/calls';;
esac
exit 0
`);
    await Deno.chmod(`${folder}/bin/incus`, 0o755);
    return folder;
}

async function run(folder: string, present: boolean) {
    const seed = { present, listen: "10.44.0.30:8444", trust: "orbit-proof-seed", project: "orbit-instances-nested" };
    await Deno.writeTextFile(`${folder}/play.yml`, JSON.stringify([{
        hosts: "localhost", connection: "local", gather_facts: false,
        vars: { seed, seed_certificate: present ? certificate : "" },
        // The node phase, then the firewall phase, which opens the listener after the rules exist.
        tasks: [{ name: "Apply the seed listener", "ansible.builtin.include_tasks": tasks },
            ...(present ? [{ name: "Open the seed listener", "ansible.builtin.include_tasks": listen }] : [])],
    }]));
    // The certificate file goes to /run as root on a Node; here it goes to the fixture folder as the test user.
    const text = Deno.readTextFileSync(tasks).replaceAll("/run/orbit-proof-seed.crt", `${folder}/orbit-proof-seed.crt`)
        .replaceAll("owner: root, group: root, ", "");
    await Deno.writeTextFile(`${folder}/tasks.yml`, text);
    const play = Deno.readTextFileSync(`${folder}/play.yml`).replace(tasks, `${folder}/tasks.yml`);
    await Deno.writeTextFile(`${folder}/play.yml`, play);
    const result = await new Deno.Command("ansible-playbook", {
        args: ["-i", "localhost,", `${folder}/play.yml`], stdout: "piped", stderr: "piped",
        env: { PATH: `${folder}/bin:${Deno.env.get("PATH")}`, ANSIBLE_LOCALHOST_WARNING: "False", ANSIBLE_PYTHON_INTERPRETER: "auto_silent" },
    }).output();
    assertEquals(result.code, 0, new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr));
    try {
        return Deno.readTextFileSync(`${folder}/calls`).trim().split("\n");
    } catch {
        return [];
    }
}

const entry = (certificateText: string, restricted = true, projects = ["orbit-instances-nested"]): Entry =>
    ({ name: "orbit-proof-seed", fingerprint: "f1", certificate: certificateText, restricted, projects });

Deno.test("a first converge listens on the WireGuard address and trusts the default's Node for one project", { ignore: !hasAnsible }, async () => {
    const folder = await node("", []);
    try {
        assertEquals(await run(folder, true), [
            `config trust add-certificate ${folder}/orbit-proof-seed.crt --name orbit-proof-seed --restricted --projects orbit-instances-nested`,
            "config set core.https_address 10.44.0.30:8444",
        ]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a converge with the listener and the matching trust changes nothing", { ignore: !hasAnsible }, async () => {
    const folder = await node("10.44.0.30:8444", [entry(certificate)]);
    try {
        assertEquals(await run(folder, true), []);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a trust entry of another certificate, unrestricted or with other projects is replaced", { ignore: !hasAnsible }, async (t) => {
    for (const [name, stale] of [["certificate", entry(other)], ["unrestricted", entry(certificate, false)],
        ["projects", entry(certificate, true, ["default"])]] as const) {
        await t.step(name, async () => {
            const folder = await node("10.44.0.30:8444", [stale]);
            try {
                assertEquals(await run(folder, true), ["config trust remove f1",
                    `config trust add-certificate ${folder}/orbit-proof-seed.crt --name orbit-proof-seed --restricted --projects orbit-instances-nested`]);
            } finally { await Deno.remove(folder, { recursive: true }); }
        });
    }
});

Deno.test("without a sender the trust and the seed listener go, and another listener address stays", { ignore: !hasAnsible }, async () => {
    const folder = await node("10.44.0.30:8444", [entry(certificate)]);
    try {
        assertEquals(await run(folder, false), ["config trust remove f1", "config unset core.https_address"]);
    } finally { await Deno.remove(folder, { recursive: true }); }
    const kept = await node("10.44.0.30:9443", []);
    try {
        assertEquals(await run(kept, false), []);
    } finally { await Deno.remove(kept, { recursive: true }); }
});

Deno.test("a converge opens the seed listener only in the firewall phase, after the rules of its port exist", async () => {
    assertEquals(Deno.readTextFileSync(tasks).includes("core.https_address, \"{{ seed.listen }}\""), false);
    // The playbook holds a tag that the YAML reader here does not parse, so the firewall play is read as text.
    const book = Deno.readTextFileSync(new URL("../ansible/operation-node-role-add.yml", import.meta.url));
    const start = book.indexOf("- name: Role firewall\n");
    const play = book.slice(start, book.indexOf("\n- name: ", start + 1));
    assertEquals(start >= 0 && play.includes("tags: [firewall]"), true);
    const converge = play.indexOf("- name: Converge the role firewall");
    assertEquals(converge >= 0 && converge < play.indexOf("- name: Open the seed listener"), true);
});

Deno.test("a ci-runner removal stops the seed listener in a phase before the firewall rules go", async () => {
    const { parse } = await import("jsr:@std/yaml@1.0.12");
    const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-node-role-remove.yaml", import.meta.url))) as
        { jobs: { name: string; steps: { name: string }[] }[] };
    const phase = (step: string) => workflow.jobs.findIndex((job) => job.steps.some((item) => item.name === step));
    assertEquals(phase("Stop the seed listener") >= 0 && phase("Stop the seed listener") < phase("Withdraw firewall rules"), true);
});
