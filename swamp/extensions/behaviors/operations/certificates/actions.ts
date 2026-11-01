import { type CertificateSystemCall, runCertificateSystem } from "./system.ts";
import { Refusal, Removal } from "./input.ts";

type Invoke = (
    call: CertificateSystemCall,
    signal?: AbortSignal,
) => Promise<Record<string, unknown>>;
function refused(value: unknown) {
    return Refusal.safeParse(value);
}
/** Retained residue scope removal; all issuing callers use native certificate children. */
export function certificateActions(invoke: Invoke = runCertificateSystem) {
    return {
        remove: async (raw: unknown, signal?: AbortSignal) => {
            const leaf = Removal.parse(raw);
            if (leaf.in_use) {
                return { error_code: "app-dev.certificate_in_use" };
            }
            const result = await invoke({
                name: "certificate.remove-leaf",
                input: { scope: leaf.scope, home: leaf.home, user: leaf.user },
            }, signal);
            return refused(result).success
                ? Refusal.parse(result)
                : { applied: true as const };
        },
    };
}
