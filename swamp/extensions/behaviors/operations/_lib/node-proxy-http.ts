import { commandOutput } from "./node-command-process.ts";
import { type NodeProxy, proxyLaunch } from "./node-proxy.ts";

const curlQuote = (value: string) => JSON.stringify(value);
/** Keep Gateway peer authentication on the effect Node, without an identity header. */
export function proxyHttpConfig(
  url: string,
  method: string,
  path: string,
  body: unknown,
  key: string | undefined,
  timeoutMs: number,
  caFile: string,
): string {
  if (!Number.isSafeInteger(timeoutMs) || timeoutMs <= 0) {
    throw new Error("Invalid proxy Gateway timeout");
  }
  const target = new URL(path, url);
  if (
    target.origin !== "https://gateway.orbit" ||
    !target.pathname.startsWith("/api/v1/") ||
    !["GET", "POST", "PUT", "PATCH"].includes(method) ||
    key !== undefined && /[\r\n]/.test(key)
  ) throw new Error("Invalid proxy Gateway request");
  return [
    `url = ${curlQuote(target.href)}`,
    `request = ${curlQuote(method)}`,
    `cacert = ${curlQuote(caFile)}`,
    'proto = "=https"',
    "silent",
    "show-error",
    `max-time = ${Math.ceil(timeoutMs / 1000)}`,
    "connect-timeout = 8",
    'header = "Accept: application/json"',
    ...(key ? [`header = ${curlQuote(`Idempotency-Key: ${key}`)}`] : []),
    ...(body === undefined ? [] : [
      'header = "Content-Type: application/json"',
      `data-binary = ${curlQuote(JSON.stringify(body))}`,
    ]),
    'write-out = "\\n%{http_code}\\t%{content_type}"',
    "",
  ].join("\n");
}

export async function proxyGatewayRequest(
  proxy: NodeProxy,
  url: string,
  caFile: string,
  method: string,
  path: string,
  body?: unknown,
  key?: string,
  signal?: AbortSignal,
  timeoutMs = 60000,
): Promise<{ status: number; body: unknown }> {
  const timeout = AbortSignal.timeout(timeoutMs + 1000);
  const result = await commandOutput(
    proxyLaunch(proxy, ["/usr/bin/curl", "--config", "-"], timeoutMs),
    new TextEncoder().encode(
      proxyHttpConfig(url, method, path, body, key, timeoutMs, caFile),
    ),
    268435456 + 1024,
    signal ? AbortSignal.any([signal, timeout]) : timeout,
  );
  if (!result.success) {
    throw new Error("The proxy Gateway request did not complete");
  }
  const bytes = result.stdout;
  const end = bytes.lastIndexOf(10);
  const metadata = /^(\d{3})\t([^\r\n]*)$/.exec(
    new TextDecoder().decode(bytes.subarray(end + 1)),
  );
  if (end < 0 || !metadata) throw new Error("Invalid proxy Gateway response");
  const data = bytes.slice(0, end);
  return {
    status: Number(metadata[1]),
    body: metadata[2].split(";")[0] === "application/octet-stream"
      ? data
      : JSON.parse(new TextDecoder().decode(data)),
  };
}
