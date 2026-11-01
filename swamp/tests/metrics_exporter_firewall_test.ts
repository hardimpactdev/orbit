/** Exercise the official model against existing Orbit rules without host access. */
import { model } from "../.swamp/pulled-extensions/@swamp/cfgmgmt/models/firewall.ts";
import { assertEquals } from "jsr:@std/assert@1";

Deno.test("exporter firewall adds/removes its port rule without changing existing member or service rules", async () => {
    const originalCommand = Deno.Command;
    const legacy = [
        "10.44.0.9 9114/tcp on orbit ALLOW IN 10.44.0.3 # orbit:metrics-service-fpm-allow",
        "10.44.0.9 9114/tcp on orbit DENY IN Anywhere # orbit:metrics-service-fpm-deny",
    ];
    const member =
        "10.44.0.9 on orbit ALLOW IN 10.44.0.0/24 # orbit:wireguard-members";
    const portRule = "9114/tcp ALLOW IN 10.44.0.3";
    let rows: string[] = [];
    let changes: string[] = [];
    class HostCommand {
        constructor(
            private command: string,
            private options: Deno.CommandOptions,
        ) {}
        output() {
            assertEquals(this.command, "ssh");
            const args = this.options.args ?? [];
            let stdout = "";
            if (!args.includes("-O")) {
                const remote = String(args.at(-1));
                if (remote.startsWith("ufw status")) {
                    stdout = "Status: active\n" + rows.join("\n");
                } else if (
                    remote ===
                        "ufw allow from '10.44.0.3' to any port 9114 proto tcp"
                ) {
                    changes.push(remote);
                    rows.push(portRule);
                } else if (
                    remote ===
                        "ufw delete allow from '10.44.0.3' to any port 9114 proto tcp"
                ) {
                    changes.push(remote);
                    rows = rows.filter((row) => row !== portRule);
                } else {
                    throw new Error("Unexpected host action: " + remote);
                }
            }
            return Promise.resolve({
                code: 0,
                success: true,
                signal: null,
                stdout: new TextEncoder().encode(stdout),
                stderr: new Uint8Array(),
            });
        }
    }
    Deno.Command = HostCommand as unknown as typeof Deno.Command;
    try {
        for (const existing of [[member], [...legacy, member]]) {
            rows = [...existing];
            changes = [];
            const apply = async (ensure: "present" | "absent") => {
                let state: Record<string, unknown> = {};
                await model.methods.apply.execute({}, {
                    globalArgs: model.globalArguments.parse({
                        nodeHost: "192.0.2.9",
                        port: 9114,
                        source: "10.44.0.3",
                        action: "allow",
                        ensure,
                    }),
                    logger: { info() {}, error() {} },
                    writeResource: (
                        _name: string,
                        _key: string,
                        value: Record<string, unknown>,
                    ) => {
                        state = value;
                        return Promise.resolve({});
                    },
                } as never);
                return state;
            };
            assertEquals((await apply("present")).status, "applied");
            assertEquals(rows, [...existing, portRule]);
            assertEquals((await apply("present")).status, "compliant");
            assertEquals(changes.length, 1);
            assertEquals((await apply("absent")).status, "applied");
            assertEquals(rows, existing);
            assertEquals((await apply("absent")).status, "compliant");
            assertEquals(changes.length, 2);
        }
    } finally {
        Deno.Command = originalCommand;
    }
});
