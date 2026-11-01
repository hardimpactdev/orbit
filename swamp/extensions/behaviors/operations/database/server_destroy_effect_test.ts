import { assertEquals } from "jsr:@std/assert@1.0.19";

Deno.test({
    name:
        "disposable server destroy removes owned containers, preserves data and replays",
    fn: async () => {
        const suffix = crypto.randomUUID().replaceAll("-", "").slice(0, 12);
        const container = `orbit-process-999997-server-destroy-${suffix}`;
        const volume = `orbit-server-destroy-${suffix}-data`;
        const docker = async (args: string[], success = true) => {
            const reply = await new Deno.Command("docker", {
                args,
                stdout: "null",
                stderr: "null",
            }).output();
            assertEquals(
                reply.success,
                success,
                "Disposable server fixture outcome",
            );
        };
        try {
            await docker([
                "run",
                "-d",
                "--name",
                container,
                "--label",
                "orbit.managed=true",
                "--label",
                "orbit.container.kind=process",
                "--label",
                "orbit.process.id=999997",
                "--label",
                "orbit.process.spec=" + "a".repeat(64),
                "--mount",
                `type=volume,src=${volume},dst=/data`,
                "valkey/valkey:8.1",
                "valkey-server",
                "--dir",
                "/data",
            ]);
            await docker([
                "exec",
                container,
                "touch",
                "/data/volume-preserved",
            ]);
            // Execute the checked-in action files directly; native ordering has its own test.
            const script =
                new URL("../process-commands/scripts/", import.meta.url)
                    .pathname;
            const slots = [
                "canonical",
                "candidate",
                "rollback-running",
                "rollback-stopped",
                "failed",
            ];
            const invoke = async (file: string, slot: string) => {
                const reply = await new Deno.Command("python3", {
                    args: ["-B", script + file],
                    env: {
                        ORBIT_PROCESS_CONTROL: JSON.stringify({
                            process_id: 999997,
                            runtime: "docker",
                            name: container,
                            vite: null,
                            on_demand: false,
                            slot,
                        }),
                        ORBIT_PROCESS_OPERATION: "stop",
                    },
                    stdout: "piped",
                    stderr: "piped",
                }).output();
                return {
                    success: reply.success,
                    value: JSON.parse(new TextDecoder().decode(reply.stdout)),
                };
            };
            const destroy = async () => {
                const observed = await Promise.all(
                    slots.map(async (slot) => ({
                        slot,
                        ...await invoke("inspect.py", slot),
                    })),
                );
                const refusal = observed.find((v) => !v.success);
                if (refusal) return refusal.value;
                for (const entry of observed.filter((v) => v.value.present)) {
                    if (
                        !["created", "exited", "dead"].includes(
                            entry.value.state,
                        )
                    ) {
                        const stopped = await invoke("control.py", entry.slot);
                        if (!stopped.success) return stopped.value;
                    }
                    const removed = await invoke(
                        "remove_container.py",
                        entry.slot,
                    );
                    if (!removed.success) return removed.value;
                }
                return { backend: "absent" };
            };
            assertEquals(await destroy(), { backend: "absent" });
            await docker(["container", "inspect", container], false);
            await docker(["volume", "inspect", volume]);
            await docker([
                "run",
                "--rm",
                "--mount",
                `type=volume,src=${volume},dst=/data,readonly`,
                "valkey/valkey:8.1",
                "test",
                "-f",
                "/data/volume-preserved",
            ]);
            assertEquals(await destroy(), { backend: "absent" });
            await docker([
                "run",
                "-d",
                "--name",
                container,
                "--label",
                "orbit.managed=true",
                "--label",
                "orbit.container.kind=process",
                "--label",
                "orbit.process.id=999996",
                "--label",
                "orbit.process.spec=" + "a".repeat(64),
                "valkey/valkey:8.1",
            ]);
            assertEquals(await destroy(), {
                error_code: "process.name_collision",
            });
            await docker(["container", "inspect", container]);
        } finally {
            for (
                const name of [
                    container,
                    `${container}-candidate`,
                    `${container}-rollback-running`,
                    `${container}-rollback-stopped`,
                    `${container}-failed`,
                ]
            ) {
                await new Deno.Command("docker", {
                    args: ["rm", "--force", "--volumes", name],
                    stdout: "null",
                    stderr: "null",
                }).output();
            }
            await docker(["volume", "rm", volume]);
        }
    },
});
