/** Run the product Caddyfile with real FrankenPHP; loopback peers stand in for the Node and an untrusted container. */
import { assert, assertEquals } from "jsr:@std/assert@1";

const binary = Deno.env.get("ORBIT_FRANKENPHP") ?? "/opt/orbit-runtime/current/php/8.5/bin/frankenphp";
let available = false;
try {
  available = Deno.statSync(binary).isFile;
} catch (error) {
  if (!(error instanceof Deno.errors.NotFound)) throw error;
}

Deno.test({
  name: "FrankenPHP translates CGI only for the trusted Node peer with forwarded HTTPS",
  ignore: !available || Deno.build.os !== "linux",
  async fn() {
    const folder = Deno.makeTempDirSync({ prefix: "orbit-frankenphp-" });
    const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
    const port = (listener.addr as Deno.NetAddr).port;
    listener.close();
    const template = Deno.readTextFileSync(new URL("../../apps/gateway/resources/instances/frankenphp.Caddyfile", import.meta.url));
    const config = template.replaceAll("__ORBIT_PROXY__", "127.0.0.2").replaceAll("__ORBIT_PORT__", String(port))
      .replaceAll("__ORBIT_ROOT__", folder).replace(`:${port} {`, `:${port} {\n    bind 127.0.0.1`);
    Deno.writeTextFileSync(`${folder}/Caddyfile`, config);
    Deno.writeTextFileSync(`${folder}/index.php`, `<?php
header('Content-Type: application/json');
echo json_encode(array_intersect_key($_SERVER, array_flip(['HTTPS', 'REQUEST_SCHEME', 'REMOTE_ADDR', 'SERVER_PORT'])));
`);
    const child = new Deno.Command(binary, {
      args: ["run", "--config", `${folder}/Caddyfile`, "--adapter", "caddyfile"],
      env: { HOME: folder },
      stdout: "piped",
      stderr: "piped",
    }).spawn();
    const output = child.output();
    try {
      for (let attempt = 0; ; attempt++) {
        try {
          const response = await fetch(`http://127.0.0.1:${port}/`);
          await response.body?.cancel();
          break;
        } catch (error) {
          if (attempt === 50) throw error;
          await new Promise((resolve) => setTimeout(resolve, 50));
        }
      }
      for (const [peer, proto, https, address, serverPort] of [
        ["127.0.0.2", "https", "on", "198.51.100.44", "443"],
        ["127.0.0.2", "http", "", "127.0.0.2", String(port)],
        ["127.0.0.3", "https", "", "127.0.0.3", String(port)],
        ["127.0.0.1", "", "", "127.0.0.1", String(port)],
      ]) {
        const response = await new Deno.Command("curl", {
          args: ["--fail", "--silent", "--show-error", "--max-time", "5", "--noproxy", "*", "--interface", peer,
            "-H", `X-Forwarded-Proto: ${proto}`, "-H", "X-Forwarded-For: 198.51.100.44", `http://127.0.0.1:${port}/`],
        }).output();
        assert(response.success, new TextDecoder().decode(response.stderr));
        assertEquals(JSON.parse(new TextDecoder().decode(response.stdout)), {
          HTTPS: https, REQUEST_SCHEME: https ? "https" : "http", REMOTE_ADDR: address, SERVER_PORT: serverPort,
        }, `peer=${peer}, proto=${proto}`);
      }
    } finally {
      child.kill("SIGTERM");
      await output;
      Deno.removeSync(folder, { recursive: true });
    }
  },
});
