import { behavior as planBehavior } from "../../extensions/behaviors/operations/database-server-commands/destroy_plan.ts";

/** Real action-file fixture; native ordering/resume is verified by the DAG suite. */
export async function destroyDatabase(
    intent: Record<string, unknown>,
    password: string,
) {
    const scratch = await Deno.makeTempDir({ prefix: "orbit-destroy-effect-" });
    let plan = "{}";
    const action = async (name: string, item = "") => {
        const process = new Deno.Command("python3", {
            args: [
                "-B",
                new URL(
                    "../../extensions/behaviors/operations/database-server-commands/scripts/destroy.py",
                    import.meta.url,
                ).pathname,
                JSON.stringify(intent),
                name,
                plan,
                item,
                scratch,
            ],
            stdin: "piped",
            stdout: "piped",
            stderr: "piped",
            signal: AbortSignal.timeout(120000),
        }).spawn();
        const writer = process.stdin.getWriter();
        await writer.write(
            new TextEncoder().encode(JSON.stringify({ password })),
        );
        await writer.close();
        const output = await process.output();
        const stdout = new TextDecoder().decode(output.stdout);
        if (output.stderr.length || (password && stdout.includes(password))) {
            throw new Error("Private action diagnostic");
        }
        const result = JSON.parse(stdout);
        if (!output.success) throw result;
        return result;
    };
    let selected: { databases: string[]; users: string[] } | undefined;
    let snapshot = false;
    let result: Record<string, unknown> = { backend: "absent" };
    try {
        const inventory = intent.driver === "mysql" &&
                (intent.test_prefixes as string[]).length
            ? await action("inventory")
            : { databases: [] };
        const value = await planBehavior.methods.read.execute({
            intent,
            inventory: JSON.stringify(inventory),
        }, { writeResource: () => Promise.resolve() });
        selected = value;
        plan = value.json;
        if (intent.driver === "redis") {
            await action("acl_snapshot");
            snapshot = true;
        } else {
            await action("ownership");
            if (intent.driver === "pgsql") await action("fence");
            await action("blockers");
            for (const database of value.databases) {
                await action("drop_database", database);
            }
        }
        for (const user of value.users) {
            await action(
                intent.driver === "redis" ? "acl_remove" : "drop_user",
                user,
            );
        }
        if (intent.driver === "redis") await action("acl_save");
    } catch (error) {
        result = error as Record<string, unknown>;
        if (snapshot && selected) {
            try {
                for (const user of selected.users) {
                    await action("acl_restore", user);
                }
                await action("acl_restore_save");
            } catch (restoreError) {
                result = restoreError as Record<string, unknown>;
            }
        }
    } finally {
        try {
            if (intent.driver === "pgsql" && selected) await action("restore");
        } finally {
            await Deno.remove(scratch, { recursive: true });
        }
    }
    return result;
}
