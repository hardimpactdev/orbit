import { z } from "npm:zod@4.4.3";
import {
  type BootstrapEnvironment,
  bootstrapSystem,
  BootstrapTarget,
} from "../node-bootstrap/bootstrap_ssh.ts";
import { removeNodeArtifacts } from "./removal_system.ts";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

export const RemovalRetirement = z.object({
  executor_node_id: z.number().int().positive(),
  target: BootstrapTarget,
}).strict().refine((value) => value.executor_node_id !== value.target.node_id);
export type RemovalRetirement = z.infer<typeof RemovalRetirement>;

const ProbeProgram = String.raw`
import json,socket,sys
i=json.load(sys.stdin)
try:
 with socket.create_connection((i['host'],i['port']),timeout=5): pass
 print('{"reachable":true}')
except (ConnectionRefusedError,TimeoutError,socket.timeout): print('{"reachable":false}')
except OSError as e:
 if e.errno in [101,113]: print('{"reachable":false}')
 else: sys.exit(1)
`;

/** An offline assertion is checked from the admitted Gateway, without credentials or effects. */
export async function removalReachable(
  raw: RemovalRetirement,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<boolean> {
  const input = RemovalRetirement.parse(raw);
  const result = await invoke({
    command: "/usr/bin/python3",
    args: ["-c", ProbeProgram],
    stdin: JSON.stringify({ host: input.target.host, port: input.target.port }),
    timeoutMs: 10000,
  }, signal);
  if (!result.success) {
    throw new Error("The Node removal reachability outcome is uncertain");
  }
  return z.object({ reachable: z.boolean() }).strict().parse(
    JSON.parse(result.stdout),
  ).reachable;
}

/** Final retirement runs off-worker through the existing admitted, pinned SSH channel.
 * The parent projects deletion only after service inactivity and file removal return.
 */
export async function retireNodeOffWorker(
  raw: RemovalRetirement,
  identity: string,
  environment: BootstrapEnvironment,
  signal?: AbortSignal,
  transport = bootstrapSystem,
) {
  const input = RemovalRetirement.parse(raw);
  return await removeNodeArtifacts(
    { node_id: input.target.node_id },
    transport(input.target, identity, environment),
    signal,
    true,
  );
}
