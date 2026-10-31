import { z } from "npm:zod@4.4.3";
const Sha = z.string().regex(/^[a-f0-9]{64}$/);

/** Verify all delivery bytes before any privileged program can execute them. */
export async function downloadAgent(
  location: string,
  pin: string,
  signal?: AbortSignal,
  request: typeof fetch = fetch,
): Promise<Uint8Array> {
  Sha.parse(pin);
  const timeout = AbortSignal.timeout(120000);
  const readSignal = signal ? AbortSignal.any([signal, timeout]) : timeout;
  let url = new URL(location);
  let response: Response | undefined;
  for (let redirect = 0; redirect <= 5; redirect++) {
    if (url.protocol !== "https:" || url.username || url.password) {
      throw new Error("Agent delivery needs a credential-free HTTPS URL");
    }
    readSignal.throwIfAborted();
    response = await request(url, { redirect: "manual", signal: readSignal });
    if (![301, 302, 303, 307, 308].includes(response.status)) break;
    const next = response.headers.get("location");
    await response.body?.cancel();
    if (!next || redirect === 5) {
      throw new Error("Agent delivery redirects exceeded their bound");
    }
    url = new URL(next, url);
  }
  if (!response?.ok || !response.body) {
    throw new Error("Pinned agent delivery is unavailable");
  }
  let size = 0;
  const chunks: Uint8Array[] = [];
  for await (const chunk of response.body) {
    size += chunk.length;
    if (size > 128 * 1024 * 1024) {
      throw new Error("Agent delivery exceeded its bound");
    }
    chunks.push(chunk);
  }
  const bytes = new Uint8Array(size);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.length;
  }
  const digest = Array.from(
    new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  if (digest !== pin) {
    throw new Error("Agent delivery differs from the admitted digest");
  }
  return bytes;
}
