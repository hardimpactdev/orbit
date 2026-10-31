import { z } from "npm:zod@4.4.3";
import { FirewallProgram } from "./firewall-program.ts";
import { runLocalProgram } from "./local-program.ts";
import source from "./firewall-command/scripts/router_lan.py" with {
    type: "text",
};
export const RouterLanExtension = source.split("def main(value):")[0].replace(
    /^import firewall as engine$/m,
    "",
)
    .replace(/^from firewall import \([\s\S]*?^\)/m, "").replace(
        /^from firewall import .*$/m,
        "",
    );
export const RouterLanProgram = '__name__="orbit_router_lan"\n' +
    FirewallProgram + RouterLanExtension + `
try:
    with transaction_lock():
        result=router_lan(json.load(sys.stdin))
except Refusal as error: result=dict(error_code=error.code)
except Exception: result=dict(error_code='node.firewall_convergence_failed')
print(json.dumps(result))
`;
const Input = z.object({
    desired: z.array(z.record(z.string(), z.unknown())).max(4096),
    expand: z.boolean(),
    prune: z.boolean(),
}).strict();
export async function convergeRouterLan(
    raw: unknown,
    invoke = runLocalProgram,
    signal?: AbortSignal,
) {
    return z.union([
        z.object({ matches: z.literal(true) }).strict(),
        z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) })
            .strict(),
    ]).parse(await invoke(RouterLanProgram, Input.parse(raw), signal));
}
