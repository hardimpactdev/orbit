/** Exercise production container deletion, including a migrated checkout with retained Git, against the Incus fixture. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";
// The "Delete the container" step of a normal destroy, its guard and its env evaluated from the shipped workflow.
type Step = { name: string; guard: string; task: { inputs: { env: Record<string, string> } } };
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
const available =
    new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
        .outputSync().success;
Deno.test({
    name:
        "migrated container cleanup keeps retained Git while removing its container, volume, ACL, own rules (while another removal shifts rule numbers) and mirror; a retry changes nothing",
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
            for (const name of ["instance-7", "instance-8"]) {
                Deno.mkdirSync(`${folder}/mail-state/${name}`, {recursive: true});
                Deno.writeTextFileSync(`${folder}/mail-state/${name}/activation.json`, "[]");
            }
            Deno.copyFileSync(
                new URL("tasks/address-node.yml", root),
                `${folder}/tasks/address-node.yml`,
            );
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
                        name: "instance-7",
                        project: "orbit-instances",
                        pool: "orbit-instances",
                        acl: "instance-7",
                        mirror: `${folder}/mirror.git`,
                        firewall_prefix: "orbit:instance-7-",
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
