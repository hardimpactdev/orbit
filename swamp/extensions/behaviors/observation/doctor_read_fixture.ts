import type { Row } from "./doctor_contract.ts";

/** Run the one shipped log reader against local, synthetic log files. */
export async function collectLogs(input: Row): Promise<Row> {
    const script = (await Deno.readTextFile(
        new URL("./doctor-read/scripts/logs.py", import.meta.url),
    ))
        .replace(
            '"__DOCTOR_LOG_INPUT__"',
            JSON.stringify(JSON.stringify(input)),
        );
    const output = await new Deno.Command("python3", {
        args: ["-c", script],
        stdout: "piped",
        stderr: "piped",
    }).output();
    if (!output.success) {
        throw new Error(new TextDecoder().decode(output.stderr));
    }
    return JSON.parse(new TextDecoder().decode(output.stdout));
}
