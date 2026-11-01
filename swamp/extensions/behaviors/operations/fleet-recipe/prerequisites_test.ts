import { assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior } from "./assets.ts";

Deno.test("shipped prerequisite action binds public input and exits nonzero on refusal", async () => {
    const requested: string[] = [];
    const output = await behavior.methods.read.execute({
        action: "role-prerequisites",
        automatic: false,
        daytime: false,
        input: {
            name: "role.install-prerequisites",
            input: {
                releases: ["unsupported-fixture"],
                packages: [],
                user: "orbit",
                group: "orbit",
                home: "/home/orbit",
                composer_home: false,
                apps_directory: false,
                install_packages: false,
            },
        },
    }, {
        extensionFile(path) {
            requested.push(path);
            return new URL(path, import.meta.url).pathname;
        },
        writeResource: () => Promise.resolve(),
    });
    assertEquals(requested, ["scripts/role_prerequisites.py"]);
    const process = new Deno.Command("python3", {
        args: ["-I", "-c", output.script_body],
        stdin: "null",
        stdout: "piped",
        stderr: "piped",
    });
    const result = await process.output();
    assertEquals(result.code, 1);
    assertEquals(JSON.parse(new TextDecoder().decode(result.stdout)), {
        error_code: "role.os_unsupported",
    });
});
