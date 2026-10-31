import { z } from "npm:zod@4.4.3";
import { certificateActions } from "../certificates/actions.ts";
import { Leaf as CertificateLeaf, Scope } from "../certificates/input.ts";
import { removeLan } from "../routes/lan.ts";
import { Leaf, Request, Signed } from "./lifecycle.ts";

type Actions = ReturnType<typeof certificateActions>;
type TrackingLeaf = z.infer<typeof Leaf>;
function successful<T extends Record<string, unknown>>(result: T): T {
  if (result.error_code !== undefined) {
    throw new Error(
      z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(result.error_code),
    );
  }
  return result;
}
function scope(leaf: TrackingLeaf) {
  return Scope.parse({
    node_id: leaf.node_id,
    user: leaf.user,
    ...(leaf.home === undefined ? {} : { home: leaf.home }),
    scope: leaf.scope,
    agent_sha256: leaf.agent_sha256,
  });
}

/** Node-local steps inside the admitted parent. No child run or Gateway callback. */
export function analyticsRouteActions(
  actions: Actions = certificateActions(),
  lan = removeLan,
) {
  const remove = async (raw: unknown, signal?: AbortSignal) => {
    signal?.throwIfAborted();
    const leaf = Leaf.parse(raw);
    if (leaf.in_use !== false) throw new Error("analytics.certificate_in_use");
    const removed = successful(
      await actions.remove({ ...scope(leaf), in_use: false }, signal),
    );
    z.object({ present: z.literal(false) }).parse(
      successful(await actions.inspect(scope(leaf), removed, signal)),
    );
    signal?.throwIfAborted();
    successful(await lan({ route_id: leaf.route_id }, undefined, signal));
    return { ready: true };
  };
  return {
    hostPrepare: async (raw: unknown, signal?: AbortSignal) => {
      signal?.throwIfAborted();
      const leaf = Leaf.parse(raw);
      const input = CertificateLeaf.parse({
        ...scope(leaf),
        domain: leaf.domain,
        version: leaf.version,
        root_hash: leaf.root_hash,
        user: leaf.user,
      });
      return Request.parse({
        node_id: leaf.node_id,
        scope: leaf.scope,
        ...successful(await actions.request(input, signal)),
      });
    },
    publish: async (
      raw: unknown,
      requests: unknown[],
      signatures: unknown[],
      signal?: AbortSignal,
    ) => {
      signal?.throwIfAborted();
      const leaf = Leaf.parse(raw);
      const input = CertificateLeaf.parse({
        ...scope(leaf),
        domain: leaf.domain,
        version: leaf.version,
        root_hash: leaf.root_hash,
        user: leaf.user,
      });
      if (signatures.length > 1) {
        throw new Error("analytics.certificate_mismatch");
      }
      const signed = signatures.length
        ? Signed.parse(signatures[0])
        : undefined;
      if (
        signed &&
        (signed.node_id !== leaf.node_id || signed.scope !== leaf.scope)
      ) {
        throw new Error("analytics.certificate_mismatch");
      }
      const matching = requests.map((value) => Request.parse(value)).filter((
        request,
      ) => request.node_id === leaf.node_id && request.scope === leaf.scope);
      if (matching.length !== 1) {
        throw new Error("analytics.certificate_mismatch");
      }
      const { node_id: _node, scope: _scope, ...request } = matching[0];
      if ((request.state === "request") !== Boolean(signed)) {
        throw new Error("analytics.certificate_mismatch");
      }
      const applied = successful(
        await actions.publish(
          input,
          request,
          signed
            ? {
              certificate_base64: signed.certificate_base64,
              root_certificate_base64: signed.root_certificate_base64,
            }
            : { skipped: true },
          signal,
        ),
      );
      signal?.throwIfAborted();
      z.object({ present: z.literal(true) }).parse(
        successful(await actions.inspect(scope(leaf), applied, signal)),
      );
      return { ready: true };
    },
    remove,
    hostWithdraw: remove,
  };
}
