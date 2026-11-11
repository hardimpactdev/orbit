/** Exercise production container deletion, including a migrated checkout with retained Git, against the Incus fixture. */
import { assert, assertEquals } from "jsr:@std/assert@1";
const available =
    new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
        .outputSync().success;
Deno.test({
    name:
        "migrated container cleanup keeps retained Git while removing its container, volume, ACL, rules and mirror; a retry changes nothing",
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
            Deno.copyFileSync(
                new URL("instance-container-destroy.yml", root),
                `${folder}/instance-container-destroy.yml`,
            );
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
                `#!/bin/sh\nif [ "$1" = status ]; then cat '${folder}/ufw'; else sed -i '/orbit:instance-7-/d' '${folder}/ufw'; fi\n`,
            );
            for (const name of ["incus", "ufw"]) {
                Deno.chmodSync(`${folder}/bin/${name}`, 0o755);
            }
            Deno.writeTextFileSync(
                `${folder}/ufw`,
                "[ 1] 3800 ALLOW IN # orbit:instance-7-t3\n[ 2] 3801 ALLOW IN # orbit:instance-8-t3\n",
            );
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
                    "network-acls": [{ name: "instance-7" }, {
                        name: "instance-8",
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
            assertEquals(state["network-acls"], [{ name: "instance-8" }]);
            assertEquals(
                Deno.readTextFileSync(`${folder}/ufw`),
                "[ 2] 3801 ALLOW IN # orbit:instance-8-t3\n",
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
