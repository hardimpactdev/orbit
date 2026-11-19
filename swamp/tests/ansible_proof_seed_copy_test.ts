/**
 * The seed copy of the proof default (ansible/proof-seed-copy.yml, docs/cli/instance/seed-copy.mmd), its plays run by Ansible on this
 * machine against a stub `incus` that logs each call. The plays address two Nodes over SSH on a worker; here both are localhost.
 */
import { assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";

const book = fileURLToPath(new URL("../ansible/proof-seed-copy.yml", import.meta.url));
const hasAnsible = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const certificate = "-----BEGIN CERTIFICATE-----\nCI\n-----END CERTIFICATE-----";

type Play = Record<string, unknown> & { name: string };
const intent = (inner: string | null) => ({
    project: "orbit-instances-nested", pool: "orbit-instances", snapshot: "seed-abc", holder: "proof-seed", holder_volume: "proof-seed-inner",
    holder_nic_acl: "orbitinst0-proxy", release_base: false,
    source: { node_id: 7, container: "instance-41", inner_volume: inner },
    targets: [{ node_id: 12, remote: "proof-node-12", listen: "10.44.0.30:8444" }, { node_id: 13, remote: "proof-node-13", listen: "10.44.0.31:8444" }],
});

/**
 * A folder with a stub incus: `remote list` answers from `remotes.json`, `query` from the snapshot lists, every other call is logged.
 * As the Incus API does, the stub names an instance snapshot `<snapshot>` and a volume snapshot `<volume>/<snapshot>`. The volume holds
 * the instance's snapshots unless the test names its own. `snapshots` are the holder's, `source` the default's, both halves alike.
 * `remote add` rewrites `remotes.json` as Incus rewrites root's client configuration: it reads the file, waits a moment and writes it
 * back with the new remote, with no lock of its own.
 */
async function fixture(remotes: string[], snapshots: string[], volumeSnapshots = snapshots, source: string[] = []) {
    const folder = await Deno.makeTempDir();
    await Deno.mkdir(`${folder}/bin`);
    await Deno.mkdir(`${folder}/servercerts`);
    await Deno.writeTextFile(`${folder}/remotes.json`, JSON.stringify(Object.fromEntries(remotes.map((name) => [name, {}]))));
    await Deno.writeTextFile(`${folder}/snapshots.json`, JSON.stringify(snapshots.map((name) => ({ name }))));
    await Deno.writeTextFile(`${folder}/volume-snapshots.json`, JSON.stringify(volumeSnapshots.map((name) => ({ name: `proof-seed-inner/${name}` }))));
    await Deno.writeTextFile(`${folder}/source-snapshots.json`, JSON.stringify(source.map((name) => ({ name }))));
    await Deno.writeTextFile(`${folder}/source-volume-snapshots.json`, JSON.stringify(source.map((name) => ({ name: `inner-41/${name}` }))));
    for (const node of [12, 13]) await Deno.writeTextFile(`${folder}/certificate-${node}.pem`, certificate);
    await Deno.writeTextFile(`${folder}/bin/incus`, `#!/bin/sh
case "$1 $2" in
  "remote list") cat '${folder}/remotes.json';;
  "query --") case "$3" in
    *proof-seed-inner/*) cat '${folder}/volume-snapshots.json';; */instances/proof-seed/*) cat '${folder}/snapshots.json';;
    */volumes/custom/*) cat '${folder}/source-volume-snapshots.json';; *) cat '${folder}/source-snapshots.json';; esac;;
  "remote add") echo "$*" >> '${folder}/calls'; cp '${folder}/certificate-12.pem' '${folder}/servercerts/'"$3"'.crt'
     python3 -I -c 'import json, sys, time; remotes = json.load(open(sys.argv[1])); time.sleep(2); remotes[sys.argv[2]] = {}; json.dump(remotes, open(sys.argv[1], "w"))' \
       '${folder}/remotes.json' "$3";;
  *) echo "$*" >> '${folder}/calls'
     # Each line of \`push-errors\` fails one push of the inner volume with that error.
     if [ "$1 $2 $3" = "storage volume copy" ] && [ -s '${folder}/push-errors' ]; then
       head -n 1 '${folder}/push-errors' >&2; sed -i 1d '${folder}/push-errors'; exit 1
     fi;;
esac
exit 0
`);
    await Deno.chmod(`${folder}/bin/incus`, 0o755);
    return folder;
}

/**
 * Runs the named plays of the book on localhost, with the facts the first play gives each Node. An `unreachable` run addresses a
 * Node over SSH on a closed port of this machine instead. With several targets, their runs go side by side, as the workflow's do.
 */
async function run(folder: string, names: string[], seed: ReturnType<typeof intent>, target: number | number[] = 12, unreachable = false, tags: string[] = []) {
    const targets = [target].flat();
    for (const node of targets) {
        const plays = (parse(Deno.readTextFileSync(book)) as Play[]).filter((play) => names.includes(play.name)).map((play) => ({
            ...play, hosts: unreachable ? "gone" : "localhost", ...(unreachable ? {} : { connection: "local" }), become: false,
            vars: { ...(play.vars as Record<string, unknown>), seed_intent: seed, seed_target: seed.targets.find((item) => item.node_id === node),
            ansible_python_interpreter: "{{ ansible_playbook_python }}",
                ...(unreachable ? { ansible_host: "127.0.0.1", ansible_port: 1, ansible_ssh_common_args: "-o ConnectTimeout=2 -o BatchMode=yes" } : {}) },
        }));
        const text = JSON.stringify(plays).replaceAll("/root/.config/incus/servercerts", `${folder}/servercerts`)
            .replaceAll("/run/lock/", `${folder}/`);
        await Deno.writeTextFile(`${folder}/play-${node}.yml`, text);
    }
    await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify(seed));
    const results = await Promise.all(targets.map((node) => new Deno.Command("ansible-playbook", {
        args: ["-i", unreachable ? "gone," : "localhost,", ...(tags.length ? ["--tags", tags.join(",")] : []), `${folder}/play-${node}.yml`], stdout: "piped", stderr: "piped",
        env: { PATH: `${folder}/bin:${Deno.env.get("PATH")}`, ORBIT_RUN: folder, ORBIT_TARGET: String(node), ANSIBLE_LOCALHOST_WARNING: "False" },
    }).output()));
    const calls = (() => { try { return Deno.readTextFileSync(`${folder}/calls`).trim().split("\n"); } catch { return []; } })();
    return { code: Math.max(...results.map((result) => result.code)), calls, out: results.map((result) => new TextDecoder().decode(result.stdout)).join("\n") };
}

