import { z } from "npm:zod@4.4.3";
import { toolSetupPlan } from "../node-bootstrap/tool_setup_plan.ts";
import { composePhp } from "../routes/publication-command/assets.ts";
import { mergeDns } from "../routes/mutation-command/dns.ts";

const account = z.record(z.string(), z.array(z.string()));
const decode = (text: string) =>
    new TextDecoder().decode(
        Uint8Array.from(atob(text), (c) => c.charCodeAt(0)),
    );
const read = async (path: string) => JSON.parse(await Deno.readTextFile(path));
const State = z.object({ records: z.string(), catalog: z.string() })
    .passthrough();
const Publication = z.object({
    node_id: z.number(),
    connection: z.object({ nodeUser: z.string() }).passthrough(),
    host_keys: z.array(z.string()),
    before: z.object({ php: z.unknown(), dns: State.nullable() }),
    after: z.object({ php: z.unknown(), dns: State.nullable() }),
}).passthrough();

/** Compose only public resource descriptions from Ansible's captured machine reads. */
export async function roleAddPlans(
    intent: Record<string, unknown>,
    folder: string,
    phase: "tools" | "publication",
) {
    if (phase === "tools") {
        const observed = account.parse(
            await read(folder + "/tools-account.json"),
        );
        const managers = z.array(
            z.object({
                user: z.string(),
                setup: z.unknown(),
            }).passthrough(),
        ).parse(intent.managers);
        return {
            managers: await Promise.all(
                managers.map((manager) =>
                    toolSetupPlan(manager.setup, observed[manager.user][4])
                ),
            ),
        };
    }
    const { control_node_id } = z.object({
        control_node_id: z.number().int().positive(),
    }).parse(intent.ssh);
    const publications: Record<string, unknown> = {};
    for (const publication of z.array(Publication).parse(intent.publications)) {
        const observed = z.object({
            account,
            pools: z.record(z.string(), z.string()),
            records: z.string(),
            catalog: z.string(),
        }).parse(
            await read(
                folder + "/publication-n" + publication.node_id + ".json",
            ),
        );
        const user = publication.connection.nodeUser;
        const current = Object.fromEntries(
            Object.entries(observed.pools).map(
                ([version, content]) => [version, decode(content)],
            ),
        );
        const php = publication.after.php === null
            ? { files: [] }
            : composePhp({
                node_id: publication.node_id,
                user,
                control_node_id,
                connection: publication.connection,
                host_keys: publication.host_keys,
                before: publication.before.php,
                after: publication.after.php,
                direction: "forward",
                current: JSON.stringify(current),
                account: JSON.stringify({
                    user,
                    group: observed.account[user][2],
                    home: observed.account[user][4],
                }),
            });
        const dns = publication.after.dns === null ? null : mergeDns(
            State.parse(publication.before.dns),
            publication.after.dns,
            {
                records: decode(observed.records),
                catalog: decode(observed.catalog),
            },
        );
        publications["n" + publication.node_id] = { php, dns };
    }
    return { publications };
}
