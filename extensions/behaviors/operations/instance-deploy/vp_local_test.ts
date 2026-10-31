import { assertEquals } from "jsr:@std/assert@1.0.19";
import { userInfo } from "node:os";
import { vpLocal } from "./vp_local.ts";

async function tar(files: { name: string; content: string; mode?: number }[]) {
  const program =
    "import sys,json,tarfile,io,base64,hashlib\np=json.load(sys.stdin);b=io.BytesIO()\nwith tarfile.open(fileobj=b,mode='w:gz') as t:\n for f in p:\n  v=f['content'].encode();i=tarfile.TarInfo(f['name']);i.size=len(v);i.mode=f.get('mode',493);t.addfile(i,io.BytesIO(v))\nv=b.getvalue();print(json.dumps({'bytes':base64.b64encode(v).decode(),'sha256':hashlib.sha256(v).hexdigest(),'integrity':'sha512-'+base64.b64encode(hashlib.sha512(v).digest()).decode()}))";
  const child = new Deno.Command("/usr/bin/python3", {
    args: ["-I", "-c", program],
    stdin: "piped",
    stdout: "piped",
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(new TextEncoder().encode(JSON.stringify(files)));
  await writer.close();
  const result = await child.output();
  assertEquals(result.success, true);
  return JSON.parse(new TextDecoder().decode(result.stdout)) as {
    bytes: string;
    sha256: string;
    integrity: string;
  };
}
async function setup() {
  const base = await Deno.makeTempDir(),
    store = base + "/store",
    home = base + "/home";
  await Deno.mkdir(store);
  await Deno.mkdir(home);
  const node = await tar([{
    name: "node-v24.22.0-linux-x64/bin/node",
    content: "#!/bin/sh\nexit 0\n",
  }]);
  const npm = await tar([{
    name: "package/package.json",
    content: JSON.stringify({
      name: "npm",
      version: "11.12.0",
      bin: { npm: "bin/npm-cli.js", npx: "bin/npm-cli.js" },
    }),
  }, { name: "package/bin/npm-cli.js", content: "#!/usr/bin/env node\n" }]);
  const nodeUrl = "https://nodejs.org/dist/v24.22.0/",
    packageUrl = "https://registry.example.test/npm/-/npm.tgz";
  const fixtures: Record<string, unknown> = {
    [nodeUrl + "SHASUMS256.txt"]: btoa(
      node.sha256 + "  node-v24.22.0-linux-x64.tar.gz\n",
    ),
    [nodeUrl + "node-v24.22.0-linux-x64.tar.gz"]: node.bytes,
    "https://registry.example.test/npm/11.12.0": {
      authorization: "Bearer private-token",
      bytes: btoa(
        JSON.stringify({
          name: "npm",
          version: "11.12.0",
          dist: { integrity: npm.integrity, tarball: packageUrl },
        }),
      ),
    },
    [packageUrl]: { authorization: "Bearer private-token", bytes: npm.bytes },
  };
  const options = {
    user: userInfo().username,
    platform: "linux" as const,
    architecture: "x86_64" as const,
    deadline: Date.now() + 60000,
    fixture: {
      home,
      opt: base + "/opt",
      root_uid: userInfo().uid,
      root_gid: userInfo().gid,
      unprivileged: true,
      fixture_only: true,
      fixtures,
    },
  };
  const environment = {
    store,
    node_version: "24.22.0",
    package_manager: "npm",
    package_manager_version: "11.12.0",
    package_manager_hash: null,
  };
  const npmrc =
    "registry=https://registry.example.test/\n//registry.example.test/:_authToken=private-token\n";
  return {
    base,
    store,
    home,
    options,
    environment,
    fixtures,
    npmrc,
    nodeUrl,
    packageUrl,
  };
}

Deno.test("native VP installs pinned archives with scoped credentials and prunes only ledger-owned unused runtimes", async () => {
  const f = await setup();
  try {
    assertEquals(
      await vpLocal("inspect", { environments: [f.environment] }, f.options),
      { installed: [false] },
    );
    const result = await vpLocal("install", {
      environment: f.environment,
      npmrc: f.npmrc,
    }, f.options);
    assertEquals("verified" in result, true, JSON.stringify(result));
    assertEquals(JSON.stringify(result).includes("private-token"), false);
    assertEquals(
      await vpLocal("inspect", { environments: [f.environment] }, f.options),
      { installed: [true] },
    );
    await Deno.mkdir(f.store + "/operator-owned");
    await Deno.writeTextFile(
      f.store + "/.orbit-recipe-node.json",
      JSON.stringify({ version: f.environment.node_version }),
    );
    assertEquals(
      await vpLocal("prune", { store: f.store, references: [] }, f.options),
      { removed: ["package_manager/npm/11.12.0"] },
    );
    assertEquals(
      (await Deno.stat(f.store + "/js_runtime/node/24.22.0/bin/node")).isFile,
      true,
    );
    assertEquals(
      (await Deno.stat(f.store + "/operator-owned")).isDirectory,
      true,
    );
  } finally {
    await Deno.remove(f.base, { recursive: true });
  }
});
Deno.test("native VP rejects corrupt checksums and archive escapes without publishing a manager", async () => {
  for (const fault of ["checksum", "archive"] as const) {
    const f = await setup();
    try {
      if (fault === "checksum") {
        f.fixtures[f.nodeUrl + "SHASUMS256.txt"] = btoa(
          "a".repeat(64) + "  node-v24.22.0-linux-x64.tar.gz\n",
        );
      } else {
        const invalid = await tar([{
          name: "package/../../escaped",
          content: "unsafe",
        }]);
        f.fixtures[f.packageUrl] = {
          authorization: "Bearer private-token",
          bytes: invalid.bytes,
        };
        f.fixtures["https://registry.example.test/npm/11.12.0"] = {
          authorization: "Bearer private-token",
          bytes: btoa(
            JSON.stringify({
              name: "npm",
              version: "11.12.0",
              dist: { integrity: invalid.integrity, tarball: f.packageUrl },
            }),
          ),
        };
      }
      assertEquals(
        "error_code" in
          await vpLocal("install", {
            environment: f.environment,
            npmrc: f.npmrc,
          }, f.options),
        true,
      );
      assertEquals(
        [...Deno.readDirSync(f.base)].some((v) => v.name === "escaped"),
        false,
      );
      assertEquals(
        await vpLocal("inspect", { environments: [f.environment] }, f.options),
        { installed: [false] },
      );
    } finally {
      await Deno.remove(f.base, { recursive: true });
    }
  }
});
Deno.test("native VP reads private Project configuration locally and rejects linked stores", async () => {
  const f = await setup();
  try {
    const project = f.base + "/project";
    await Deno.mkdir(project);
    await Deno.writeTextFile(
      project + "/package.json",
      JSON.stringify({ dependencies: { "vite-plus": "1.0.0" } }),
    );
    await Deno.writeTextFile(project + "/.npmrc", "TOKEN=private-project\n");
    const value = await vpLocal("project", { path: project }, f.options);
    assertEquals(value.npmrc, "\nTOKEN=private-project\n");
    await Deno.symlink(f.store, f.base + "/linked");
    assertEquals(
      "error_code" in
        await vpLocal("inspect", {
          environments: [{ ...f.environment, store: f.base + "/linked" }],
        }, f.options),
      true,
    );
  } finally {
    await Deno.remove(f.base, { recursive: true });
  }
});
