/** Exercise production container deletion, including a migrated checkout with retained Git, against the Incus fixture. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";
import { helperCalls, installPowerHelper } from "./_support/power_helper.ts";
// The "Delete the container" step of a normal destroy, its guard and its env evaluated from the shipped workflow.
type Step = { name: string; guard: string; task: { inputs: { env: Record<string, string>; run?: string } } };
const deleteStep = (parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-destroy.yaml", import.meta.url))) as {
    jobs: { steps: Step[] }[];
}).jobs.flatMap((job) => job.steps).find((step) => step.name === "Delete the container")!;
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const shipped = new URL("../ansible/", import.meta.url).pathname.replace(/\/$/, "");
const runId = "0f0e0d0c-0b0a-4908-8706-050403020100";
const normal = { intent: { container_destroy: { name: "instance-7" } }, node_id: 9 };
const settled = { "Prepare container removal": { status: "succeeded", outputs: { assets: shipped } }, "Withdraw the Route": { status: "skipped" } };
const evaluate = (expr: string) =>
    expr.startsWith("${{") ? cel.evaluate(expr.slice(3, -2).trim(), { inputs: normal, steps: settled, run: { id: runId } }) : expr;
const stepEnv = Object.fromEntries(Object.entries(deleteStep.task.inputs.env).map(([key, value]) => [key, String(evaluate(value))]));
Deno.test("a normal destroy deletes the container over one pipelined SSH master with the shipped config", () => {
    assertEquals(evaluate(deleteStep.guard), false);
    assertEquals(stepEnv.ANSIBLE_CONFIG, `${shipped}/ansible.cfg`);
    assert(Deno.readTextFileSync(stepEnv.ANSIBLE_CONFIG).includes("pipelining = True"));
    for (const option of ["ControlMaster=auto", "ControlPersist=60s", `UserKnownHostsFile=/tmp/orbit-ansible-${runId}/known_hosts`, "StrictHostKeyChecking=yes"]) {
        assert(stepEnv.ANSIBLE_SSH_ARGS.includes(`-o ${option}`), option);
    }
});
Deno.test("a container removal stops the container before its databases drop and deletes it after them", () => {
    type Placed = Step & { dependsOn?: { step: string }[] };
    const all = (parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-destroy.yaml", import.meta.url))) as {
        jobs: { steps: Placed[] }[];
    }).jobs.flatMap((job) => job.steps);
    const named = (name: string) => all.find((step) => step.name === name)!;
    const after = (name: string) => (named(name).dependsOn ?? []).map((dependency) => dependency.step);
    assert(after("Drop owned databases").includes("Stop the container"));
    assert(after("Delete the container").includes("Drop owned databases"));
    assert(all.findIndex((step) => step.name === "Stop the container") < all.findIndex((step) => step.name === "Drop owned databases"));
    // The stop runs on the container path only, and like the deletion it stops once a step failed or the withdrawal did not hold.
    const stop = named("Stop the container");
    assertEquals(stop.guard, deleteStep.guard);
    assertEquals(evaluate(stop.guard), false);
    assertEquals(cel.evaluate(stop.guard.slice(3, -2).trim(), { inputs: { intent: { container_destroy: null } }, steps: settled }), true);
    assert(String(stop.task.inputs.run).includes("--tags stop"));
});
const available =
    new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
        .outputSync().success;
Deno.test({
    name:
        "migrated sleeping container cleanup keeps retained Git while removing its container, volume, ACL, own rules (while another removal shifts rule numbers), asleep marker, mirror and task base scratch; a retry changes nothing",
    ignore: !available,
    fn() {
        const folder = Deno.makeTempDirSync();
        const root = new URL("../ansible/", import.meta.url);
        const retained = `${folder}/old-checkout.orbit-retired-git/${
            "a".repeat(64)
        }`;
        const git = (...args: string[]) => {
            const done = new Deno.Command("git", { args }).outputSync();
            assert(done.success, new TextDecoder().decode(done.stderr));
            return new TextDecoder().decode(done.stdout).trim();
        };
        try {
            for (
                const dir of [
                    "bin",
                    "run",
                    "tasks",
                    "containers/instance-7",
                    "containers/instance-8",
                    "mirror.git",
                ]
            ) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
            Deno.mkdirSync(retained, { recursive: true });
            git("init", "--quiet", "--initial-branch=main", retained);
            git(
                "-C",
                retained,
                "-c",
                "user.name=Fixture",
                "-c",
                "user.email=fixture@example.test",
                "commit",
                "--quiet",
                "--allow-empty",
                "-m",
                "retained commit",
            );
            const retainedCommit = git("-C", retained, "rev-parse", "HEAD");
            Deno.writeTextFileSync(
                `${folder}/instance-container-destroy.yml`,
                Deno.readTextFileSync(new URL("instance-container-destroy.yml", root))
                    .replaceAll("/var/lib/orbit/instance-mail", `${folder}/mail-state`),
            );
            // The stopped instance-7 sleeps: its asleep marker goes with it, and instance-8's stays.
            Deno.mkdirSync(`${folder}/hibernation`);
            for (const id of [7, 8]) Deno.writeTextFileSync(`${folder}/hibernation/app-instance-${id}.asleep`, "");
            for (const name of ["instance-7", "instance-8"]) {
                Deno.mkdirSync(`${folder}/mail-state/${name}`, {recursive: true});
                Deno.writeTextFileSync(`${folder}/mail-state/${name}/activation.json`, "[]");
            }
            Deno.copyFileSync(
                new URL("tasks/address-node.yml", root),
                `${folder}/tasks/address-node.yml`,
            );
            // The one rule that names a task copy's base scratch, shipped beside the playbook as it is on a Node.
            Deno.copyFileSync(
                new URL("tasks/task-copy-base-names.yml", root),
                `${folder}/tasks/task-copy-base-names.yml`,
            );
            // The power helper discards the Instance's plan, sleep result, swap record and fast wake files (_support/power_helper.ts).
            installPowerHelper(folder, folder);
            // A base scratch that a cancelled task:sync of this Instance left, named by its mirror's Instance, and another Instance's.
            for (const name of ["orbit-task-base-mirror", "orbit-task-base-8"]) {
                Deno.mkdirSync(`${folder}/task-base/${name}`, { recursive: true });
                Deno.writeTextFileSync(`${folder}/task-base/${name}/base.bundle`, "bundle");
            }
            Deno.writeTextFileSync(
                `${folder}/bin/incus`,
                `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${
                    new URL(
                        "fixtures/instance-container-incus.ts",
                        import.meta.url,
                    ).pathname
                }' "$@"\n`,
            );
            Deno.writeTextFileSync(
                `${folder}/bin/ufw`,
                `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${
                    new URL("fixtures/instance-container-ufw.ts", import.meta.url).pathname
                }' "$@"\n`,
            );
            for (const name of ["incus", "ufw"]) {
                Deno.chmodSync(`${folder}/bin/${name}`, 0o755);
            }
            const rule = (spec: string, comment: string) => ({ spec, comment });
            Deno.writeTextFileSync(
                `${folder}/ufw.json`,
                JSON.stringify([
                    rule("route allow in on orbit out on orbitinst0 to 10.253.0.5 port 3773 proto tcp", "orbit:instance-5-t3"),
                    rule("route deny in on orbit out on orbitinst0 from 10.44.0.128/25 to 10.253.0.7 port 3773 proto tcp", "orbit:instance-7-t3-task-vms"),
                    rule("route allow in on orbit out on orbitinst0 to 10.253.0.7 port 3773 proto tcp", "orbit:instance-7-t3"),
                    rule("route allow in on orbit out on orbitinst0 to 10.253.0.8 port 3773 proto tcp", "orbit:instance-8-t3"),
                    rule("allow in on orbitinst0 to 10.253.0.70 port 3800 proto tcp", "orbit:instance-70-t3"),
                ]),
            );
            // Another removal deletes instance-5's rule between this playbook's read and its first delete.
            Deno.writeTextFileSync(`${folder}/ufw-concurrent`, "orbit:instance-5-t3");
            const smtp = {action: "allow", destination: "10.253.0.1/32", protocol: "tcp", destination_port: "1025", state: "enabled"};
            const sharedMail = JSON.stringify({host: "10.253.0.1", port: 1025, docker: true, translated: {host: "172.17.0.2", port: 1025}});
            Deno.mkdirSync(`${folder}/bridge/endpoints`, {recursive: true});
            Deno.writeTextFileSync(`${folder}/bridge/endpoints/mailpit.json`, sharedMail);
            Deno.writeTextFileSync(
                `${folder}/state.json`,
                JSON.stringify({
                    containers: {
                        "instance-7": {
                            status: "Stopped",
                            snapshots: ["seed"],
                            added: { t3: {} },
                        },
                        "instance-8": { status: "Running" },
                    },
                    "network-acls": [{name: "orbitinst0-egress", egress: [{action: "allow", source: "10.253.0.0/24", destination: "10.44.0.7/32", protocol: "tcp", destination_port: "3306", state: "enabled"}, {action: "allow", source: "10.253.0.0/24", destination: "172.17.0.2/32", protocol: "tcp", destination_port: "1025", state: "enabled"}]}, { name: "instance-7", egress: [smtp] }, {
                        name: "instance-8", egress: [smtp],
                    }],
                }),
            );
            Deno.writeTextFileSync(
                `${folder}/run/intent.json`,
                JSON.stringify({
                    ssh_execution: {
                        identity_file: "/fixture",
                        target: {
                            host: "127.0.0.1",
                            port: 22,
                            user: "orbit",
                            host_keys: [
                                "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl",
                            ],
                        },
                    },
                    container_destroy: {
                        instance_id: 7,
                        name: "instance-7",
                        project: "orbit-instances",
                        pool: "orbit-instances",
                        acl: "instance-7",
                        mirror: `${folder}/mirror.git`,
                        firewall_prefix: "orbit:instance-7-",
                        asleep_marker: `${folder}/hibernation/app-instance-7.asleep`,
                    },
                }),
            );
            const run = () => {
                const done = new Deno.Command("ansible-playbook", {
                    args: [
                        "-i",
                        "localhost,",
                        "--tags",
                        "remove",
                        "-e",
                        "ansible_connection=local",
                        "-e",
                        "ansible_become=false",
                        "-e",
                        "ansible_python_interpreter={{ ansible_playbook_python }}",
                        "-e",
                        `task_base_root=${folder}/task-base`,
                        `${folder}/instance-container-destroy.yml`,
                    ],
                    env: {
                        // The step's own Ansible settings, so the shipped config is proven to run this playbook.
                        ANSIBLE_CONFIG: stepEnv.ANSIBLE_CONFIG,
                        ANSIBLE_SSH_ARGS: stepEnv.ANSIBLE_SSH_ARGS,
                        ANSIBLE_DISPLAY_FAILED_STDERR: stepEnv.ANSIBLE_DISPLAY_FAILED_STDERR,
                        HOST_FIXTURE: folder,
                        ORBIT_RUN: `${folder}/run`,
                        PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
                        ANSIBLE_HOME: `${folder}/ansible`,
                        ANSIBLE_NOCOLOR: "1",
                    },
                }).outputSync();
                return {
                    ok: done.success,
                    output: new TextDecoder().decode(done.stdout) +
                        new TextDecoder().decode(done.stderr),
                };
            };
            let done = run();
            assert(done.ok, done.output);
            const state = JSON.parse(
                Deno.readTextFileSync(`${folder}/state.json`),
            );
            assertEquals(Object.keys(state.containers), ["instance-8"]);
            // The destroyed Instance's base scratch goes; another Instance's stays.
            assertEquals([...Deno.readDirSync(`${folder}/task-base`)].map((entry) => entry.name), ["orbit-task-base-8"]);
            assertEquals([...Deno.readDirSync(`${folder}/hibernation`)].map((entry) => entry.name), ["app-instance-8.asleep"]);
            // The helper's files of instance-7 go through its lock; instance-8's stay.
            assertEquals([helperCalls(folder), state.discarded], [[["discard", "--instance", "7"]], [7]]);
            assert(!Deno.statSync(`${folder}/mail-state`).isFile);
            assertEquals([...Deno.readDirSync(`${folder}/mail-state`)].map((entry) => entry.name), ["instance-8"]);
            assertEquals(Deno.readTextFileSync(`${folder}/mail-state/instance-8/activation.json`), "[]");
            assertEquals(state["network-acls"].filter((acl: {name: string}) => acl.name !== "orbitinst0-egress"), [{ name: "instance-8", egress: [smtp] }]);
            assert(!state["network-acls"].some((acl: {name: string}) => acl.name === "instance-7"));
            assertEquals(state["network-acls"].find((acl: {name: string}) => acl.name === "orbitinst0-egress").egress[1].destination, "172.17.0.2/32");
            assertEquals(Deno.readTextFileSync(`${folder}/bridge/endpoints/mailpit.json`), sharedMail);
            // A concurrent destroy never withdraws another Instance's bridge endpoint. Host reconcile owns removal.
            assertEquals(state["network-acls"].find((acl: {name: string}) => acl.name === "orbitinst0-egress").egress[0].destination, "10.44.0.7/32");
            // Only instance-7's two rules went; the shifted numbers did not take instance-8's or instance-70's.
            assertEquals(
                JSON.parse(Deno.readTextFileSync(`${folder}/ufw.json`)).map((r: { comment: string }) => r.comment),
                ["orbit:instance-8-t3", "orbit:instance-70-t3"],
            );
            assertEquals(
                JSON.parse(Deno.readTextFileSync(`${folder}/run/remove.json`)),
                { container_present: true, removed: true },
            );
            assertEquals(
                git("-C", retained, "rev-parse", "HEAD"),
                retainedCommit,
            );
            assertEquals(
                git("-C", retained, "cat-file", "-t", retainedCommit),
                "commit",
            );
            done = run();
            assert(done.ok, done.output);
            assertEquals(
                git("-C", retained, "rev-parse", "HEAD"),
                retainedCommit,
            );
            assert(/node\s*:.*changed=0/.test(done.output), done.output);
        } finally {
            Deno.removeSync(folder, { recursive: true });
        }
    },
});
Deno.test({
    name: "a destroy deletes the inner volume its intent names, with its snapshots, after the container; another volume stays and a retry changes nothing",
    ignore: !available,
    fn() {
        const folder = Deno.makeTempDirSync();
        const root = new URL("../ansible/", import.meta.url);
        try {
            for (const dir of ["bin", "run", "tasks", "mirror.git", "containers/instance-7", "containers/instance-8"]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
            Deno.writeTextFileSync(`${folder}/instance-container-destroy.yml`,
                Deno.readTextFileSync(new URL("instance-container-destroy.yml", root)).replaceAll("/var/lib/orbit/instance-mail", `${folder}/mail-state`));
            Deno.copyFileSync(new URL("tasks/address-node.yml", root), `${folder}/tasks/address-node.yml`);
            Deno.copyFileSync(new URL("tasks/task-copy-base-names.yml", root), `${folder}/tasks/task-copy-base-names.yml`);
            // The power helper discards the Instance's plan, sleep result, swap record and fast wake files (_support/power_helper.ts).
            installPowerHelper(folder, folder);
            Deno.writeTextFileSync(`${folder}/bin/incus`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-container-incus.ts", import.meta.url).pathname}' "$@"\n`);
            Deno.writeTextFileSync(`${folder}/bin/ufw`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-container-ufw.ts", import.meta.url).pathname}' "$@"\n`);
            for (const name of ["incus", "ufw"]) Deno.chmodSync(`${folder}/bin/${name}`, 0o755);
            Deno.writeTextFileSync(`${folder}/ufw.json`, "[]");
            const inner = (name: string) => ({ pool: "orbit-instances", project: "orbit-instances-nested", snapshots: ["seed-1"], config: { "zfs.block_mode": "true", "block.filesystem": "btrfs", size: "30GiB", "volatile.uuid": name } });
            Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({
                containers: {
                    "instance-7": { status: "Running", snapshots: ["seed-1"], added: { "inner-pool": { type: "disk", settings: ["pool=orbit-instances", "source=inner-7", "path=/srv/inner-pool"] } } },
                    "instance-8": { status: "Running", added: { "inner-pool": { type: "disk", settings: ["pool=orbit-instances", "source=inner-8", "path=/srv/inner-pool"] } } },
                },
                volumes: { "inner-7": inner("inner-7"), "inner-8": inner("inner-8") },
                "network-acls": [{ name: "instance-7", egress: [] }],
            }));
            const intent = (destroy: Record<string, unknown>) => Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({
                ssh_execution: { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } },
                container_destroy: { project: "orbit-instances-nested", pool: "orbit-instances", mirror: `${folder}/mirror.git`, ...destroy },
            }));
            const run = () => {
                const done = new Deno.Command("ansible-playbook", {
                    args: ["-i", "localhost,", "--tags", "remove", "-e", "ansible_connection=local", "-e", "ansible_become=false",
                        "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", "-e", `task_base_root=${folder}/task-base`,
                        `${folder}/instance-container-destroy.yml`],
                    env: { ANSIBLE_CONFIG: stepEnv.ANSIBLE_CONFIG, HOST_FIXTURE: folder, ORBIT_RUN: `${folder}/run`, PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
                        ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1" },
                }).outputSync();
                return { ok: done.success, output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr) };
            };
            const calls = () => Deno.readTextFileSync(`${folder}/calls`).trim().split("\n").map((line) => JSON.parse(line) as string[]);
            const state = () => JSON.parse(Deno.readTextFileSync(`${folder}/state.json`));
            intent({ instance_id: 7, name: "instance-7", acl: "instance-7", firewall_prefix: "orbit:instance-7-", inner_volume: "inner-7" });
            let done = run();
            assert(done.ok, done.output);
            assertEquals(Object.keys(state().containers), ["instance-8"]);
            assertEquals(Object.keys(state().volumes), ["inner-8"]);
            assertEquals(state().discarded, [7]);
            const order = calls().map((call) => call.join(" "));
            const deleted = order.findIndex((call) => call === "--project orbit-instances-nested delete --force instance-7");
            const volume = order.findIndex((call) => call === "--project orbit-instances-nested storage volume delete orbit-instances inner-7");
            assert(deleted >= 0 && deleted < volume, JSON.stringify(order));
            // Incus deleted both at once, so the removal read no pool, snapshot or dataset: as many Incus calls as before the give-back.
            assert(!order.some((call) => call.includes("/1.0/storage-pools/orbit-instances?") || call.endsWith("/1.0/storage-pools/orbit-instances")
                || call.includes("/snapshots?")), JSON.stringify(order));
            // A retry finds the container and the volume gone and deletes nothing.
            Deno.writeTextFileSync(`${folder}/calls`, "");
            done = run();
            assert(done.ok, done.output);
            assert(!calls().some((call) => call.includes("delete")), JSON.stringify(calls()));
            // A removal without an inner volume in its intent (a clone, or Instance 2454's shape) reads and deletes no volume.
            Deno.writeTextFileSync(`${folder}/calls`, "");
            intent({ instance_id: 8, name: "instance-8", acl: "instance-8", firewall_prefix: "orbit:instance-8-" });
            done = run();
            assert(done.ok, done.output);
            assertEquals(Object.keys(state().volumes), ["inner-8"]);
            assert(!calls().some((call) => call.includes("volume") || call.join(" ").includes("/volumes/custom/")), JSON.stringify(calls()));
        } finally {
            Deno.removeSync(folder, { recursive: true });
        }
    },
});
Deno.test({
    name: "a proof copy's destroy releases its CI place only after the container is gone; any other removal, and a Node without the hold script, release nothing",
    ignore: !available,
    fn() {
        const folder = Deno.makeTempDirSync();
        const root = new URL("../ansible/", import.meta.url);
        const hold = `${folder}/libexec/orbit-ci-runner-hold`;
        try {
            for (const dir of ["bin", "run", "tasks", "libexec", "mirror.git"]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
            Deno.writeTextFileSync(`${folder}/instance-container-destroy.yml`, Deno.readTextFileSync(new URL("instance-container-destroy.yml", root))
                .replaceAll("/var/lib/orbit/instance-mail", `${folder}/mail-state`).replaceAll("/usr/local/libexec/orbit-ci-runner-hold", hold));
            Deno.copyFileSync(new URL("tasks/address-node.yml", root), `${folder}/tasks/address-node.yml`);
            Deno.copyFileSync(new URL("tasks/task-copy-base-names.yml", root), `${folder}/tasks/task-copy-base-names.yml`);
            installPowerHelper(folder, folder);
            Deno.writeTextFileSync(`${folder}/bin/incus`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-container-incus.ts", import.meta.url).pathname}' "$@"\n`);
            Deno.writeTextFileSync(`${folder}/bin/ufw`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-container-ufw.ts", import.meta.url).pathname}' "$@"\n`);
            // The hold script logs each call with whether the container was still in the fixture's state.
            Deno.writeTextFileSync(hold, `#!/bin/sh\nif grep -q '"instance-7"' '${folder}/state.json'; then present=yes; else present=no; fi\necho "$* $present" >> '${folder}/holds'\n`);
            for (const file of [`${folder}/bin/incus`, `${folder}/bin/ufw`, hold]) Deno.chmodSync(file, 0o755);
            Deno.writeTextFileSync(`${folder}/ufw.json`, "[]");
            const container = (copy: boolean) => {
                Deno.mkdirSync(`${folder}/containers/instance-7`, { recursive: true });
                Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({ containers: { "instance-7": { status: "Running" } }, "network-acls": [] }));
                Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({
                    ssh_execution: { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } },
                    container_destroy: { instance_id: 7, name: "instance-7", project: "orbit-instances-nested", pool: "orbit-instances", acl: "instance-7",
                        mirror: `${folder}/mirror.git`, firewall_prefix: "orbit:instance-7-", ...(copy ? { ci_hold: true } : {}) },
                }));
            };
            const run = () => {
                const done = new Deno.Command("ansible-playbook", {
                    args: ["-i", "localhost,", "--tags", "remove", "-e", "ansible_connection=local", "-e", "ansible_become=false",
                        "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", "-e", `task_base_root=${folder}/task-base`,
                        `${folder}/instance-container-destroy.yml`],
                    env: { ANSIBLE_CONFIG: stepEnv.ANSIBLE_CONFIG, HOST_FIXTURE: folder, ORBIT_RUN: `${folder}/run`, PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
                        ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1" },
                }).outputSync();
                return { ok: done.success, output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr) };
            };
            const holds = () => { try { return Deno.readTextFileSync(`${folder}/holds`).trim().split("\n"); } catch { return []; } };
            container(false);
            let done = run();
            assert(done.ok, done.output);
            assertEquals(holds(), []);
            container(true);
            done = run();
            assert(done.ok, done.output);
            // A retry, after a failure or by the watchdog, releases again; the script changes nothing then.
            done = run();
            assert(done.ok, done.output);
            assertEquals(holds(), ["release instance-7 no", "release instance-7 no"]);
            Deno.removeSync(hold);
            container(true);
            done = run();
            assert(done.ok, done.output);
            assertEquals(holds(), ["release instance-7 no", "release instance-7 no"]);
        } finally {
            Deno.removeSync(folder, { recursive: true });
        }
    },
});
Deno.test({
    name: "a destroy whose ZFS datasets went outside Incus gives back only this Instance's missing datasets so Incus deletes its records, deletes a dangling inner clone by name, and a removal with nothing left changes nothing",
    ignore: !available,
    fn() {
        const folder = Deno.makeTempDirSync();
        const root = new URL("../ansible/", import.meta.url);
        try {
            for (const dir of ["bin", "run", "tasks", "mirror.git", "containers/instance-7", "containers/instance-8"]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
            Deno.writeTextFileSync(`${folder}/instance-container-destroy.yml`,
                Deno.readTextFileSync(new URL("instance-container-destroy.yml", root)).replaceAll("/var/lib/orbit/instance-mail", `${folder}/mail-state`));
            Deno.copyFileSync(new URL("tasks/address-node.yml", root), `${folder}/tasks/address-node.yml`);
            Deno.copyFileSync(new URL("tasks/task-copy-base-names.yml", root), `${folder}/tasks/task-copy-base-names.yml`);
            installPowerHelper(folder, folder);
            for (const [name, fixture] of [["incus", "instance-container-incus.ts"], ["ufw", "instance-container-ufw.ts"], ["zfs", "instance-container-zfs.ts"]]) {
                Deno.writeTextFileSync(`${folder}/bin/${name}`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL(`fixtures/${fixture}`, import.meta.url).pathname}' "$@"\n`);
                Deno.chmodSync(`${folder}/bin/${name}`, 0o755);
            }
            Deno.writeTextFileSync(`${folder}/ufw.json`, "[]");
            const ds = "tank/orbit-instances";
            const inner = (name: string) => ({ pool: "orbit-instances", project: "orbit-instances-nested", snapshots: ["seed-1"], config: { "zfs.block_mode": "true", "volatile.uuid": name } });
            // Instance 3427's shape: the root dataset and its snapshot are gone, the Incus record stays; the inner volume is a clone of a snapshot
            // of a volume Incus deleted, whose dataset Incus keeps under deleted/ for the clone. Instance 8 and the origin must stay.
            // Look-alikes of instance-7's names stay too: another Instance's, an older one's, and the same name in another project.
            const others = [ds, `${ds}/containers`, `${ds}/custom`, `${ds}/deleted`, `${ds}/deleted/custom`,
                `${ds}/containers/orbit-instances-nested_instance-8`, `${ds}/custom/orbit-instances-nested_inner-8`,
                `${ds}/custom/orbit-instances-nested_inner-8@snapshot-seed-1`, `${ds}/deleted/custom/2454-origin`, `${ds}/deleted/custom/2454-origin@snapshot-copy`,
                `${ds}/containers/orbit-instances-nested_instance-70`, `${ds}/containers/orbit-instances-nested_instance-7-old`,
                `${ds}/containers/orbit-instances_instance-7`, `${ds}/containers/orbit-instances_instance-7@snapshot-seed`];
            Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({
                pool: { name: "orbit-instances", driver: "zfs", config: { "zfs.pool_name": ds, source: ds } },
                datasets: [...others, `${ds}/custom/orbit-instances-nested_inner-7`, `${ds}/custom/orbit-instances-nested_inner-7@snapshot-seed-1`],
                containers: {
                    // instance-7 still holds its inner volume, so Incus refuses the volume until the container goes.
                    "instance-7": { status: "Stopped", snapshots: ["seed"], added: { "inner-pool": { type: "disk", settings: ["pool=orbit-instances", "source=inner-7", "path=/srv/inner-pool"] } } },
                    "instance-8": { status: "Running", added: { "inner-pool": { type: "disk", settings: ["pool=orbit-instances", "source=inner-8", "path=/srv/inner-pool"] } } },
                },
                volumes: { "inner-7": inner("inner-7"), "inner-8": inner("inner-8") },
                "network-acls": [{ name: "instance-7", egress: [] }],
            }));
            Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({
                ssh_execution: { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } },
                container_destroy: { instance_id: 7, name: "instance-7", project: "orbit-instances-nested", pool: "orbit-instances", acl: "instance-7",
                    mirror: `${folder}/mirror.git`, firewall_prefix: "orbit:instance-7-", inner_volume: "inner-7" },
            }));
            const run = () => {
                const done = new Deno.Command("ansible-playbook", {
                    args: ["-i", "localhost,", "--tags", "remove", "-e", "ansible_connection=local", "-e", "ansible_become=false",
                        "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", "-e", `task_base_root=${folder}/task-base`,
                        `${folder}/instance-container-destroy.yml`],
                    env: { ANSIBLE_CONFIG: stepEnv.ANSIBLE_CONFIG, HOST_FIXTURE: folder, ORBIT_RUN: `${folder}/run`, PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
                        ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1" },
                }).outputSync();
                return { ok: done.success, output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr) };
            };
            const read = (name: string) => { try { return Deno.readTextFileSync(`${folder}/${name}`).trim().split("\n").filter(Boolean).map((line) => (JSON.parse(line) as string[]).join(" ")); } catch { return []; } };
            const state = () => JSON.parse(Deno.readTextFileSync(`${folder}/state.json`));
            let done = run();
            assert(done.ok, done.output);
            assertEquals(Object.keys(state().containers), ["instance-8"]);
            assertEquals(Object.keys(state().volumes), ["inner-8"]);
            assertEquals(state()["network-acls"], []);
            // Only the two missing names of instance-7 were made, from its recorded pool, project and name; then Incus deleted them with
            // its record. Every other dataset stayed.
            assertEquals(read("zfs-calls").filter((call) => !call.startsWith("list")), [
                `create -o mountpoint=legacy -o canmount=noauto ${ds}/containers/orbit-instances-nested_instance-7`,
                `snapshot ${ds}/containers/orbit-instances-nested_instance-7@snapshot-seed`,
            ]);
            assertEquals(state().datasets, others);
            // The clone went by name: its snapshot, then the volume, after the container; Orbit ran no zfs destroy.
            const order = read("calls");
            // Incus refused both at first; the second deletions run after the datasets are back.
            const container = order.lastIndexOf("--project orbit-instances-nested delete --force instance-7");
            const snapshot = order.indexOf("--project orbit-instances-nested storage volume snapshot delete orbit-instances inner-7 seed-1");
            const volume = order.lastIndexOf("--project orbit-instances-nested storage volume delete orbit-instances inner-7");
            assert(container >= 0 && container < snapshot && snapshot < volume, JSON.stringify(order));
            assert(!read("zfs-calls").some((call) => call.includes("destroy")));
            // Everything is gone now: a retry succeeds, deletes and makes nothing, and changes nothing.
            for (const name of ["calls", "zfs-calls"]) Deno.writeTextFileSync(`${folder}/${name}`, "");
            done = run();
            assert(done.ok, done.output);
            assert(/node\s*:.*changed=0/.test(done.output), done.output);
            assertEquals(read("zfs-calls"), []);
            assert(!read("calls").some((call) => call.includes("delete")), JSON.stringify(read("calls")));
            assertEquals(state().datasets, others);
            // A step that fails writes its task, message and the end of its stderr into the phase result, which the Gateway records.
            const now = state();
            now.volumes["inner-9"] = { ...inner("inner-9"), snapshots: [] };
            now.datasets.push(`${ds}/custom/orbit-instances-nested_inner-9`);
            now.containers["instance-8"].added.held = { type: "disk", settings: ["pool=orbit-instances", "source=inner-9", "path=/srv/held"] };
            Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify(now));
            const intent = JSON.parse(Deno.readTextFileSync(`${folder}/run/intent.json`));
            intent.container_destroy = { ...intent.container_destroy, instance_id: 9, name: "instance-9", acl: "instance-9", firewall_prefix: "orbit:instance-9-", inner_volume: "inner-9" };
            Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify(intent));
            done = run();
            assert(!done.ok, done.output);
            assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/run/remove.json`)), {
                error_code: "instance.removal_incomplete",
                detail: "Delete the inner volume again: non-zero return code: Error: Storage volume is still in use",
            });
            // A missing name that another writer takes, with data, after the read: the removal refuses, makes nothing and deletes nothing.
            const taken = `${ds}/containers/orbit-instances-nested_instance-11`;
            const again = state();
            delete again.containers["instance-8"].added.held;
            again.containers["instance-11"] = { status: "Stopped", snapshots: [], added: {} };
            again.appears = { [taken]: [`${taken}/data`] };
            Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify(again));
            Deno.mkdirSync(`${folder}/containers/instance-11`, { recursive: true });
            intent.container_destroy = { ...intent.container_destroy, instance_id: 11, name: "instance-11", acl: "instance-11", firewall_prefix: "orbit:instance-11-" };
            delete intent.container_destroy.inner_volume;
            Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify(intent));
            for (const name of ["calls", "zfs-calls"]) Deno.writeTextFileSync(`${folder}/${name}`, "");
            done = run();
            assert(!done.ok, done.output);
            assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/run/remove.json`)).error_code, "instance.removal_dataset_taken");
            assert(!read("zfs-calls").some((call) => call.startsWith("create") || call.startsWith("snapshot")), JSON.stringify(read("zfs-calls")));
            // Only Incus's own first deletion ran, which Incus refused for the missing dataset.
            assertEquals(read("calls").filter((call) => call.includes("delete")), ["--project orbit-instances-nested delete --force instance-11"]);
            assert(state().containers["instance-11"]);
            assertEquals(state().datasets.filter((name: string) => name.startsWith(taken)), [taken, `${taken}/data`]);
        } finally {
            Deno.removeSync(folder, { recursive: true });
        }
    },
});
Deno.test({
    name: "the stop phase stops a running container before its databases drop; a stopped or missing one counts as stopped and nothing changes",
    ignore: !available,
    fn() {
        const folder = Deno.makeTempDirSync();
        const root = new URL("../ansible/", import.meta.url);
        try {
            for (const dir of ["bin", "run", "tasks", "containers/instance-7", "containers/instance-8"]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
            Deno.copyFileSync(new URL("instance-container-destroy.yml", root), `${folder}/instance-container-destroy.yml`);
            for (const task of ["address-node.yml", "task-copy-base-names.yml"]) Deno.copyFileSync(new URL(`tasks/${task}`, root), `${folder}/tasks/${task}`);
            Deno.writeTextFileSync(`${folder}/bin/incus`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-container-incus.ts", import.meta.url).pathname}' "$@"\n`);
            Deno.chmodSync(`${folder}/bin/incus`, 0o755);
            const container = (name: string, status?: string) => {
                Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({
                    containers: { ...(status ? { [name]: { status } } : {}), "instance-8": { status: "Running" } }, "network-acls": [] }));
                Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({
                    ssh_execution: { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } },
                    container_destroy: { instance_id: 7, name, project: "orbit-instances", pool: "orbit-instances", acl: name, mirror: `${folder}/mirror.git`, firewall_prefix: `orbit:${name}-` },
                }));
                Deno.writeTextFileSync(`${folder}/calls`, "");
            };
            const run = () => {
                const done = new Deno.Command("ansible-playbook", {
                    args: ["-i", "localhost,", "--tags", "stop", "-e", "ansible_connection=local", "-e", "ansible_become=false",
                        "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", `${folder}/instance-container-destroy.yml`],
                    env: { ANSIBLE_CONFIG: stepEnv.ANSIBLE_CONFIG, HOST_FIXTURE: folder, ORBIT_RUN: `${folder}/run`, PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
                        ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1" },
                }).outputSync();
                return { ok: done.success, output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr) };
            };
            const changes = () => Deno.readTextFileSync(`${folder}/calls`).trim().split("\n").filter(Boolean)
                .map((line) => (JSON.parse(line) as string[]).join(" ")).filter((call) => !call.startsWith("query") && !call.includes(" list "));
            container("instance-7", "Running");
            let done = run();
            assert(done.ok, done.output);
            // Only the stop: the deletion, its volume, ACL, rules and files wait for the remove phase after the databases.
            assertEquals(changes(), ["--project orbit-instances stop --force instance-7"]);
            const state = JSON.parse(Deno.readTextFileSync(`${folder}/state.json`));
            assertEquals([state.containers["instance-7"].status, state.containers["instance-8"].status], ["Stopped", "Running"]);
            assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/run/stop.json`)), { container_present: true, removed: false });
            for (const status of ["Stopped", undefined]) {
                container("instance-7", status);
                done = run();
                assert(done.ok, done.output);
                assertEquals(changes(), [], String(status));
                assert(/node\s*:.*changed=0/.test(done.output), done.output);
            }
            // A failed stop keeps the end of its stderr in whole lines: the cut drops the partial line, which could be half a secret.
            container("instance-7", "Running");
            const lines = Array.from({ length: 120 }, (_, i) => `line ${i} https://deploy:secret-${i}@git.example.test/x.git`);
            const failing = JSON.parse(Deno.readTextFileSync(`${folder}/state.json`));
            Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({ ...failing, stop_stderr: lines.join("\n") }));
            done = run();
            assert(!done.ok, done.output);
            const refusal = JSON.parse(Deno.readTextFileSync(`${folder}/run/remove.json`));
            const head = "Stop the container: non-zero return code: ";
            assertEquals(refusal.error_code, "instance.removal_incomplete");
            assert(refusal.detail.startsWith(head) && refusal.detail.length <= 1800, refusal.detail);
            const kept = refusal.detail.slice(head.length).split("\n");
            assertEquals(kept, lines.slice(lines.length - kept.length));
            assert(kept.length > 1 && kept.length < lines.length, String(kept.length));
        } finally {
            Deno.removeSync(folder, { recursive: true });
        }
    },
});
Deno.test({
    name: "a signed removal reports the discard receipt of its stop phase in remove.json; a refused discard reports it and fails; a lost relay reports none",
    ignore: !available,
    fn() {
        const folder = Deno.makeTempDirSync();
        const root = new URL("../ansible/", import.meta.url);
        const receipt = { status: "done", operation: "41", action: "discard", target: "7", delivery: "swamp" };
        try {
            for (const dir of ["bin", "run", "tasks", "containers/instance-7", "mail-state", "hibernation"]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
            Deno.writeTextFileSync(`${folder}/instance-container-destroy.yml`, Deno.readTextFileSync(new URL("instance-container-destroy.yml", root))
                .replaceAll("/var/lib/orbit/instance-mail", `${folder}/mail-state`));
            for (const task of ["address-node.yml", "task-copy-base-names.yml"]) Deno.copyFileSync(new URL(`tasks/${task}`, root), `${folder}/tasks/${task}`);
            installPowerHelper(folder, folder);
            // The relay's own outcomes have their tests (ansible_executor_power_relay_test.ts). Here it answers with the fixture's line:
            // a receipt it then respects, or no line at all, as a lost transport.
            Deno.writeTextFileSync(`${folder}/tasks/executor-power-relay.yml`, `---
- name: Answer as the relay
  ansible.builtin.set_fact:
    executor_receipt: "{{ lookup('ansible.builtin.file', '${folder}/relay') | from_json }}"
  when: lookup('ansible.builtin.file', '${folder}/relay') != ''
- name: Require a terminal executor line
  ansible.builtin.assert: { that: executor_receipt is defined, fail_msg: executor.relay_transport, quiet: true }
- name: Respect the executor's terminal outcome
  when: executor_receipt.status != 'done'
  ansible.builtin.fail: { msg: executor.order_refused }
`);
            for (const [name, fixture] of [["incus", "instance-container-incus.ts"], ["ufw", "instance-container-ufw.ts"]]) {
                Deno.writeTextFileSync(`${folder}/bin/${name}`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL(`fixtures/${fixture}`, import.meta.url).pathname}' "$@"\n`);
                Deno.chmodSync(`${folder}/bin/${name}`, 0o755);
            }
            const prepare = (relay: unknown) => {
                Deno.removeSync(`${folder}/run`, { recursive: true });
                Deno.mkdirSync(`${folder}/run`);
                Deno.writeTextFileSync(`${folder}/relay`, relay === null ? "" : JSON.stringify(relay));
                Deno.writeTextFileSync(`${folder}/ufw.json`, "[]");
                Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({ containers: { "instance-7": { status: "Running" } }, "network-acls": [] }));
                Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({
                    ssh_execution: { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } },
                    container_destroy: { instance_id: 7, name: "instance-7", project: "orbit-instances", pool: "orbit-instances", acl: "instance-7",
                        mirror: `${folder}/mirror.git`, firewall_prefix: "orbit:instance-7-", signed_power: true },
                }));
            };
            const run = (tag: string) => {
                const done = new Deno.Command("ansible-playbook", {
                    args: ["-i", "localhost,", "--tags", tag, "-e", "ansible_connection=local", "-e", "ansible_become=false",
                        "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", "-e", `task_base_root=${folder}/task-base`,
                        `${folder}/instance-container-destroy.yml`],
                    env: { ANSIBLE_CONFIG: stepEnv.ANSIBLE_CONFIG, HOST_FIXTURE: folder, ORBIT_RUN: `${folder}/run`, PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
                        ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1" },
                }).outputSync();
                return { ok: done.success, output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr) };
            };
            const file = (name: string) => JSON.parse(Deno.readTextFileSync(`${folder}/run/${name}`));
            // The stop phase relays the discard and keeps its receipt; the remove phase carries it into the file the Gateway reads.
            prepare(receipt);
            let done = run("stop");
            assert(done.ok, done.output);
            assertEquals(file("stop.json"), { container_present: true, removed: false, executor: receipt });
            done = run("remove");
            assert(done.ok, done.output);
            assertEquals(file("remove.json"), { container_present: true, removed: true, executor: receipt });
            // A refused discard fails the stop; its refusal and its receipt are in remove.json.
            const refused = { status: "refused", reason: "expired", delivery: "swamp" };
            prepare(refused);
            done = run("stop");
            assert(!done.ok, done.output);
            assertEquals([file("remove.json").error_code, file("remove.json").executor], ["executor.order_refused", refused]);
            // A lost relay has no receipt to report; the Gateway keeps the Operation's fence (ADR 0266, R2).
            prepare(null);
            done = run("stop");
            assert(!done.ok, done.output);
            assertEquals(file("remove.json").error_code, "executor.relay_transport");
            assert(!("executor" in file("remove.json")));
        } finally {
            Deno.removeSync(folder, { recursive: true });
        }
    },
});
