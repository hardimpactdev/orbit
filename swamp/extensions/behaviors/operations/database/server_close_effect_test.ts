import { assertEquals } from "jsr:@std/assert@1.0.19";
import { closeServer } from "./server_close.ts";
import { ValkeyClient } from "./valkey_client.ts";

Deno.test({
  name:
    "disposable Valkey closes anonymous login and resumes with its original admin password",
  ignore: Deno.env.get("DATABASE_VALKEY_EFFECT_CANARY") !== "1",
  fn: async () => {
    const name = `orbit-database-close-test-${crypto.randomUUID()}`;
    const dir = await Deno.makeTempDir({ prefix: "database-close-" });
    const docker = async (...args: string[]) => {
      const result = await new Deno.Command("docker", {
        args,
        stdout: "piped",
        stderr: "null",
        signal: AbortSignal.timeout(15000),
      }).output();
      if (!result.success) throw new Error("Disposable Valkey fixture failed");
      return new TextDecoder().decode(result.stdout).trim();
    };
    try {
      await Deno.chmod(dir, 0o777);
      await Deno.writeTextFile(
        `${dir}/users.acl`,
        "user default on nopass ~* &* +@all\n",
        { mode: 0o666 },
      );
      await docker(
        "run",
        "--detach",
        "--rm",
        "--name",
        name,
        "--label",
        "orbit.test=database-server-close",
        "--user",
        `${Deno.uid()}:${Deno.gid()}`,
        "--publish",
        "127.0.0.1::6379",
        "--volume",
        `${dir}:/data`,
        "valkey/valkey:8.1",
        "valkey-server",
        "--aclfile",
        "/data/users.acl",
        "--save",
        "",
        "--appendonly",
        "no",
        "--protected-mode",
        "no",
      );
      const binding = await docker("port", name, "6379/tcp");
      const port = Number(binding.split(":").at(-1));
      const input = { host: "127.0.0.1", port, default_open: true };
      const password = `private-disposable-${crypto.randomUUID()}`;
      const use = (
        callback: (value: Record<string, unknown>) => Promise<void>,
      ) => callback({ password });
      for (let attempt = 0; attempt < 20; attempt++) {
        try {
          const client = await ValkeyClient.connect(input.host, port);
          await client.command("PING");
          client.close();
          break;
        } catch {
          if (attempt === 19) throw new Error("Disposable Valkey not ready");
          await new Promise((r) => setTimeout(r, 50));
        }
      }
      assertEquals(await closeServer(input, use), { backend: "active" });
      const anon = await ValkeyClient.connect(input.host, port);
      let refused = false;
      try {
        await anon.command("PING");
      } catch {
        refused = true;
      } finally {
        anon.close();
      }
      assertEquals(refused, true);
      assertEquals(await closeServer(input, use), { backend: "active" });
      assertEquals(
        (await Deno.readTextFile(`${dir}/users.acl`)).includes(password),
        false,
      );
    } finally {
      await docker("rm", "--force", name).catch(() => {});
      await Deno.remove(dir, { recursive: true });
    }
  },
});
