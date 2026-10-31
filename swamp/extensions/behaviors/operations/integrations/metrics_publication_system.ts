import { z } from "npm:zod@4.4.3";
import { runMetricsProcess, type Runner } from "./metrics_system.ts";
const Allow = "orbit:metrics-grafana-upstream",
  Deny = "orbit:metrics-grafana-isolation",
  Trust = "orbit:wireguard-members";
export class MetricsPublicationError extends Error {
  constructor(code: string) {
    super("metrics.publication_firewall_" + code);
  }
}
type Row = { number: number; comment: string; body: string };
export type PublicationFirewallInput = {
  address: string;
  action: { operation: "converge" | "remove"; gateway: string } | {
    operation: "abandon";
  };
};
function rows(text: string): Row[] {
  if (!/^Status: active$/im.test(text)) {
    throw new MetricsPublicationError("inactive");
  }
  return text.split("\n").flatMap((line) => {
    const split = line.lastIndexOf("#");
    if (split < 0) return [];
    const comment = line.slice(split + 1).trim();
    if (![Allow, Deny, Trust].includes(comment)) return [];
    const match = /^\[\s*([1-9][0-9]*)\]\s*(.+)$/.exec(
      line.slice(0, split).trim(),
    );
    if (!match) throw new MetricsPublicationError("ownership_drift");
    return [{ number: Number(match[1]), body: match[2].trim(), comment }];
  });
}
function owned(
  all: Row[],
  comment: string,
  address: string,
  gateway: string,
): number | undefined {
  const found = all.filter((row) => row.comment === comment);
  if (found.length > 1) throw new MetricsPublicationError("ownership_drift");
  if (!found.length) return;
  const row = found[0], words = row.body.split(/\s+/);
  let iface = false;
  for (let i = words.indexOf("on"); i !== -1; i = words.indexOf("on")) {
    if (words[i + 1] !== "orbit") {
      throw new MetricsPublicationError("ownership_drift");
    }
    words.splice(i, 2);
    iface = true;
  }
  const host = (value: string) => value.replace(/\/32$/, "");
  if (
    !iface || words.length !== 5 || host(words[0]) !== address ||
    words[1] !== "3000/tcp" ||
    words[2] !== (comment === Allow ? "ALLOW" : "DENY") || words[3] !== "IN" ||
    (comment === Allow
      ? host(words[4]) !== gateway
      : !["Anywhere", "0.0.0.0/0"].includes(words[4]))
  ) throw new MetricsPublicationError("ownership_drift");
  return row.number;
}
export function publicationFirewallActions(run: Runner = runMetricsProcess) {
  return async (input: PublicationFirewallInput, signal?: AbortSignal) => {
    if (
      !z.ipv4().safeParse(input.address).success ||
      !["converge", "remove", "abandon"].includes(input.action.operation) ||
      (input.action.operation !== "abandon" &&
        !z.ipv4().safeParse(input.action.gateway).success)
    ) throw new MetricsPublicationError("input_invalid");
    const call = async (args: string[], code: string) => {
      const result = await run("/usr/sbin/ufw", args, {
        signal,
        timeoutMs: 120000,
      });
      if (result.code !== 0) throw new MetricsPublicationError(code);
      return result.stdout;
    };
    const status = async () =>
      rows(await call(["status", "numbered"], "inspection_failed"));
    const remove = (number: number) =>
      call(["--force", "delete", String(number)], "remove_failed");
    const beforeTrust = (all: Row[], number: number) =>
      all.filter((row) => row.comment === Trust).every((row) =>
        number < row.number
      );
    let all = await status();
    const gateway = input.action.operation === "abandon"
      ? ""
      : input.action.gateway;
    let allow = input.action.operation === "abandon"
      ? undefined
      : owned(all, Allow, input.address, gateway);
    let deny = input.action.operation === "abandon"
      ? undefined
      : owned(all, Deny, input.address, gateway);
    if (input.action.operation !== "converge") {
      const matching = all.filter((row) => [Allow, Deny].includes(row.comment))
        .sort((a, b) => b.number - a.number);
      for (const row of matching) await remove(row.number);
      if ((await status()).some((row) => [Allow, Deny].includes(row.comment))) {
        throw new MetricsPublicationError("remove_verify_failed");
      }
      return { changed: matching.length > 0 };
    }
    if (
      allow !== undefined && deny !== undefined && allow < deny &&
      beforeTrust(all, deny)
    ) return { changed: false };
    if (deny !== undefined && !beforeTrust(all, deny)) {
      await remove(deny);
      all = await status();
      deny = undefined;
    }
    const insert = (allow: boolean) =>
      call([
        "insert",
        "1",
        allow ? "allow" : "deny",
        "in",
        "on",
        "orbit",
        "proto",
        "tcp",
        "from",
        allow ? gateway : "any",
        "to",
        input.address,
        "port",
        "3000",
        "comment",
        allow ? Allow : Deny,
      ], "apply_failed");
    if (deny === undefined) {
      await insert(false);
      all = await status();
      deny = owned(all, Deny, input.address, gateway);
      if (deny === undefined || !beforeTrust(all, deny)) {
        throw new MetricsPublicationError("verify_failed");
      }
    }
    allow = owned(all, Allow, input.address, gateway);
    if (allow !== undefined && allow > deny) await remove(allow);
    all = await status();
    if (owned(all, Allow, input.address, gateway) === undefined) {
      await insert(true);
    }
    all = await status();
    allow = owned(all, Allow, input.address, gateway);
    deny = owned(all, Deny, input.address, gateway);
    if (
      allow === undefined || deny === undefined || allow >= deny ||
      !beforeTrust(all, deny)
    ) throw new MetricsPublicationError("verify_failed");
    return { changed: true };
  };
}
export const publicationFirewall = publicationFirewallActions();
