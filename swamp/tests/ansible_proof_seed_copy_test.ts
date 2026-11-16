/**
 * The seed copy of the proof default (ansible/proof-seed-copy.yml, docs/cli/instance/seed-copy.mmd), its plays run by Ansible on this
 * machine against a stub `incus` that logs each call. The plays address two Nodes over SSH on a worker; here both are localhost.
 */
import { assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";

const book = fileURLToPath(new URL("../ansible/proof-seed-copy.yml", import.meta.url));
const venv = [...Deno.readDirSync("/opt/orbit-shape-gate")].find((entry) => entry.name.startsWith("venv-"))?.name;
const playbook = venv ? `/opt/orbit-shape-gate/${venv}/bin/ansible-playbook` : "ansible-playbook";
const certificate = "-----BEGIN CERTIFICATE-----\nCI\n-----END CERTIFICATE-----";

type Play = Record<string, unknown> & { name: string };
const intent = (inner: string | null) => ({
    project: "orbit-instances-nested", pool: "orbit-instances", snapshot: "seed-abc", holder: "proof-seed", holder_volume: "proof-seed-inner",
    holder_nic_acl: "orbitinst0-proxy",
    source: { node_id: 7, container: "instance-41", inner_volume: inner },
    targets: [{ node_id: 12, remote: "proof-node-12", listen: "10.44.0.30:8444" }, { node_id: 13, remote: "proof-node-13", listen: "10.44.0.31:8444" }],
});

/** A folder with a stub incus: `remote list` answers from `remotes.json`, `query` from `snapshots.json`, every other call is logged. */
async function fixture(remotes: string[], snapshots: string[]) {
    const folder = await Deno.makeTempDir();
    await Deno.mkdir(`${folder}/bin`);
    await Deno.mkdir(`${folder}/servercerts`);
    await Deno.writeTextFile(`${folder}/remotes.json`, JSON.stringify(Object.fromEntries(remotes.map((name) => [name, {}]))));
    await Deno.writeTextFile(`${folder}/snapshots.json`, JSON.stringify(snapshots.map((name) => ({ name }))));
    await Deno.writeTextFile(`${folder}/certificate-12.pem`, certificate);
    await Deno.writeTextFile(`${folder}/bin/incus`, `#!/bin/sh
case "$1 $2" in
  "remote list") cat '${folder}/remotes.json';;
  "query --") cat '${folder}/snapshots.json';;
  "remote add") echo "$*" >> '${folder}/calls'; cp '${folder}/certificate-12.pem' '${folder}/servercerts/'"$3"'.crt';;
  *) echo "$*" >> '${folder}/calls';;
esac
exit 0
`);
    await Deno.chmod(`${folder}/bin/incus`, 0o755);
    return folder;
}

/** Runs the named plays of the book on localhost, with the facts the first play gives each Node. */
async function run(folder: string, names: string[], seed: ReturnType<typeof intent>, target = 12) {
    const plays = (parse(Deno.readTextFileSync(book)) as Play[]).filter((play) => names.includes(play.name)).map((play) => ({
        ...play, hosts: "localhost", connection: "local", become: false,
        vars: { ...(play.vars as Record<string, unknown>), seed_intent: seed, seed_target: seed.targets.find((item) => item.node_id === target),
            ansible_python_interpreter: "{{ ansible_playbook_python }}" },
    }));
    const text = JSON.stringify(plays).replaceAll("/root/.config/incus/servercerts", `${folder}/servercerts`);
    await Deno.writeTextFile(`${folder}/play.yml`, text);
    await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify(seed));
    const result = await new Deno.Command(playbook, {
        args: ["-i", "localhost,", `${folder}/play.yml`], stdout: "piped", stderr: "piped",
        env: { PATH: `${folder}/bin:${Deno.env.get("PATH")}`, ORBIT_RUN: folder, ORBIT_TARGET: String(target), ANSIBLE_LOCALHOST_WARNING: "False" },
    }).output();
    const calls = (() => { try { return Deno.readTextFileSync(`${folder}/calls`).trim().split("\n"); } catch { return []; } })();
    return { code: result.code, calls, out: new TextDecoder().decode(result.stdout) };
}

const nested = ["--project", "orbit-instances-nested", "--target-project", "orbit-instances-nested"].join(" ");

Deno.test("the copy adds the CI Node's remote, then pushes the inner volume and the container with refresh, no static address and the common ACL", async () => {
    const folder = await fixture([], []);
    try {
        const { code, calls } = await run(folder, ["Push the seed from the default's Node"], intent("inner-41"));
        assertEquals(code, 0);
        assertEquals(calls, [
            "remote add proof-node-12 https://10.44.0.30:8444 --accept-certificate --auth-type tls",
            `storage volume copy orbit-instances/inner-41 proof-node-12:orbit-instances/proof-seed-inner --refresh --mode=push ${nested}`,
            "copy instance-41 proof-node-12:proof-seed --refresh --mode=push --device eth0,ipv4.address= --device eth0,security.acls=orbitinst0-proxy"
            + ` --device inner-pool,source=proof-seed-inner ${nested}`,
        ]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a default without an inner volume pushes the container alone; a known remote is not added again", async () => {
    const folder = await fixture(["proof-node-12"], []);
    try {
        await Deno.writeTextFile(`${folder}/servercerts/proof-node-12.crt`, certificate + "\n");
        const { code, calls } = await run(folder, ["Push the seed from the default's Node"], intent(null));
        assertEquals(code, 0);
        assertEquals(calls, [
            `copy instance-41 proof-node-12:proof-seed --refresh --mode=push --device eth0,ipv4.address= --device eth0,security.acls=orbitinst0-proxy ${nested}`,
        ]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a remote with another server certificate fails the target before any copy and records the phase", async () => {
    const folder = await fixture(["proof-node-12"], []);
    try {
        await Deno.writeTextFile(`${folder}/servercerts/proof-node-12.crt`, "-----BEGIN CERTIFICATE-----\nOTHER\n-----END CERTIFICATE-----\n");
        const { code, calls } = await run(folder, ["Push the seed from the default's Node"], intent("inner-41"));
        assertEquals(code === 0, false);
        assertEquals(calls, []);
        const outcome = JSON.parse(Deno.readTextFileSync(`${folder}/target-12.json`));
        assertEquals([outcome.status, outcome.detail.includes("another server certificate")], ["failed", true]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the check passes a holder with the admitted seed snapshot, and the report names every target", async () => {
    const folder = await fixture([], ["seed-old", "seed-abc"]);
    try {
        assertEquals((await run(folder, ["Check the holder on the CI Node"], intent("inner-41"))).code, 0);
        assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/target-12.json`)), { status: "succeeded" });
        assertEquals((await run(folder, ["Report every target"], intent("inner-41"))).code, 0);
        assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/result.json`)), {
            targets: [{ node_id: 12, status: "succeeded" }, { node_id: 13, status: "failed", detail: "The target did not finish" }],
        });
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the check fails a holder without the admitted seed snapshot", async () => {
    const folder = await fixture([], ["seed-old"]);
    try {
        assertEquals((await run(folder, ["Check the holder on the CI Node"], intent(null))).code === 0, false);
        assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/target-12.json`)).status, "failed");
    } finally { await Deno.remove(folder, { recursive: true }); }
});
