import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { prepareEnrollmentSettings } from "./enrollment_settings.ts";
const input = {
  root: null,
  user: "candidate",
  create_missing: true,
  protected_roots: ["/etc", "/run"],
  managed_checkouts: ["/srv/checkout"],
};
const transport = () => Promise.reject(new Error("unexpected system action"));
Deno.test("enrollment settings uses the admitted candidate's actual home and group", async () => {
  let prepared: unknown;
  assertEquals(
    await prepareEnrollmentSettings(input, transport, undefined, (user) => {
      assertEquals(user, "candidate");
      return Promise.resolve({
        user,
        group: "builders",
        home: "/srv/candidate",
      });
    }, (raw) => {
      prepared = raw;
      return Promise.resolve({ ok: true });
    }),
    { settings_applied: true },
  );
  assertEquals(prepared, {
    root: "/srv/candidate/apps",
    user: "candidate",
    group: "builders",
    create_missing: true,
  });
});
Deno.test("enrollment settings refuses protected, hidden, ancestor and managed roots before filesystem effects", async () => {
  for (
    const root of [
      "/etc/apps",
      "/",
      "/srv",
      "/srv/candidate",
      "/srv/candidate/.ssh/apps",
      "/srv/checkout/apps",
    ]
  ) {
    let effects = 0;
    const outcome = await prepareEnrollmentSettings(
      { ...input, root },
      transport,
      undefined,
      () =>
        Promise.resolve({
          user: "candidate",
          group: "builders",
          home: "/srv/candidate",
        }),
      () => {
        effects++;
        return Promise.resolve({ ok: true });
      },
    );
    assertEquals(outcome, { error_code: "node.settings_root_failed" });
    assertEquals(effects, 0);
  }
});
Deno.test("enrollment settings preserves missing-account and existing-root refusal without adoption", async () => {
  assertEquals(
    await prepareEnrollmentSettings(
      input,
      transport,
      undefined,
      () => Promise.resolve(null),
    ),
    { error_code: "node.settings_root_failed" },
  );
  let creates: unknown;
  assertEquals(
    await prepareEnrollmentSettings(
      { ...input, create_missing: false },
      transport,
      undefined,
      () =>
        Promise.resolve({
          user: "candidate",
          group: "builders",
          home: "/srv/candidate",
        }),
      (raw) => {
        creates = raw;
        return Promise.resolve({
          ok: false,
          error_code: "node.storage_root_unusable",
        });
      },
    ),
    { error_code: "node.settings_root_failed" },
  );
  assertEquals((creates as { create_missing: boolean }).create_missing, false);
});
Deno.test("cancelled enrollment settings does not discover accounts or change storage", async () => {
  let reads = 0;
  await assertRejects(() =>
    prepareEnrollmentSettings(input, transport, AbortSignal.abort(), () => {
      reads++;
      return Promise.resolve(null);
    })
  );
  assertEquals(reads, 0);
});
