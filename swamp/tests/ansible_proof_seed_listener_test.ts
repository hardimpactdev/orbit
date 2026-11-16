/**
 * The seed listener of a CI Node (ansible/tasks/proof-seed-listener.yml, docs/reference/node-provisioning.md#the-seed-listener), run
 * by Ansible against a stub `incus` that keeps the listener address and the trust list in files and logs each change.
 */
import { assertEquals } from "jsr:@std/assert@1";
import { fileURLToPath } from "node:url";

const tasks = fileURLToPath(new URL("../ansible/tasks/proof-seed-listener.yml", import.meta.url));
const venv = [...Deno.readDirSync("/opt/orbit-shape-gate")].find((entry) => entry.name.startsWith("venv-"))?.name;
const playbook = venv ? `/opt/orbit-shape-gate/${venv}/bin/ansible-playbook` : "ansible-playbook";
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
        tasks: [{ name: "Apply the seed listener", "ansible.builtin.include_tasks": tasks }],
    }]));
    // The certificate file goes to /run as root on a Node; here it goes to the fixture folder as the test user.
    const text = Deno.readTextFileSync(tasks).replaceAll("/run/orbit-proof-seed.crt", `${folder}/orbit-proof-seed.crt`)
        .replaceAll("owner: root, group: root, ", "");
    await Deno.writeTextFile(`${folder}/tasks.yml`, text);
    const play = Deno.readTextFileSync(`${folder}/play.yml`).replace(tasks, `${folder}/tasks.yml`);
    await Deno.writeTextFile(`${folder}/play.yml`, play);
    const result = await new Deno.Command(playbook, {
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

Deno.test("a first converge listens on the WireGuard address and trusts the default's Node for one project", async () => {
    const folder = await node("", []);
    try {
        assertEquals(await run(folder, true), [
            `config trust add-certificate ${folder}/orbit-proof-seed.crt --name orbit-proof-seed --restricted --projects orbit-instances-nested`,
            "config set core.https_address 10.44.0.30:8444",
        ]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a converge with the listener and the matching trust changes nothing", async () => {
    const folder = await node("10.44.0.30:8444", [entry(certificate)]);
    try {
        assertEquals(await run(folder, true), []);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a trust entry of another certificate, unrestricted or with other projects is replaced", async (t) => {
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

Deno.test("without a sender the trust and the seed listener go, and another listener address stays", async () => {
    const folder = await node("10.44.0.30:8444", [entry(certificate)]);
    try {
        assertEquals(await run(folder, false), ["config trust remove f1", "config unset core.https_address"]);
    } finally { await Deno.remove(folder, { recursive: true }); }
    const kept = await node("10.44.0.30:9443", []);
    try {
        assertEquals(await run(kept, false), []);
    } finally { await Deno.remove(kept, { recursive: true }); }
});
