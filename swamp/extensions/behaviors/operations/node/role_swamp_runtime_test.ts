import { assertEquals } from "jsr:@std/assert@1.0.19";

const SWAMP_WORKERS_PROGRAM = await Deno.readTextFile(
    new URL("../fleet-recipe/scripts/swamp_workers.py", import.meta.url),
);
Deno.test("Gateway renders the manifest and serve grants without widening native output access", async () => {
    const repo = await Deno.makeTempDir();
    try {
        await Deno.mkdir(`${repo}/config`);
        const manifest = new URL(
            "../../../../config/gateway-workflow-grants.json",
            import.meta.url,
        );
        await Deno.copyFile(
            manifest,
            `${repo}/config/gateway-workflow-grants.json`,
        );
        const serve = new URL(
            "../../../../config/serve-grants.json",
            import.meta.url,
        );
        await Deno.copyFile(serve, `${repo}/config/serve-grants.json`);
        const result = await new Deno.Command("php", {
            args: [
                new URL("../../../../../apps/gateway/bin/render-swamp-grants", import.meta.url).pathname,
                repo,
            ],
        }).output();
        assertEquals(
            result.success,
            true,
            new TextDecoder().decode(result.stderr),
        );
        const rendered = JSON.parse(new TextDecoder().decode(result.stdout));
        const source = JSON.parse(await Deno.readTextFile(manifest));
        const grants = JSON.parse(
            rendered["grants/orbit-gateway.yaml"],
        ).grants;
        assertEquals(
            grants.length,
            source.vaults.length + source.workflows.length,
        );
        assertEquals(
            grants.every((grant: { subject: string }) =>
                grant.subject === "user:orbit-gateway"
            ),
            true,
        );
        assertEquals(
            grants.find((grant: { resource: string }) =>
                grant.resource === "workflow:schedule-*"
            ).actions,
            ["run", "read", "write"],
        );
        assertEquals(
            grants.some((grant: { condition?: string }) =>
                grant.condition?.includes("^schedule-command-")
            ),
            true,
        );
        const others = JSON.parse(
            rendered["grants/orbit-serve.yaml"],
        ).grants;
        assertEquals(others, JSON.parse(await Deno.readTextFile(serve)).grants);
        // Native resource outputs have generated keys, separate from Gateway execution-input copies.
        const outputs = others.filter((grant: { subject: string }) =>
            grant.subject === "user:orbit-gateway"
        );
        assertEquals(outputs.length, 1);
        const output = outputs[0];
        assertEquals(output.resource, "vault:orbit-control-inputs");
        assertEquals(output.effect, "allow");
        assertEquals(output.actions, ["read", "write"]);
        const expression = /^key\.matches\((.+)\)$/.exec(output.condition);
        if (!expression) {
            throw new Error(
                "Native output grants must be scoped to their generated keys",
            );
        }
        const pattern = new RegExp(JSON.parse(expression[1]));
        const prefix =
            "swamp-cfgmgmt-docker_container-75e257b4-7510-425b-867e-a25f62829390-";
        for (const method of ["apply", "check"]) {
            assertEquals(
                pattern.test(
                    prefix + method + "-state-10.231.0.10-current.env",
                ),
                true,
            );
        }
        for (
            const key of [
                "process-create-operation-17",
                "schedule-command-c1bb62f0-a2d9-48ae-92b1-740fa3619933",
                prefix + "restart-state-10.231.0.10-current.env",
                prefix + "check-state-10.231.0.10-current.volumes",
                "swamp-cfgmgmt-file-75e257b4-7510-425b-867e-a25f62829390-check-state-10.231.0.10-current.env",
            ]
        ) assertEquals(pattern.test(key), false, key);
    } finally {
        await Deno.remove(repo, { recursive: true });
    }
});

Deno.test("the release restarts serve only for a shipped file serve did not start with (Lab #4282)", async () => {
    const repo = await Deno.makeTempDir();
    const git = async (...args: string[]) => {
        const result = await new Deno.Command("git", {
            args,
            cwd: repo,
            stdout: "piped",
            stderr: "piped",
        }).output();
        assertEquals(
            result.success,
            true,
            new TextDecoder().decode(result.stderr),
        );
        return new TextDecoder().decode(result.stdout).trim();
    };
    const manifest = async (dir: string, files: string[]) => {
        await Deno.mkdir(`${repo}/swamp/extensions/${dir}`, {
            recursive: true,
        });
        await Deno.writeTextFile(
            `${repo}/swamp/extensions/${dir}/manifest.yaml`,
            `name: ${dir}\nadditionalFiles:\n${
                files.map((f) => `  - ${f}\n`).join("")
            }`,
        );
    };
    const commit = async () => {
        await git("add", "-A");
        await git(
            "-c",
            "user.name=t",
            "-c",
            "user.email=t@t",
            "commit",
            "-qm",
            "c",
        );
        return await git("rev-parse", "HEAD");
    };
    try {
        await git("init", "-q");
        await manifest("a", ["scripts/one.py"]);
        await manifest("b", ["scripts/two.py"]);
        const served = await commit();
        await Deno.writeTextFile(`${repo}/swamp/extensions/b/other.ts`, "");
        const same = await commit();
        await manifest("a", ["scripts/one.py", "scripts/three.py"]);
        const changed = await commit();
        // A removed entry needs no restart: serve serves a listed file that is gone as missing.
        await manifest("b", []);
        const removed = await commit();
        const program = SWAMP_WORKERS_PROGRAM.split(
            "if __name__ == '__main__':",
        )[0];
        const result = await new Deno.Command("python3", {
            args: [
                "-I",
                "-c",
                `${program}\nprint(json.dumps([added(additional_files(sys.argv[1]), additional_files(c)) for c in sys.argv[2:]]))`,
                served,
                same,
                changed,
                removed,
            ],
            cwd: repo,
        }).output();
        assertEquals(
            result.success,
            true,
            new TextDecoder().decode(result.stderr),
        );
        assertEquals(JSON.parse(new TextDecoder().decode(result.stdout)), [
            [],
            ["swamp/extensions/a/manifest.yaml"],
            ["swamp/extensions/a/manifest.yaml"],
        ]);
    } finally {
        await Deno.remove(repo, { recursive: true });
    }
    for (
        const part of [
            "'HEAD@{' + started + '}'",
            "'ExecMainStartTimestamp'",
            "'--on-active=5s'",
            "https://swamp-club.com/lab/4282",
        ]
    ) assertEquals(SWAMP_WORKERS_PROGRAM.includes(part), true, part);
});
