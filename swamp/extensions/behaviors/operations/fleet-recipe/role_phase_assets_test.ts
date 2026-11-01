import { assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior } from "./role_phase_assets.ts";

Deno.test("role phase bundle ships importable canonical sources once without private material", async () => {
    const context = {
        extensionFile: (name: string) =>
            new URL("./" + name, import.meta.url).pathname,
        writeResource: () => Promise.resolve(),
    };
    const result = await behavior.methods.bundle.execute({
        intent: {
            schema_version: 1,
            node_id: 5,
            assignment_id: 7,
            created: false,
            role: "database",
            prerequisites: { packages: ["docker.io"], releases: ["fixture"] },
        },
        prepared_tools: false,
        sources: {
            account: 'print("account-source")',
            observer_install: 'print("observer-source")',
            packages: 'print("packages-source")',
        },
    }, context);
    try {
        const input = JSON.parse(result.input_json);
        assertEquals(input.intent.node_id, 5);
        assertEquals(input.prepared_tools, false);
        assertEquals("execution_secrets" in input, false);
        const canonical = await Deno.readTextFile(
            context.extensionFile("scripts/role_prerequisites.py"),
        );
        assertEquals(
            await Deno.readTextFile(
                result.scripts_directory + "/canonical_role_prerequisites.py",
            ),
            canonical,
        );
        const python = await new Deno.Command("python3", {
            args: [
                "-B",
                "-c",
                "import pathlib,runpy,sys;root=pathlib.Path(sys.argv[1]);sys.path.insert(0,str(root));[runpy.run_path(str(p),run_name='supplier_import') for p in [*root.glob('phase_*.py'),*root.glob('canonical_*.py')]];print('imported')",
                result.scripts_directory,
            ],
            stdout: "piped",
            stderr: "piped",
        }).output();
        assertEquals(new TextDecoder().decode(python.stderr), "");
        assertEquals(python.code, 0);
        assertEquals(
            new TextDecoder().decode(python.stdout).trim(),
            "imported",
        );
    } finally {
        await Deno.remove(result.scripts_directory, { recursive: true });
    }
});
