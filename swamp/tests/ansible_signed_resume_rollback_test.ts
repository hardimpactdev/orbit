/**
 * A failed deployment of a frozen nested-fleet default (ADR 0266, rule R1): the first start resumes the frozen container through a signed
 * order, the rollback stops it and restores the seed, and the second start on the same Operation gets the executor's repeated receipt.
 * The real container state then decides, so the restored seed boots. The start tasks are the shipped ones; the relay answers as the
 * executor's journal does (its own outcomes are in ansible_executor_power_relay_test.ts).
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";

const available = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
type Task = Record<string, unknown> & { name: string };
const shipped = parse(Deno.readTextFileSync(new URL("../ansible/tasks/instance-container-phase.yml", import.meta.url))) as Task[];
const named = (name: string) => {
    const task = shipped.find((each) => each.name === name);
    assert(task, name);
    return task;
};
const start = [
    "Read the container", "Read the kernel's freeze of a running container", "Note the container's state before the start",
    "Recover a signed wake or resume a frozen container", "Refuse a signed wake that discovered a stopped nested fleet",
    "Discard stale signed state before a lifecycle boot", "Start the container",
].map(named);
const rollback = (named("Restore the newest seed").block as Task[])
    .filter((task) => ["Read the container's state", "Stop the container", "Restore the container to the newest seed"].includes(task.name));

Deno.test({
    name: "a failed deploy's rollback boots the restored seed of a frozen nested default after the executor repeats its resume receipt",
    ignore: !available,
    fn() {
        assertEquals(rollback.length, 3);
        const folder = Deno.makeTempDirSync();
        try {
            Deno.mkdirSync(`${folder}/bin`);
            Deno.mkdirSync(`${folder}/containers/instance-7/etc`, { recursive: true });
            Deno.writeTextFileSync(`${folder}/containers/instance-7/etc/machine-id`, "0123456789abcdef0123456789abcdef\n");
            Deno.writeTextFileSync(`${folder}/bin/incus`,
                `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-container-incus.ts", import.meta.url).pathname}' "$@"\n`);
            Deno.chmodSync(`${folder}/bin/incus`, 0o755);
            Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({ containers: { "instance-7": { status: "Frozen", snapshots: ["seed-1"] } } }));
            // The executor's journal: the first resume of the Operation acts and unfreezes; a later one returns that result with `repeat`.
            Deno.writeTextFileSync(`${folder}/executor-power-relay.yml`, stringify([
                { name: "Record the signed phase", "ansible.builtin.shell": `echo '{{ executor_phase }}' >> '${folder}/orders'` },
                { name: "Act once", when: "executor_receipt is not defined", "ansible.builtin.command": { argv: ["incus", "start", "instance-7"] } },
                { name: "Answer from the journal", "ansible.builtin.set_fact": {
                    executor_receipt: "{{ {'status': 'done', 'action': 'wake', 'operation': '41', 'target': '7', 'delivery': 'swamp'} | combine({'repeat': true} if executor_receipt is defined else {}) }}" } },
            ]));
            const tagged = (tasks: Task[]) => tasks.map((task) => ({ ...task, tags: ["start"] }));
            Deno.writeTextFileSync(`${folder}/play.yml`, stringify([{
                hosts: "localhost", gather_facts: false,
                vars: {
                    phase: "start", incus_project: ["incus", "--project", "orbit-instances-nested"], restore_seed: "seed-1",
                    container: { instance_id: 7, name: "instance-7", project: "orbit-instances-nested", allowed_devices: [] },
                    intent: { signed_power: true, executor_lifecycle_phase: "resume" },
                },
                tasks: [
                    ...tagged(start),
                    { name: "Keep the first start's state", "ansible.builtin.set_fact": { first_found: "{{ start_found }}", first_resumed: "{{ start_resumed }}" } },
                    ...rollback,
                    ...tagged(start),
                    { name: "Write what the second start saw", "ansible.builtin.copy": { dest: `${folder}/result.json`,
                        content: "{{ {'first': first_found, 'first_resumed': first_resumed, 'second': start_found, 'resumed': start_resumed, 'repeat': executor_receipt.repeat | default(false)} | to_json }}" } },
                ],
            }]));
            const done = new Deno.Command("ansible-playbook", {
                args: ["-i", "localhost,", "-c", "local", "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", `${folder}/play.yml`],
                env: { HOST_FIXTURE: folder, ORBIT_OPERATION_ID: "41", PATH: `${folder}/bin:${Deno.env.get("PATH")}`, ANSIBLE_NOCOLOR: "1",
                    ANSIBLE_HOME: `${folder}/ansible` },
            }).outputSync();
            const output = new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr);
            assert(done.success, output);
            // The first start resumed the frozen container; the second read the stopped seed under a repeated receipt and booted it.
            assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/result.json`)),
                { first: "Frozen", first_resumed: true, second: "Stopped", resumed: false, repeat: true });
            assertEquals(Deno.readTextFileSync(`${folder}/orders`).trim().split("\n"), ["resume", "resume"]);
            const calls = Deno.readTextFileSync(`${folder}/calls`).trim().split("\n").map((line) => (JSON.parse(line) as string[]).join(" "))
                .filter((call) => !call.startsWith("query"));
            assertEquals(calls, ["start instance-7", "--project orbit-instances-nested stop --timeout 30 instance-7",
                "--project orbit-instances-nested snapshot restore instance-7 seed-1", "--project orbit-instances-nested start instance-7"]);
            assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/state.json`)).containers["instance-7"].status, "Running");
        } finally {
            Deno.removeSync(folder, { recursive: true });
        }
    },
});
