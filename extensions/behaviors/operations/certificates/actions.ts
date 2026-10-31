import { z } from "npm:zod@4.4.3";
import { type CertificateSystemCall, runCertificateSystem } from "./system.ts";
import {
  Applied,
  Leaf,
  Presence,
  Refusal,
  Removal,
  Request,
  Scope,
  Signed,
} from "./input.ts";

type Invoke = (
  call: CertificateSystemCall,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;
const RawRequest = z.union([
  z.object({ state: z.literal("current") }),
  z.object({
    state: z.literal("request"),
    request: z.string().startsWith("-----BEGIN CERTIFICATE REQUEST-----").max(
      24576,
    ),
  }),
]);
function refused(value: unknown) {
  return Refusal.safeParse(value);
}
function wire(leaf: z.infer<typeof Leaf>) {
  const { node_id: _node, agent_sha256: _pin, ...input } = leaf;
  return input;
}

/** Fixed local primitives reusable inside any admitted parent native DAG. */
export function certificateActions(invoke: Invoke = runCertificateSystem) {
  return {
    request: async (raw: unknown, signal?: AbortSignal) => {
      const leaf = Leaf.parse(raw);
      const result = await invoke({
        name: "certificate.request-leaf",
        input: wire(leaf),
      }, signal);
      if (refused(result).success) return Refusal.parse(result);
      const request = RawRequest.parse(result);
      return request.state === "current"
        ? request
        : { state: "request" as const, request_base64: btoa(request.request) };
    },
    publish: async (
      raw: unknown,
      rawRequest: unknown,
      rawSigned: unknown,
      signal?: AbortSignal,
    ) => {
      const leaf = Leaf.parse(raw), request = Request.parse(rawRequest);
      if (refused(request).success) return Refusal.parse(request);
      if ("state" in request && request.state === "current") {
        return { applied: true as const };
      }
      const signed = Signed.parse(rawSigned);
      if (refused(signed).success) return Refusal.parse(signed);
      if (!("certificate_base64" in signed)) {
        throw new Error("Public certificate signature missing");
      }
      const result = await invoke({
        name: "certificate.publish-leaf",
        input: {
          ...wire(leaf),
          certificate: atob(signed.certificate_base64),
          root_certificate: atob(signed.root_certificate_base64),
        },
      }, signal);
      return refused(result).success
        ? Refusal.parse(result)
        : { applied: true as const };
    },
    inspect: async (raw: unknown, previous: unknown, signal?: AbortSignal) => {
      const scope = Scope.parse(raw);
      if (refused(previous).success) return Refusal.parse(previous);
      Applied.parse(previous);
      return Presence.parse(
        await invoke({
          name: "certificate.inspect-leaf",
          input: { scope: scope.scope, home: scope.home },
        }, signal),
      );
    },
    remove: async (raw: unknown, signal?: AbortSignal) => {
      const leaf = Removal.parse(raw);
      if (leaf.in_use) return { error_code: "app-dev.certificate_in_use" };
      const result = await invoke({
        name: "certificate.remove-leaf",
        input: { scope: leaf.scope, home: leaf.home },
      }, signal);
      return refused(result).success
        ? Refusal.parse(result)
        : { applied: true as const };
    },
  };
}
