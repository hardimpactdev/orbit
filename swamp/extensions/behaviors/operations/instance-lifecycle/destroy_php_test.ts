import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { publishDestroyPhp } from "./destroy_php.ts";
const owner = () =>
  Promise.resolve({ user: "orbit", group: "apps", home: "/home/orbit" });
Deno.test("private PHP publication uses current callback-local render and actual account", async () => {
  let inside = false;
  const writes: unknown[] = [];
  const gateway = {
    withPrivateMaterial: async (
      use: (data: Record<string, unknown>) => Promise<void>,
    ) => {
      inside = true;
      try {
        await use({
          php: {
            node_id: 9,
            user: "orbit",
            configurations: {
              "8.5":
                "user=__ORBIT_ACCOUNT_USER__\ngroup=__ORBIT_ACCOUNT_GROUP__\nchdir=__ORBIT_ACCOUNT_HOME__",
            },
          },
        });
      } finally {
        inside = false;
      }
    },
  };
  assertEquals(
    await publishDestroyPhp(9, gateway, undefined, owner, (input) => {
      assertEquals(inside, true);
      writes.push(input);
      return Promise.resolve({ ready: true });
    }),
    { ready: true },
  );
  assertEquals(writes, [{
    node_id: 9,
    configurations: { "8.5": "user=orbit\ngroup=apps\nchdir=/home/orbit" },
  }]);
});
Deno.test("empty PHP render is still published to remove the last owned pool", async () => {
  let published = false;
  await publishDestroyPhp(
    9,
    {
      withPrivateMaterial: (use) =>
        use({ php: { node_id: 9, user: "orbit", configurations: {} } }),
    },
    undefined,
    owner,
    () => {
      published = true;
      return Promise.resolve({ ready: true });
    },
  );
  assertEquals(published, true);
});
Deno.test("missing private PHP callback cannot report successful withdrawal", async () => {
  await assertRejects(
    () =>
      publishDestroyPhp(
        9,
        { withPrivateMaterial: () => Promise.resolve() },
        undefined,
        owner,
      ),
    Error,
    "app-dev.php_publication_failed",
  );
});
Deno.test("foreign private Node or account refuses before pool publication", async () => {
  let effects = 0;
  for (
    const data of [{ node_id: 17, user: "orbit" }, {
      node_id: 9,
      user: "other",
    }]
  ) {
    await assertRejects(() =>
      publishDestroyPhp(
        9,
        {
          withPrivateMaterial: (use) =>
            use({ php: { ...data, configurations: {} } }),
        },
        undefined,
        owner,
        () => {
          effects++;
          return Promise.resolve({ ready: true });
        },
      )
    );
  }
  assertEquals(effects, 0);
});

Deno.test("default native account lookup supplies the real home without an agent delivery pin", async () => {
  const reply = await new Deno.Command("/usr/bin/id", {
    args: ["-un"],
    stdout: "piped",
  }).output();
  const user = new TextDecoder().decode(reply.stdout).trim();
  let rendered = "";
  await publishDestroyPhp(
    9,
    {
      withPrivateMaterial: (use) =>
        use({
          php: {
            node_id: 9,
            user,
            configurations: { "8.5": "chdir=__ORBIT_ACCOUNT_HOME__" },
          },
        }),
    },
    undefined,
    undefined,
    (raw) => {
      rendered = (raw as { configurations: Record<string, string> })
        .configurations["8.5"];
      return Promise.resolve({ ready: true });
    },
  );
  assertEquals(rendered, "chdir=" + Deno.env.get("HOME"));
});
