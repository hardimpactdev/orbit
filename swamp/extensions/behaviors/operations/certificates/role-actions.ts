import { caddyProgram } from "../routes/caddy-program.ts";
import { runLocalProgram } from "../routes/local-program.ts";
import { Applied, Refusal, Request, Signed } from "./input.ts";
import { RoleLeaf } from "./role-input.ts";
import { RoleCertificateExtension } from "./role-publication.ts";
import { type ExtensionFile, roleSection } from "./role_source.ts";

async function retainedProgram(extensionFile: ExtensionFile) {
    const source = await Deno.readTextFile(
        await extensionFile("scripts/role_policy.py"),
    );
    return '__name__="orbit_role_actions"\n' + caddyProgram +
        RoleCertificateExtension + roleSection(source, "ACTIONS") + String.raw`
try:
    data=json.load(sys.stdin)
    with publication_lock():
        if data['operation']=='request': result=role_request(data)
        elif data['operation']=='publish': result=role_publish(data)
        else: raise CertificateRefusal('certificate.role_input_invalid')
except CertificateRefusal as error: result={'error_code':str(error)}
except Refusal as error: result={'error_code':'caddy.publication_lock_failed' if error.stage=='lock' else 'certificate.role_failed'}
except Exception: result={'error_code':'certificate.role_failed'}
print(json.dumps(result))
`;
}

export function roleCertificateActions(
    extensionFile: ExtensionFile,
    invoke = runLocalProgram,
) {
    return {
        request: async (raw: unknown, signal?: AbortSignal) => {
            const leaf = RoleLeaf.parse(raw);
            return Request.parse(
                await invoke(await retainedProgram(extensionFile), {
                    operation: "request",
                    ...leaf,
                }, signal),
            );
        },
        publish: async (
            raw: unknown,
            rawRequest: unknown,
            rawSigned: unknown,
            signal?: AbortSignal,
        ) => {
            const leaf = RoleLeaf.parse(raw),
                request = Request.parse(rawRequest);
            if ("error_code" in request) return request;
            const signed = Signed.parse(rawSigned);
            if ("error_code" in signed) return Refusal.parse(signed);
            if (
                request.state === "request" && !("certificate_base64" in signed)
            ) {
                throw new Error("Public role certificate signature missing");
            }
            return Applied.parse(
                await invoke(await retainedProgram(extensionFile), {
                    operation: "publish",
                    ...leaf,
                    request,
                    signed,
                }, signal),
            );
        },
    };
}
