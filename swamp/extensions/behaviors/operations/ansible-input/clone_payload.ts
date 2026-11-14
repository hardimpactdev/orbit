/**
 * The clone part of the `write` method's output (write.ts): the launcher, the private folder's commands and the payload that a
 * clone intent (`container.clone`) runs. Only a clone intent reaches it; a Node converge intent has no `container`, so the nested
 * fleet's converge digest leaves this module out (swamp/ansible/fleet-converge-inputs.json, its `cut_imports`).
 */
type Context = { extensionFile(path: string): string };
type Container = { clone?: unknown; instance_id: number; allowed_devices?: unknown; club_key?: unknown };

export type ClonePart = { clone_argv: string[]; clone_stage_argv: string[]; clone_payload: string };

export async function clonePart(container: Container, intent: Record<string, unknown>, privatePath: string, ctx: Context): Promise<ClonePart> {
    const launcher = await Deno.readTextFile(ctx.extensionFile("scripts/instance_clone_launch.py"));
    const network = await Deno.readTextFile(ctx.extensionFile("ansible/instance_network.py"));
    const cloneNetwork = container.allowed_devices
        ? network.split('if __name__ == "__main__":')[0] + await Deno.readTextFile(ctx.extensionFile("ansible/instance_nested.py")) : network;
    const clonePayload = JSON.stringify({
        launcher,
        script: await Deno.readTextFile(ctx.extensionFile("scripts/instance_clone.py")),
        container,
        guest: await Deno.readTextFile(ctx.extensionFile("scripts/instance_clone_guest.py")),
        network: cloneNetwork,
        account: (intent.ssh_execution as { target: { user: string } }).target.user,
        bundle: {
            node_services: await Deno.readTextFile(ctx.extensionFile("ansible/instance_node_services.py")),
            machine_name: await Deno.readTextFile(ctx.extensionFile("ansible/instance_machine_name.py")),
            mail: await Deno.readTextFile(ctx.extensionFile("ansible/instance_mail.py")),
            bridge: await Deno.readTextFile(ctx.extensionFile("ansible/instance_bridge.py")),
            node: await Deno.readTextFile(ctx.extensionFile("ansible/instance_node_runtime.py")),
            // Only a nested-fleet clone delivers its nested Gateway's Club key.
            ...(container.club_key ? { club_key: await Deno.readTextFile(ctx.extensionFile("ansible/instance_nested_club_key.sh")) } : {}),
        },
    });
    return {
        clone_argv: ["python3", privatePath + "/launch.py", privatePath],
        clone_stage_argv: ["python3", "-c", launcher, privatePath],
        clone_payload: clonePayload,
    };
}
