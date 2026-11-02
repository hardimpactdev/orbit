import type { FleetContext } from "https://raw.githubusercontent.com/swamp-club/swamp-extensions/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/ssh/extensions/models/_lib/operations.ts";

/** Transport type used only by the retained WireGuard native fixture. */
export type BootstrapSsh = (
  target: { node_id: number; host: string; port: number; user: string; fingerprint: string },
  identity: string,
  environment: Pick<FleetContext, "createCelEnvironment">,
  argv: string[],
  stdin: string | undefined,
  seconds: number,
  upload?: { src: string; dst: string },
  signal?: AbortSignal,
) => Promise<string>;
