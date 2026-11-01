import { z } from "npm:zod@4.4.3";
import { caddyProgram } from "../routes/caddy-program.ts";
import { rolePairProgram } from "./role_source.ts";
import { runLocalProgram } from "../routes/local-program.ts";

/** Withdrawal only. Publication and activation belong to the native child. */
export const RoleCertificateProgram = '__name__="orbit_role_certificate"\n' +
    caddyProgram + rolePairProgram + String.raw`
try:
    payload=json.load(sys.stdin)
    with publication_lock(): result=role_remove(payload)
except CertificateRefusal as error: result={'error_code':str(error)}
except Refusal as error: result={'error_code':'caddy.publication_lock_failed' if error.stage=='lock' else 'caddy.certificate_failed'}
except Exception: result={'error_code':'caddy.certificate_failed'}
print(json.dumps(result))
`;
const Directory = z.string().regex(
    /^\/etc\/caddy\/orbit-[a-z][a-z0-9-]{0,63}-cert-current$/,
);
const Remove = z.object({ directory: Directory }).strict();
const Refusal = z.object({
    error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/),
}).strict();
export async function removeRoleCertificate(
    raw: unknown,
    invoke = runLocalProgram,
    signal?: AbortSignal,
) {
    return z.union([z.object({}).strict(), Refusal]).parse(
        await invoke(RoleCertificateProgram, {
            operation: "remove",
            ...Remove.parse(raw),
        }, signal),
    );
}
