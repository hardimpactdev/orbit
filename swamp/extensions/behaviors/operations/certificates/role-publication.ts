import { z } from "npm:zod@4.4.3";
import { caddyProgram } from "../routes/caddy-program.ts";
import { rolePairProgram } from "./role_source.ts";
import { runLocalProgram } from "../routes/local-program.ts";

/** Role/extension pair transaction on the same host lock as the whole Caddy publication. */
export const RoleCertificateExtension = rolePairProgram + String.raw`
def role_certificate(payload):
    current=certificate_scope(payload['directory'])
    if not certificate_parents():
        if payload['operation']=='remove': return {}
        raise CertificateRefusal('caddy.certificate_unsafe')
    if payload['operation']=='remove' and not exists(current): return {}
    gid=grp.getgrnam('caddy').gr_gid;previous=previous_pair(current,gid)
    if payload['operation']=='remove':
        if previous is not None: remove(current)
        return {}
    values=certificate_values(payload)
    if previous is not None and set(previous)-{'root-ca.pem'}!=set(values)-{'root-ca.pem'}: raise CertificateRefusal('caddy.certificate_unsafe')
    candidate=certificate_candidate(current,payload,gid);recovery_failed=False
    try:
        if previous=={name:value.encode() for name,value in values.items()}: return {'changed':False}
        active=certificate_active()
        if previous is None: candidate.rename(current)
        else: exchange_pair(candidate,current)
        if active and not certificate_reload():
            try:
                if previous is None: current.rename(candidate)
                else: exchange_pair(candidate,current)
            except OSError:
                recovery_failed=True;raise CertificateRefusal('caddy.certificate_recovery_failed')
            if not certificate_reload():
                recovery_failed=True;raise CertificateRefusal('caddy.certificate_recovery_failed')
            raise CertificateRefusal('caddy.certificate_failed')
        return {'changed':True}
    finally:
        if not recovery_failed and exists(candidate): remove(candidate)
`;
export const RoleCertificateProgram = '__name__="orbit_role_certificate"\n' +
    caddyProgram + RoleCertificateExtension + String.raw`
try:
    payload=json.load(sys.stdin)
    with publication_lock(): result=role_certificate(payload)
except CertificateRefusal as error: result={'error_code':str(error)}
except Refusal as error: result={'error_code':'caddy.publication_lock_failed' if error.stage=='lock' else 'caddy.certificate_failed'}
except Exception: result={'error_code':'caddy.certificate_failed'}
print(json.dumps(result))
`;
const Directory = z.string().regex(
    /^\/etc\/caddy\/orbit-[a-z][a-z0-9-]{0,63}-cert-current$/,
);
const Install = z.object({
    directory: Directory,
    certificate_file: z.string(),
    key_file: z.string(),
    certificate: z.string().max(65536),
    key: z.string().max(65536),
    service: z.literal("caddy"),
}).strict();
const Remove = z.object({ directory: Directory }).strict();
const Refusal = z.object({
    error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/),
}).strict();
/** Call only inside the owner's private-material callback; never return the pair from a step. */
export async function installRoleCertificate(
    raw: unknown,
    invoke = runLocalProgram,
    signal?: AbortSignal,
) {
    return z.union([z.object({ changed: z.boolean() }).strict(), Refusal])
        .parse(
            await invoke(RoleCertificateProgram, {
                operation: "install",
                ...Install.parse(raw),
            }, signal),
        );
}
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
