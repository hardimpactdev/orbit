// Canonical UFW engine shared with the native Router script assets.
export const FirewallProgram = await Deno.readTextFile(
    new URL("./firewall-command/scripts/firewall.py", import.meta.url),
);
