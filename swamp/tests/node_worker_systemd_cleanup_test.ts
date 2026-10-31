import { assertEquals } from "jsr:@std/assert@1.0.14";
import { fileURLToPath } from "node:url";
import { workerUnit } from "../extensions/behaviors/node-worker/config.ts";

const userManagerAvailable = Deno.build.os === "linux" &&
    Boolean(Deno.env.get("DBUS_SESSION_BUS_ADDRESS")) &&
    await new Deno.Command("/usr/bin/systemctl", {
        args: ["--user", "is-active", "default.target"],
        stdout: "null",
        stderr: "null",
    }).output().then((result) => result.success).catch(() => false);

const servicePolicy = () =>
    new Map(
        workerUnit({ node_id: 209, user: "orbit" }).split("\n")
            .filter((line) => line.includes("="))
            .map((line) => {
                const separator = line.indexOf("=");
                return [line.slice(0, separator), line.slice(separator + 1)];
            }),
    );

Deno.test("worker drain retains final child cleanup after its main process exits", () => {
    const policy = servicePolicy();
    assertEquals(policy.get("KillMode"), "mixed");
    assertEquals(policy.get("TimeoutStopSec"), "infinity");
    assertEquals(policy.get("SendSIGKILL") ?? "yes", "yes");
});

Deno.test({
    name:
        "systemd restarts the rendered worker policy despite a lingering connection child",
    ignore: !userManagerAvailable,
    fn: async () => {
        const root = await Deno.makeTempDir({ prefix: "worker-cleanup-" });
        const unit = `orbit-worker-cleanup-test-${crypto.randomUUID()}.service`;
        const command = async (binary: string, args: string[]) => {
            const output = await new Deno.Command("/usr/bin/timeout", {
                args: ["5", binary, ...args],
                stdout: "piped",
                stderr: "piped",
            }).output();
            return {
                ...output,
                text: new TextDecoder().decode(output.stdout) +
                    new TextDecoder().decode(output.stderr),
            };
        };
        const properties = [...servicePolicy()].filter(([name]) =>
            ["KillMode", "SendSIGKILL", "TimeoutStopSec"].includes(name)
        ).map(([name, value]) => `--property=${name}=${value}`);
        try {
            const start = await command("/usr/bin/systemd-run", [
                "--user",
                "--quiet",
                `--unit=${unit}`,
                "--property=Type=exec",
                "--property=Restart=on-failure",
                "--property=RestartSec=100ms",
                ...properties,
                "/usr/bin/python3",
                fileURLToPath(
                    new URL(
                        "fixtures/node-worker/exit_with_child.py",
                        import.meta.url,
                    ),
                ),
                `${root}/phase`,
                `${root}/ready`,
            ]);
            assertEquals(start.success, true, start.text);
            let restarted = false;
            const deadline = performance.now() + 2000;
            while (performance.now() < deadline) {
                try {
                    restarted = await Deno.readTextFile(`${root}/ready`) ===
                        "restarted";
                } catch (error) {
                    if (!(error instanceof Deno.errors.NotFound)) throw error;
                }
                if (restarted) break;
                await new Promise((resolve) => setTimeout(resolve, 25));
            }
            const state = await command("/usr/bin/systemctl", [
                "--user",
                "show",
                unit,
                "-p",
                "ActiveState",
                "-p",
                "SubState",
                "-p",
                "Result",
            ]);
            assertEquals(restarted, true, state.text);
        } finally {
            await command("/usr/bin/systemctl", [
                "--user",
                "stop",
                "--no-block",
                unit,
            ]);
            await command("/usr/bin/systemctl", [
                "--user",
                "kill",
                "--kill-whom=all",
                "--signal=SIGKILL",
                unit,
            ]);
            await command("/usr/bin/systemctl", ["--user", "stop", unit]);
            await command("/usr/bin/systemctl", [
                "--user",
                "reset-failed",
                unit,
            ]);
            await Deno.remove(root, { recursive: true });
        }
    },
});
