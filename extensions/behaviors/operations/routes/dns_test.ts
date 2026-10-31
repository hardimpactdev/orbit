import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { dnsLaunch, publishPrivateDns, runPrivateDns } from "./dns.ts";
import { stub } from "jsr:@std/testing@1.0.17/mock";
const input = {
  node_id: 1,
  records: "",
  catalog: "{}",
  digest: "44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a",
};
Deno.test("DNS rejects a changed catalog or invalid JSON before invoking the fixed program", async () => {
  let calls = 0;
  for (const catalog of ["{", '{"new":true}']) {
    await assertRejects(() =>
      publishPrivateDns({ ...input, catalog }, () => {
        calls++;
        return Promise.resolve({});
      })
    );
  }
  assertEquals(calls, 0);
});
Deno.test("DNS confirms exactly the admitted digest and preserves only bounded outcomes", async () => {
  assertEquals(
    await publishPrivateDns(input, () =>
      Promise.resolve({
        outcome: "published",
        data: { ready: true, digest: input.digest, stdout: "PRIVATE" },
        log: "PRIVATE",
      })),
    {
      outcome: "published",
      data: { ready: true, digest: input.digest },
      ready: true,
    },
  );
  assertEquals(
    await publishPrivateDns(input, () =>
      Promise.resolve({
        outcome: "refused",
        data: { error_code: "private_dns.listener_not_ready", log: "PRIVATE" },
      })),
    { error_code: "private_dns.listener_not_ready" },
  );
  await assertRejects(() =>
    publishPrivateDns(
      input,
      () =>
        Promise.resolve({
          outcome: "published",
          data: { ready: true, digest: "a".repeat(64) },
        }),
    )
  );
  await assertRejects(() =>
    publishPrivateDns(
      input,
      () => Promise.resolve({ outcome: "published", data: { ready: true } }),
    )
  );
});
Deno.test("DNS program is fixed local sudo with a hard timeout, data only on stdin", async () => {
  const launch = dnsLaunch();
  assertEquals(launch.command, "/usr/bin/sudo");
  assertEquals(launch.args.slice(0, 9), [
    "-n",
    "--",
    "/usr/bin/timeout",
    "--signal=TERM",
    "--kill-after=5s",
    "60s",
    "/usr/bin/python3",
    "-I",
    "-c",
  ]);
  let received = "";
  using _command = stub(
    Deno as unknown as {
      Command: (command: string, options: Deno.CommandOptions) => unknown;
    },
    "Command",
    function (command: string, options: Deno.CommandOptions) {
      assertEquals(command, launch.command);
      assertEquals(options.args, launch.args);
      assertEquals(options.stderr, "null");
      return {
        spawn() {
          return {
            stdin: new WritableStream({
              write(bytes) {
                received += new TextDecoder().decode(bytes);
              },
            }),
            stdout: new ReadableStream({
              start(controller) {
                controller.enqueue(
                  new TextEncoder().encode(
                    JSON.stringify({
                      outcome: "published",
                      data: { ready: true, digest: input.digest },
                    }),
                  ),
                );
                controller.close();
              },
            }),
            status: Promise.resolve({ success: true }),
            kill() {
              throw new Error("Unexpected kill");
            },
          };
        },
      };
    },
  );
  await publishPrivateDns(input, runPrivateDns);
  assertEquals(JSON.parse(received), {
    records: input.records,
    catalog: input.catalog,
  });
});
