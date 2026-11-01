type Fields = Record<string, unknown>;
const ex = (value: string) => "${{ " + value + " }}";
/** Select named public phase facts. Never publish the execution context. */
export function roleResultInputs(intent: string, prefix: string): Fields {
    const names = [
        "account",
        "observer_install",
        "packages",
        "storage",
        "hibernation",
        "tmpfiles",
        "runtime-0",
        "publication-0",
        "caddy_service-0",
        "caddy-0",
        "firewall-0",
        "observer",
        "peer_dns-0",
        "php_runtime",
        "cli",
    ];
    const key = (name: string) => JSON.stringify(prefix + name);
    return {
        intent: ex(intent),
        steps: Object.fromEntries(names.map((name) => [name, {
            status: ex(
                "(" + key(name) + " in steps ? steps[" + key(name) +
                    '].status : "skipped")',
            ),
            ...(name === "cli"
                ? {
                    outputs: ex(
                        "(" + key(name) + " in steps && steps[" + key(name) +
                            '].status == "succeeded" ? steps[' + key(name) +
                            "].outputs : {})",
                    ),
                }
                : {}),
        }])),
        private_dns_statuses: ex(
            "steps.filter(k,k.startsWith(" +
                JSON.stringify(prefix + "private_dns-") +
                ")).map(k,steps[k].status)",
        ),
        certificate_statuses: ex(
            "steps.filter(k,k.startsWith(" +
                JSON.stringify(prefix + "certificates-") +
                ")).map(k,steps[k].status)",
        ),
    };
}
