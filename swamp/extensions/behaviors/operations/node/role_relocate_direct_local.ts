import { z } from "npm:zod@4.4.3";
import {
  WebSocketRuntimeError,
  websocketRuntimeSystem,
} from "../integrations/websocket_runtime_system.ts";
import { readRoleAccount } from "./role_baseline_system.ts";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

const AccountName = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Path = z.string().regex(/^(\/[A-Za-z0-9._-]+)+$/).refine((path) =>
  path.split("/").every((part) => part !== "." && part !== "..")
);
const Identity = z.object({
  node_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
});
export const WebSocketRuntime = Identity.extend({
  user: AccountName,
  install_path: Path,
  repository: z.string().url().startsWith("https://").max(2048),
  ref: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._/-]{0,255}$/),
  port: z.number().int().min(1).max(65535),
}).strict();
const Removal = Identity.extend({ install_path: Path, purge: z.boolean() })
  .strict();
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
export type WebSocketRuntimeActions = ReturnType<typeof websocketRuntimeSystem>;
type Outcome = { completed: true } | { error_code: string };

/** The canonical native Reverb lower owns release preparation, activation and recovery. */
export async function installWebSocketRuntime(
  raw: unknown,
  material: Record<string, unknown>,
  signal?: AbortSignal,
  transport: SystemTransport = localSystemCommand,
  runtime: WebSocketRuntimeActions = websocketRuntimeSystem(transport),
): Promise<Outcome> {
  const plan = WebSocketRuntime.parse(raw);
  signal?.throwIfAborted();
  const found = await readRoleAccount(plan.user, transport, signal);
  if (found === null) {
    return { error_code: "websocket.runtime_account_missing" };
  }
  let effectStarted = false;
  try {
    const environment = z.string().min(1).max(1048576).parse(
      material.websocket_environment,
    );
    signal?.throwIfAborted();
    effectStarted = true;
    const reply = await runtime.converge({
      user: found.user,
      group: found.group,
      install_path: plan.install_path,
      repository: plan.repository,
      ref: plan.ref,
      port: plan.port,
      environment,
      unit: webSocketUnit(
        plan.install_path,
        found.user,
        found.group,
        plan.port,
      ),
    }, signal);
    z.object({ release: Path }).strict().parse(reply);
    return { completed: true };
  } catch (error) {
    if (
      error instanceof WebSocketRuntimeError &&
      Code.safeParse(error.code).success
    ) {
      return { error_code: Code.parse(error.code) };
    }
    if (!effectStarted && signal?.aborted) {
      return { error_code: "operation.cancelled" };
    }
    throw new Error("Private WebSocket installation did not complete");
  }
}

/** Source teardown never needs a source secret, and never purges data during relocation. */
export async function removeWebSocketRuntime(
  raw: unknown,
  signal?: AbortSignal,
  transport: SystemTransport = localSystemCommand,
  runtime: WebSocketRuntimeActions = websocketRuntimeSystem(transport),
): Promise<Outcome> {
  const plan = Removal.parse(raw);
  signal?.throwIfAborted();
  try {
    z.object({}).strict().parse(
      await runtime.remove({
        install_path: plan.install_path,
        purge: plan.purge,
      }, signal),
    );
  } catch (error) {
    if (
      error instanceof WebSocketRuntimeError &&
      Code.safeParse(error.code).success
    ) {
      return { error_code: Code.parse(error.code) };
    }
    throw new Error("The WebSocket removal outcome is uncertain");
  }
  return { completed: true };
}

function webSocketUnit(
  path: string,
  user: string,
  group: string,
  port: number,
): string {
  return `# Managed by Orbit. Do not edit; the websocket role converge overwrites this file.
[Unit]
Description=Orbit websocket role: Reverb realtime server
After=network.target

[Service]
Type=simple
User=${user}
Group=${group}
WorkingDirectory=${path}
ExecStart=/usr/bin/php ${path}/artisan orbit:reverb:start --host=127.0.0.1 --port=${port}
Restart=always
RestartSec=1

[Install]
WantedBy=multi-user.target
`;
}
