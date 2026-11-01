/** Bounded RESP2 transport. Replies and diagnostics stay inside the Node effect. */
export class ValkeyClient {
  private buffer = new Uint8Array(0);
  private offset = 0;
  private bytes = 0;
  constructor(private conn: Deno.Conn) {}

  static async connect(host: string, port: number) {
    let expired = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const connecting = Deno.connect({ hostname: host, port });
    try {
      const conn = await Promise.race([
        connecting.then((conn) => {
          if (expired) {
            conn.close();
            throw new Error("Valkey connect expired");
          }
          return conn;
        }),
        new Promise<never>((_, reject) => {
          timer = setTimeout(() => {
            expired = true;
            reject(new Error("Valkey connect expired"));
          }, 5000);
        }),
      ]);
      return new ValkeyClient(conn);
    } finally {
      clearTimeout(timer);
    }
  }

  close() {
    try {
      this.conn.close();
    } catch { /* already closed */ }
  }

  async command(...args: string[]): Promise<unknown> {
    const encoder = new TextEncoder();
    const parts = args.map((arg) => encoder.encode(arg));
    const chunks = [encoder.encode(`*${parts.length}\r\n`)];
    for (const part of parts) {
      chunks.push(
        encoder.encode(`$${part.length}\r\n`),
        part,
        encoder.encode("\r\n"),
      );
    }
    const size = chunks.reduce((n, chunk) => n + chunk.length, 0);
    if (size > 1024 * 1024) throw new Error("Valkey request bound");
    const wire = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      wire.set(chunk, offset);
      offset += chunk.length;
    }
    let timer: ReturnType<typeof setTimeout> | undefined;
    try {
      return await Promise.race([
        (async () => {
          let written = 0;
          while (written < wire.length) {
            written += await this.conn.write(wire.subarray(written));
          }
          this.bytes = 0;
          return await this.reply(0);
        })(),
        new Promise<never>((_, reject) => {
          timer = setTimeout(() => {
            this.close();
            reject(new Error("Valkey command expired"));
          }, 10000);
        }),
      ]);
    } finally {
      clearTimeout(timer);
    }
  }

  private async byte(): Promise<number> {
    if (++this.bytes > 8 * 1024 * 1024) throw new Error("Valkey reply bound");
    if (this.offset === this.buffer.length) {
      const buffer = new Uint8Array(8192);
      const size = await this.conn.read(buffer);
      if (size === null || size === 0) {
        throw new Error("Valkey reply incomplete");
      }
      this.buffer = buffer.subarray(0, size);
      this.offset = 0;
    }
    return this.buffer[this.offset++];
  }

  private async line() {
    const bytes: number[] = [];
    while (bytes.length <= 1024 * 1024) {
      const byte = await this.byte();
      if (byte === 13) {
        if (await this.byte() !== 10) throw new Error("Valkey reply invalid");
        return new TextDecoder("utf-8", { fatal: true }).decode(
          new Uint8Array(bytes),
        );
      }
      bytes.push(byte);
    }
    throw new Error("Valkey line bound");
  }

  private async reply(depth: number): Promise<unknown> {
    if (depth > 16) throw new Error("Valkey nesting bound");
    const kind = await this.byte();
    const line = await this.line();
    if (kind === 45) throw new Error("Valkey command refused");
    if (kind === 43) return line;
    if (!/^-?[0-9]+$/.test(line)) throw new Error("Valkey reply invalid");
    const count = Number(line);
    if (!Number.isSafeInteger(count)) throw new Error("Valkey number bound");
    if (kind === 58) return count;
    if (count === -1 && (kind === 36 || kind === 42)) return null;
    if (count < 0) throw new Error("Valkey length invalid");
    if (kind === 36 && count <= 1024 * 1024) {
      const bytes = new Uint8Array(count);
      for (let i = 0; i < count; i++) bytes[i] = await this.byte();
      if (await this.byte() !== 13 || await this.byte() !== 10) {
        throw new Error("Valkey bulk invalid");
      }
      return new TextDecoder("utf-8", { fatal: true }).decode(bytes);
    }
    if (kind === 42 && count <= 10000) {
      const values = [];
      for (let i = 0; i < count; i++) values.push(await this.reply(depth + 1));
      return values;
    }
    throw new Error("Valkey reply invalid");
  }
}
