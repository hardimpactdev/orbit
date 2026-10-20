import { fileURLToPath } from "node:url";
import { exitCode, pathsFrom } from "./web-verify/contract";
import { runDaemon } from "./web-verify/daemon";
import { execute } from "./web-verify/command";

const paths = pathsFrom(fileURLToPath(import.meta.url));

if (process.env.ORBIT_WEB_VERIFY_DAEMON === "1") {
    await runDaemon(paths);
} else {
    const result = await execute(paths, process.argv.slice(2));
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`, () => {
        process.exit(exitCode(result));
    });
}
