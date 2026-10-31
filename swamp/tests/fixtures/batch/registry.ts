import { assertEquals } from "jsr:@std/assert@1.0.19";
/** A real npm registry fixture; no public registry or package cache is required. */
export async function registry(root: string) {
  const archives = new Map<string, Uint8Array>();
  await Deno.mkdir(`${root}/archive/package`, { recursive: true });
  for (const version of ["1.0.0", "2.0.0"]) {
    await Deno.writeTextFile(
      `${root}/archive/package/package.json`,
      JSON.stringify({ name: "batch-range-fixture", version }),
    );
    const out = await new Deno.Command("tar", {
      args: [
        "-czf",
        `${root}/${version}.tgz`,
        "-C",
        `${root}/archive`,
        "package",
      ],
      stdout: "piped",
      stderr: "piped",
    }).output();
    assertEquals(out.code, 0);
    archives.set(version, await Deno.readFile(`${root}/${version}.tgz`));
  }
  const server = Deno.serve(
    { hostname: "127.0.0.1", port: 0, onListen() {} },
    (request): Response => {
      const path = new URL(request.url).pathname;
      if (
        request.method === "POST" && path.startsWith("/-/npm/v1/security/")
      ) return Response.json({});
      if (path.endsWith(".tgz")) {
        const bytes = archives.get(path.slice(1, -4));
        return bytes
          ? new Response(new Uint8Array(bytes))
          : new Response("missing", { status: 404 });
      }
      if (path !== "/batch-range-fixture") {
        return new Response("missing", {
          status: 404,
        });
      }
      return Response.json({
        name: "batch-range-fixture",
        "dist-tags": { latest: "2.0.0" },
        time: {
          "1.0.0": "2020-01-01T00:00:00Z",
          "2.0.0": "2020-01-01T00:00:00Z",
        },
        versions: Object.fromEntries(
          [...archives.keys()].map((
            version,
          ) => [version, {
            name: "batch-range-fixture",
            version,
            dist: {
              tarball: `http://127.0.0.1:${server.addr.port}/${version}.tgz`,
            },
          }]),
        ),
      });
    },
  );
  return server;
}
