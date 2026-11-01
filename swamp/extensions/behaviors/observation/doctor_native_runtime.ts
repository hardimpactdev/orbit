import type { Row } from "./doctor_contract.ts";
/** Retained only for the existing firewall.inspect consumer; Doctor uses native command facts. */
import snapshotProgram from "../operations/routes/mutation-command/scripts/firewall_snapshot.py" with {
    type: "text",
};
export const runtimeReadProgram = snapshotProgram;
export async function readRuntime(
    name: string,
    input: Row,
    local: (argv: string[]) => Promise<string>,
): Promise<Row | undefined> {
    if (name !== "firewall.inspect") return undefined;
    const value = JSON.parse(
        await local([
            "/usr/bin/sudo",
            "-n",
            "--",
            "/usr/bin/python3",
            "-c",
            runtimeReadProgram,
            JSON.stringify({ name, input }),
        ]),
    );
    if (value.error_code) throw value;
    return value;
}