const nested = ["--project", "orbit-instances-nested", "--target-project", "orbit-instances-nested"].join(" ");

Deno.test("the copy adds the CI Node's remote, then pushes the inner volume and the container with refresh, no static address and the common ACL", { ignore: !hasAnsible }, async () => {
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

Deno.test("a default without an inner volume pushes the container alone; a known remote is not added again", { ignore: !hasAnsible }, async () => {
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

Deno.test("a remote with another server certificate fails the target before any copy and records the phase", { ignore: !hasAnsible }, async () => {
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

Deno.test("an unreachable Node fails its phase with the SSH error, in each phase, and a later phase does nothing for the target", { ignore: !hasAnsible }, async () => {
    for (const play of ["Read the certificate of the CI Node", "Push the seed from the default's Node", "Check the holder on the CI Node"]) {
        const folder = await fixture([], []);
        try {
            const { code, calls } = await run(folder, [play], intent("inner-41"), 12, true);
            assertEquals(code !== 0, true, play);
            assertEquals(calls, [], play);
            const outcome = JSON.parse(await Deno.readTextFile(`${folder}/target-12.json`));
            assertEquals(outcome.status, "failed", play);
            assertEquals(outcome.detail.startsWith("The Node is unreachable: "), true, outcome.detail);
            assertEquals(/ssh|connect|port 1/i.test(outcome.detail), true, outcome.detail);
        } finally { await Deno.remove(folder, { recursive: true }); }
    }
});

Deno.test("a target whose earlier phase left no result fails in that phase, and the later phases do nothing for it", { ignore: !hasAnsible }, async () => {
    // The Node went away in the middle of a phase: no product and no outcome. Ansible rescues no unreachable host.
    for (const [phase, lost, files] of [["copy", "certificate", []], ["check", "certificate", []], ["check", "copy", ["certificate-12.pem"]]] as [string, string, string[]][]) {
        const folder = await fixture([], ["seed-abc"]);
        try {
            await Deno.remove(`${folder}/certificate-12.pem`);
            for (const file of files) await Deno.writeTextFile(`${folder}/${file}`, certificate);
            const first = await run(folder, ["Pin the two Nodes and address them"], intent("inner-41"), 12, false, [phase]);
            assertEquals(first.code, 0, first.out);
            assertEquals(JSON.parse(await Deno.readTextFile(`${folder}/target-12.json`)), { status: "failed", failed_phase: lost,
                detail: `The ${lost} phase ended without a result: the Node was unreachable, or the phase was cut` });
            // The phase's own play gets no host, as for any target that failed earlier: a holder with the seed's name is not checked.
            assertEquals(first.out.includes("Pin the host keys of both Nodes"), false, first.out);
            assertEquals(first.calls, []);
        } finally { await Deno.remove(folder, { recursive: true }); }
    }
});

Deno.test("a finished copy leaves its mark, and a copy that fails leaves none", { ignore: !hasAnsible }, async () => {
    const folder = await fixture([], []);
    try {
        assertEquals((await run(folder, ["Push the seed from the default's Node"], intent("inner-41"))).code, 0);
        assertEquals((await Deno.stat(`${folder}/copied-12`)).isFile, true);
    } finally { await Deno.remove(folder, { recursive: true }); }
    const other = await fixture(["proof-node-12"], []);
    try {
        await Deno.writeTextFile(`${other}/servercerts/proof-node-12.crt`, "another certificate\n");
        assertEquals((await run(other, ["Push the seed from the default's Node"], intent("inner-41"))).code !== 0, true);
        assertEquals(await Deno.stat(`${other}/copied-12`).then(() => true, () => false), false);
    } finally { await Deno.remove(other, { recursive: true }); }
});

Deno.test("the check passes a holder with the admitted seed snapshot, and the report names every target", { ignore: !hasAnsible }, async () => {
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

Deno.test("the check fails a holder without the admitted seed snapshot", { ignore: !hasAnsible }, async () => {
    const folder = await fixture([], ["seed-old"]);
    try {
        assertEquals((await run(folder, ["Check the holder on the CI Node"], intent(null))).code === 0, false);
        assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/target-12.json`)).status, "failed");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the check fails a holder whose instance holds the admitted seed snapshot but whose volume does not", { ignore: !hasAnsible }, async () => {
    const folder = await fixture([], ["seed-old", "seed-abc"], ["seed-old"]);
    try {
        assertEquals((await run(folder, ["Check the holder on the CI Node"], intent("inner-41"))).code === 0, false);
        assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/target-12.json`)).status, "failed");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the holder's seed takes the name of the default's base before the refresh, so both sides share it and the push sends only the change", { ignore: !hasAnsible }, async () => {
    // docs/reference/development-containers.md#how-the-seed-moves: the default kept `seed-old` as `base-old`; the CI Node holds `seed-old`.
    const folder = await fixture(["proof-node-12"], ["seed-old"], ["seed-old"], ["base-old", "seed-abc"]);
    try {
        await Deno.writeTextFile(`${folder}/servercerts/proof-node-12.crt`, certificate + "\n");
        const { code, calls } = await run(folder, ["Push the seed from the default's Node"], intent("inner-41"));
        assertEquals(code, 0);
        assertEquals(calls, [
            "snapshot rename proof-node-12:proof-seed seed-old base-old --project orbit-instances-nested",
            "storage volume snapshot rename proof-node-12:orbit-instances proof-seed-inner seed-old base-old --project orbit-instances-nested",
            `storage volume copy orbit-instances/inner-41 proof-node-12:orbit-instances/proof-seed-inner --refresh --mode=push ${nested}`,
            "copy instance-41 proof-node-12:proof-seed --refresh --mode=push --device eth0,ipv4.address= --device eth0,security.acls=orbitinst0-proxy"
            + ` --device inner-pool,source=proof-seed-inner ${nested}`,
        ]);
    } finally { await Deno.remove(folder, { recursive: true }); }
    // A holder that has the base's name already, or holds another seed, is pushed as it is: a rerun renames nothing.
    for (const holder of [["base-old"], ["seed-older"]]) {
        const again = await fixture(["proof-node-12"], holder, holder, ["base-old", "seed-abc"]);
        try {
            await Deno.writeTextFile(`${again}/servercerts/proof-node-12.crt`, certificate + "\n");
            const { code, calls } = await run(again, ["Push the seed from the default's Node"], intent("inner-41"));
            assertEquals(code, 0);
            assertEquals(calls.filter((call) => call.includes("rename")), [], holder.join());
        } finally { await Deno.remove(again, { recursive: true }); }
    }
});

Deno.test("a copy to every Node behind the seed releases the default's base when each target passed and the seed did not move", { ignore: !hasAnsible }, async () => {
    const released = { ...intent("inner-41"), release_base: true };
    const cases: [string, ReturnType<typeof intent>, string[], string[]][] = [
        ["all passed", released, ["succeeded", "succeeded"], ["base-old", "seed-abc"]],
        ["one failed", released, ["succeeded", "failed"], ["base-old", "seed-abc"]],
        ["not every Node behind", intent("inner-41"), ["succeeded", "succeeded"], ["base-old", "seed-abc"]],
        ["the seed moved", released, ["succeeded", "succeeded"], ["base-abc", "seed-new"]],
    ];
    for (const [name, seed, outcomes, source] of cases) {
        const folder = await fixture([], [], [], source);
        try {
            for (const [index, status] of outcomes.entries()) {
                await Deno.writeTextFile(`${folder}/target-${seed.targets[index].node_id}.json`, JSON.stringify({ status }));
            }
            const { code, calls } = await run(folder, ["Release the default's base"], seed);
            assertEquals(code, 0, name);
            assertEquals(calls, name === "all passed" ? [
                "snapshot delete instance-41 base-old --project orbit-instances-nested",
                "storage volume snapshot delete orbit-instances inner-41 base-old --project orbit-instances-nested",
            ] : [], name);
        } finally { await Deno.remove(folder, { recursive: true }); }
    }
});

Deno.test("a failed push fails only its target and is not tried again", { ignore: !hasAnsible }, async () => {
    const folder = await fixture([], []);
    try {
        await Deno.writeTextFile(`${folder}/push-errors`, "Error: no space left on the target\n");
        const { code, calls } = await run(folder, ["Push the seed from the default's Node"], intent("inner-41"));
        assertEquals(code !== 0, true);
        assertEquals(calls.filter((call) => call.startsWith("storage volume copy")).length, 1, "an error that is not the freeze is not tried again");
        const outcome = JSON.parse(Deno.readTextFileSync(`${folder}/target-12.json`));
        assertEquals([outcome.status, outcome.detail.includes("no space left")], ["failed", true]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a push whose freeze meets the other target's push is tried again and passes", { ignore: !hasAnsible }, async () => {
    // Incus zfs MigrateVolume: `fsfreeze --freeze` of a file system that another push froze a moment ago fails with EBUSY.
    const folder = await fixture([], []);
    try {
        await Deno.writeTextFile(`${folder}/push-errors`, 'Error: Failed freezing filesystem "/var/lib/incus/storage-pools/x": exit status 1 (fsfreeze: freeze failed: Device or resource busy)\n');
        const { code, calls } = await run(folder, ["Push the seed from the default's Node"], intent("inner-41"));
        assertEquals(code, 0);
        assertEquals(calls.filter((call) => call.startsWith("storage volume copy")).length, 2);
        assertEquals((await Deno.stat(`${folder}/copied-12`)).isFile, true);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("two copies side by side to CI Nodes that root does not know yet both add their remote, and both stay", { ignore: !hasAnsible }, async () => {
    const folder = await fixture([], []);
    try {
        const { code, calls, out } = await run(folder, ["Push the seed from the default's Node"], intent("inner-41"), [12, 13]);
        assertEquals(code, 0, out);
        assertEquals(calls.filter((call) => call.startsWith("remote add")).length, 2);
        assertEquals(Object.keys(JSON.parse(await Deno.readTextFile(`${folder}/remotes.json`))).sort(), ["proof-node-12", "proof-node-13"]);
        for (const node of [12, 13]) assertEquals((await Deno.stat(`${folder}/copied-${node}`)).isFile, true);
    } finally { await Deno.remove(folder, { recursive: true }); }
});
