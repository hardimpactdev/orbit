/** Fake the privilege boundary while exercising real files and pipes in a private test tree. */
import {
    type SystemCommand,
    systemCommand,
} from "../processes-schedules/system.ts";

export const rootFixtureCommand: SystemCommand = async (
    program,
    args,
    stdin,
    signal,
    timeout,
) => {
    if (program === "/usr/bin/sudo") {
        if (args[0] !== "-n" || args[1] !== "--") {
            throw new Error("Unexpected fixture sudo arguments");
        }
        program = args[2];
        args = args.slice(3);
    }
    const actual = [...args];
    if (program === "/usr/bin/chown") {
        const owner = actual.indexOf("0:0");
        if (owner !== -1) actual[owner] = `${Deno.uid()}:${Deno.gid()}`;
    }
    if (program === "/usr/bin/install") {
        for (
            const [flag, value] of [["--owner", Deno.uid()], [
                "--group",
                Deno.gid(),
            ]] as const
        ) {
            const index = actual.indexOf(flag);
            if (index !== -1 && actual[index + 1] === "0") {
                actual[index + 1] = String(value);
            }
        }
    }
    const result = await systemCommand(program, actual, stdin, signal, timeout);
    if (program !== "/usr/bin/stat" || result.code !== 0) return result;
    const format =
        actual.find((arg) => arg.startsWith("--format="))?.slice(9) ??
            actual[actual.indexOf("--printf") + 1];
    const fields = result.stdout.trimEnd().split(":");
    for (const [index, field] of format.split(":").entries()) {
        if (field === "%u" || field === "%g") fields[index] = "0";
    }
    return { ...result, stdout: fields.join(":") };
};
