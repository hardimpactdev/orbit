import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { metricsCertificateSystem } from "./metrics_certificate_system.ts";
import type { SystemTransport } from "../node/system_command.ts";
async function fixture() {
  const root = await Deno.makeTempDir({ prefix: "orbit-metrics-cert-" });
  await Deno.mkdir(root + "/caddy");
  const control = root + "/control.json";
  await Deno.writeTextFile(
    control,
    JSON.stringify({ active: true, fail: false }),
  );
  const transport: SystemTransport = async (command) => {
    let source = command.args[4].replace(
      "BASE=pathlib.Path('/etc/caddy')",
      "BASE=pathlib.Path(" + JSON.stringify(root + "/caddy") + ")",
    ).replace(
      "pathlib.Path('/run/lock/orbit/caddy.lock')",
      "pathlib.Path(" + JSON.stringify(root + "/lock/caddy.lock") + ")",
    );
    source =
      `import os,subprocess,types,json,grp\nos.chown(${
        JSON.stringify(root + "/caddy")
      },0,${Deno.gid()})\ngrp.getgrnam=lambda _:types.SimpleNamespace(gr_gid=${Deno.gid()})\nreal_run=subprocess.run\ndef controlled(args,**kwargs):\n if args[0]=='/usr/bin/systemctl':\n  path=${
        JSON.stringify(control)
      }\n  state=json.load(open(path))\n  if args[1]=='is-active':return types.SimpleNamespace(returncode=0 if state['active'] else 3,stdout=b'active' if state['active'] else b'inactive')\n  fail=state['fail'];state['fail']=False;json.dump(state,open(path,'w'))\n  return types.SimpleNamespace(returncode=1 if fail else 0,stdout=b'')\n return real_run(args,**kwargs)\nsubprocess.run=controlled\n` +
      source;
    const child = new Deno.Command("/usr/bin/sudo", {
      args: ["-n", "--", "/usr/bin/python3", "-c", source],
      stdin: "piped",
      stdout: "piped",
      stderr: "null",
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(command.stdin));
    await writer.close();
    const result = await child.output();
    return {
      success: result.success,
      stdout: new TextDecoder().decode(result.stdout),
    };
  };
  const pair = async (name: string) => {
    const result = await new Deno.Command("/usr/bin/openssl", {
      args: [
        "req",
        "-x509",
        "-newkey",
        "rsa:2048",
        "-nodes",
        "-subj",
        "/CN=metrics.fixture",
        "-days",
        "1",
        "-keyout",
        root + "/" + name + ".key",
        "-out",
        root + "/" + name + ".pem",
      ],
      stdout: "null",
      stderr: "null",
    }).output();
    assertEquals(result.success, true);
    return {
      certificate: await Deno.readTextFile(root + "/" + name + ".pem"),
      key: await Deno.readTextFile(root + "/" + name + ".key"),
    };
  };
  return {
    root,
    apply: metricsCertificateSystem(transport),
    pair,
    control,
    current: root + "/caddy/orbit-metrics-cert-current",
    versions: root + "/caddy/orbit-metrics-cert-versions",
    close: async () => {
      await new Deno.Command("/usr/bin/sudo", {
        args: ["-n", "--", "/usr/bin/rm", "-rf", "--", root],
      }).output();
    },
  };
}
Deno.test("Metrics certificate publishes validates actual key pair repeats and restores the previous version", async () => {
  const f = await fixture();
  try {
    const one = await f.pair("one"), two = await f.pair("two");
    assertEquals(
      await f.apply({ operation: "publish", version: "aa", ...one }),
      { change: "created", previous: null },
    );
    assertEquals(
      await f.apply({ operation: "publish", version: "bb", ...one }),
      { change: "unchanged", previous: null },
    );
    const result = await f.apply({
      operation: "publish",
      version: "bb",
      ...two,
    });
    assertEquals(result, { change: "replaced", previous: f.versions + "/aa" });
    await f.apply({
      operation: "restore",
      change: "replaced",
      previous: result.previous!,
    });
    assertEquals(await Deno.readLink(f.current), f.versions + "/aa");
    assertEquals(
      (await Deno.stat(f.versions + "/aa/metrics.key")).mode! & 0o777,
      0o640,
    );
    await assertRejects(() => Deno.stat(f.versions + "/bb"));
    await f.apply({
      operation: "restore",
      change: "replaced",
      previous: result.previous!,
    });
    assertEquals(
      await Deno.readTextFile(f.versions + "/.orbit-owner"),
      "metrics-certificate\n",
    );
    assertEquals(
      await Deno.readTextFile(f.versions + "/aa/metrics.pem"),
      one.certificate,
    );
    await f.apply({ operation: "remove" });
    await assertRejects(() => Deno.lstat(f.current));
  } finally {
    await f.close();
  }
});
Deno.test("Metrics certificate refusal and failed Caddy reload preserve the selected key pair", async () => {
  const f = await fixture();
  try {
    const one = await f.pair("one"), two = await f.pair("two");
    await f.apply({ operation: "publish", version: "aa", ...one });
    await assertRejects(
      () =>
        f.apply({
          operation: "publish",
          version: "bb",
          certificate: one.certificate,
          key: two.key,
        }),
      Error,
      "metrics.certificate_failed",
    );
    assertEquals(await Deno.readLink(f.current), f.versions + "/aa");
    await Deno.writeTextFile(
      f.control,
      JSON.stringify({ active: true, fail: true }),
    );
    await assertRejects(
      () => f.apply({ operation: "publish", version: "bb", ...two }),
      Error,
      "metrics.certificate_failed",
    );
    assertEquals(await Deno.readLink(f.current), f.versions + "/aa");
    await assertRejects(() => Deno.stat(f.versions + "/bb"));
    await assertRejects(
      () =>
        f.apply({ operation: "restore", change: "replaced", previous: f.root }),
      Error,
      "metrics.certificate_failed",
    );
  } finally {
    await f.close();
  }
});
Deno.test("Metrics certificate private material stays on stdin and uncertain output stays bounded", async () => {
  const secret = "private-key-sentinel";
  const action = {
    operation: "publish" as const,
    version: "aa",
    certificate: "certificate",
    key: secret,
  };
  const api = metricsCertificateSystem((command) => {
    assertEquals(command.args.some((part) => part.includes(secret)), false);
    assertEquals(JSON.parse(command.stdin!).key, secret);
    return Promise.resolve({
      success: true,
      stdout: '{"change":"created","previous":null}',
    });
  });
  assertEquals(await api(action), { change: "created", previous: null });
  for (const value of [null, "not json", { unknown: true }]) {
    await assertRejects(
      () =>
        metricsCertificateSystem(() =>
          Promise.resolve({
            success: true,
            stdout: typeof value === "string" ? value : JSON.stringify(value),
          })
        )(action),
      Error,
      "metrics.certificate_outcome_unknown",
    );
  }
  await assertRejects(
    () =>
      metricsCertificateSystem(() => {
        throw new Error(secret);
      })(action),
    Error,
    "metrics.certificate_outcome_unknown",
  );
});

Deno.test("Metrics access revocation reloads under the same publication lock and fails closed", async () => {
  const f = await fixture();
  try {
    assertEquals(await f.apply({ operation: "revoke_access" }), {
      revoked: true,
    });
    await Deno.writeTextFile(
      f.control,
      JSON.stringify({ active: true, fail: true }),
    );
    await assertRejects(
      () => f.apply({ operation: "revoke_access" }),
      Error,
      "metrics.certificate_failed",
    );
    await Deno.writeTextFile(
      f.control,
      JSON.stringify({ active: false, fail: false }),
    );
    await assertRejects(
      () => f.apply({ operation: "revoke_access" }),
      Error,
      "metrics.certificate_failed",
    );
    await assertRejects(() => Deno.stat(f.versions));
  } finally {
    await f.close();
  }
});
