import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
  type CacheArtifact,
  cacheIntegrity,
  fillCacheHost,
} from "./cache_host.ts";
const bytes = new TextEncoder().encode("archive");
const hex = (value: Uint8Array) =>
  [...value].map((b) => b.toString(16).padStart(2, "0")).join("");
async function digest(algorithm: string, data: Uint8Array | string) {
  return new Uint8Array(
    await crypto.subtle.digest(
      algorithm,
      typeof data === "string"
        ? new TextEncoder().encode(data)
        : new Uint8Array(data),
    ),
  );
}
async function artifact(manager: "npm" | "composer"): Promise<CacheArtifact> {
  const url = manager === "npm"
    ? "https://registry.npmjs.org/demo/-/demo-1.0.0.tgz"
    : `https://api.github.com/repos/acme/demo/zipball/${"a".repeat(40)}`;
  const integrity = manager === "npm"
    ? `sha512-${btoa(String.fromCharCode(...await digest("SHA-512", bytes)))}`
    : `sha1-${hex(await digest("SHA-1", bytes))}`;
  return {
    manager,
    package: manager === "npm" ? "demo" : "acme/demo",
    version: "1.0.0",
    url,
    integrity,
    key: manager === "npm"
      ? `npm/${hex(await digest("SHA-256", `${url} ${integrity}`))}.tgz`
      : `composer/acme/demo/${hex(await digest("SHA-1", url))}.zip`,
  };
}
async function fixture(
  use: (
    root: string,
    options: Parameters<typeof fillCacheHost>[2],
  ) => Promise<void>,
) {
  const temp = await Deno.makeTempDir({ prefix: "native-cache-test-" });
  try {
    await use(`${temp}/cache`, {
      root: `${temp}/cache`,
      owner: Deno.uid()!,
      run: async (program, args, signal) => {
        const command = program === "sudo" ? args[1] : program;
        const argv = program === "sudo" ? args.slice(2) : args;
        const result = await new Deno.Command(command, {
          args: argv,
          signal,
          stdout: "piped",
          stderr: "null",
        }).output();
        if (!result.success) {
          throw new Error("Fixture filesystem command failed");
        }
        return new TextDecoder().decode(result.stdout);
      },
    });
  } finally {
    await Deno.remove(temp, { recursive: true });
  }
}
Deno.test("native host fills ComposerSHA1 and npmSHA512 archives and verifies cached hits without downloading again", async () => {
  await fixture(async (root, options) => {
    const artifacts = await Promise.all([
      artifact("composer"),
      artifact("npm"),
    ]);
    const headers: (string | null)[] = [];
    const opts = {
      ...options,
      fetch: ((_url, init) => {
        headers.push(new Headers(init?.headers).get("Authorization"));
        return Promise.resolve(new Response(bytes));
      }) as typeof fetch,
    };
    const input = {
      artifacts,
      max_bytes: 100000,
      github_token: "github-private-sentinel",
      deadline_ms: 10000,
    };
    const filled = await fillCacheHost(input, undefined, opts);
    assertEquals(filled.failures, {});
    assertEquals(
      Object.values(filled.hashes),
      Array(2).fill(`sha256-${hex(await digest("SHA-256", bytes))}`),
    );
    assertEquals(headers, ["Bearer github-private-sentinel", null]);
    assertEquals(
      await fillCacheHost({ ...input, github_token: null }, undefined, opts),
      filled,
    );
    assertEquals(headers.length, 2);
    assertEquals(
      await Deno.readTextFile(`${root}/.orbit-owned`),
      "orbit-package-cache-v1\n",
    );
    assertEquals(JSON.stringify(filled).includes("sentinel"), false);
  });
});
Deno.test("native host rejects checksum mismatch on download and on an inventory-matching cache hit", async () => {
  await fixture(async (root, options) => {
    const a = await artifact("composer");
    const input = {
      artifacts: [a],
      max_bytes: 100000,
      github_token: "private",
      deadline_ms: 10000,
    };
    const opts = {
      ...options,
      fetch: (() => Promise.resolve(new Response(bytes))) as typeof fetch,
    };
    const wrong = { ...a, integrity: `sha1-${"0".repeat(40)}` };
    assertEquals(
      (await fillCacheHost({ ...input, artifacts: [wrong] }, undefined, opts))
        .failures,
      { [a.key]: "Archive checksum mismatch" },
    );
    assertEquals((await fillCacheHost(input, undefined, opts)).failures, {});
    assertEquals(
      (await fillCacheHost({ ...input, artifacts: [wrong] }, undefined, opts))
        .hashes,
      {},
    );
    assertEquals(await Deno.readFile(`${root}/${a.key}`), bytes);
  });
});
Deno.test("native host quarantines corrupt owned archives and rejects unowned roots and symlinks", async () => {
  await fixture(async (root, options) => {
    const a = await artifact("composer");
    const input = {
      artifacts: [a],
      max_bytes: 100000,
      github_token: "private",
      deadline_ms: 10000,
    };
    const opts = {
      ...options,
      fetch: (() => Promise.resolve(new Response(bytes))) as typeof fetch,
    };
    await fillCacheHost(input, undefined, opts);
    await Deno.chmod(`${root}/${a.key}`, 0o644);
    await Deno.writeTextFile(`${root}/${a.key}`, "corrupt");
    assertEquals((await fillCacheHost(input, undefined, opts)).failures, {});
    const quarantined = Array.fromAsync(Deno.readDir(`${root}/quarantine`));
    assertEquals((await quarantined).length, 1);
    await Deno.remove(`${root}/.orbit-owned`);
    await assertRejects(
      () => fillCacheHost(input, undefined, opts),
      Error,
      "unowned",
    );
    await Deno.remove(root, { recursive: true });
    await Deno.symlink(root + "-foreign", root);
    await assertRejects(
      () => fillCacheHost(input, undefined, opts),
      Error,
      "unsafe",
    );
  });
});
Deno.test("native download errors never expose token text and unsafe redirects never receive credentials", async () => {
  await fixture(async (_root, options) => {
    const a = await artifact("composer"), token = "github-private-sentinel";
    const input = {
      artifacts: [a],
      max_bytes: 100000,
      github_token: token,
      deadline_ms: 10000,
    };
    const thrown = await fillCacheHost(input, undefined, {
      ...options,
      fetch: (() => {
        throw new Error(token);
      }) as typeof fetch,
    });
    assertEquals(thrown, { hashes: {}, failures: { [a.key]: "FillRefused" } });
    let calls = 0;
    const redirected = await fillCacheHost(input, undefined, {
      ...options,
      fetch: (() => {
        calls++;
        return Promise.resolve(
          new Response(null, {
            status: 302,
            headers: { location: "https://foreign.test/archive" },
          }),
        );
      }) as typeof fetch,
    });
    assertEquals(redirected.failures, { [a.key]: "Rejected archive redirect" });
    assertEquals(calls, 1);
    assertEquals(JSON.stringify({ thrown, redirected }).includes(token), false);
  });
});
Deno.test("native cache refuses malformed integrity and foreign archive identities before downloading", async () => {
  const a = await artifact("npm");
  await assertRejects(() => cacheIntegrity({ ...a, integrity: "sha512-YQ==" }));
  await assertRejects(() =>
    cacheIntegrity({ ...a, url: "https://foreign.test/archive" })
  );
  await assertRejects(() => cacheIntegrity({ ...a, key: "../foreign" }));
});

Deno.test("native filesystem commands receive the remaining overall deadline signal", async () => {
  await fixture(async (root, options) => {
    let commands = 0;
    const a = await artifact("composer");
    const opts = {
      ...options,
      run: async (program: string, args: string[], signal?: AbortSignal) => {
        assertEquals(signal instanceof AbortSignal, true);
        commands++;
        return await options!.run!(program, args, signal);
      },
      fetch: (() => Promise.resolve(new Response(bytes))) as typeof fetch,
    };
    const result = await fillCacheHost(
      {
        artifacts: [a],
        max_bytes: 100000,
        github_token: "private",
        deadline_ms: 10000,
      },
      undefined,
      opts,
    );
    assertEquals(result.failures, {});
    assertEquals(commands > 0, true);
    assertEquals(
      await Deno.readTextFile(`${root}/.orbit-owned`),
      "orbit-package-cache-v1\n",
    );
  });
});

Deno.test("native host removes each successful or refused download before starting the next archive", async () => {
  for (const corrupt of [false, true]) {
    await fixture(async (_root, options) => {
      let download = "", calls = 0;
      const artifacts = await Promise.all([
        artifact("composer"),
        artifact("npm"),
      ]);
      const result = await fillCacheHost(
        {
          artifacts,
          max_bytes: 100000,
          github_token: "private",
          deadline_ms: 10000,
        },
        undefined,
        {
          ...options,
          run: async (program, args, signal) => {
            if (program === "sha1sum") download = args.at(-1)!;
            return await options!.run!(program, args, signal);
          },
          fetch: (async () => {
            if (calls++ === 1) {
              assertEquals(download.includes("orbit-cache-fill-"), true);
              await assertRejects(
                () => Deno.stat(download),
                Deno.errors.NotFound,
              );
            }
            return new Response(corrupt && calls === 1 ? "corrupt" : bytes);
          }) as typeof fetch,
        },
      );
      assertEquals(calls, 2);
      assertEquals(Object.keys(result.hashes).length, corrupt ? 1 : 2);
      assertEquals(Object.keys(result.failures).length, corrupt ? 1 : 0);
    });
  }
});
