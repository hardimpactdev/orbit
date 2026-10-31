import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { type CloudImage, imageCloud } from "./upcloud_image_api.ts";
import type { CloudRequest } from "./upcloud_sweep.ts";
const id = (n: number) =>
  `00000000-0000-4000-8000-${String(n).padStart(12, "0")}`;
const stamp = "20261031050000";
const input = {
  stamp,
  name: `tvm-image-${stamp}`,
  identity: `sha256:${"a".repeat(64)}`,
  progress: { resources: { builder: { uuid: id(1) } }, template_uuid: null },
} as unknown as CloudImage;
const label = (value = stamp) => [{ key: "orbit-task-image", value }];
function server(n: number, value = stamp, title = input.name) {
  return {
    uuid: id(n),
    title,
    hostname: title,
    state: "started",
    labels: { label: label(value) },
    ip_addresses: { ip_address: [] },
    storage_devices: { storage_device: [{ storage: id(50) }] },
  };
}
Deno.test("lost-create cleanup removes all exact title and stamp duplicates while preserving foreign servers", async () => {
  const rows = [
    server(1),
    server(2),
    server(3, "other"),
    server(4, stamp, "another-title"),
  ];
  const deleted: string[] = [];
  const request: CloudRequest = (method, path) => {
    if (path.startsWith("server?")) {
      return Promise.resolve({ servers: { server: rows } });
    }
    const uuid = path.split("/")[1].split("?")[0];
    const row = rows.find((row) => row.uuid === uuid);
    if (method === "GET") return Promise.resolve(row ? { server: row } : null);
    if (path.endsWith("/stop")) row!.state = "stopped";
    if (method === "DELETE") {
      deleted.push(uuid);
      rows.splice(rows.indexOf(row!), 1);
    }
    return Promise.resolve({});
  };
  await imageCloud(input, request).remove("builder");
  assertEquals(deleted, [id(1), id(2)]);
  assertEquals(rows.map((row) => row.uuid), [id(3), id(4)]);
});
Deno.test("promote and discard refuse an unlabelled or differently stamped same-title template", async () => {
  for (const labels of [[], label("other")]) {
    const writes: string[] = [];
    const request: CloudRequest = (method, path) => {
      if (method !== "GET") writes.push(path);
      return Promise.resolve({
        storages: {
          storage: [{
            uuid: id(20),
            title: `orbit-task-base-${stamp}-unverified`,
            access: "private",
            labels,
          }],
        },
      });
    };
    const cloud = imageCloud(input, request);
    await assertRejects(() => cloud.promote());
    await assertRejects(() => cloud.discard());
    assertEquals(writes, []);
  }
});
Deno.test("prune preserves unlabelled, mismatched and pinned templates", async () => {
  const template = (n: number, day: string, labels: unknown[]) => ({
    uuid: id(n),
    access: "private",
    title: `orbit-task-base-202610${day}050000`,
    labels,
  });
  const rows = [
    template(1, "31", label("20261031050000")),
    template(2, "30", label("20261030050000")),
    template(3, "29", label("20261029050000")),
    template(4, "28", []),
    template(5, "27", label("other")),
    template(6, "26", label("20261026050000")),
  ];
  const deleted: string[] = [];
  const request: CloudRequest = (method, path) => {
    if (path === "storage/template") {
      return Promise.resolve({ storages: { storage: rows } });
    }
    const uuid = path.split("/")[1];
    if (method === "GET") {
      return Promise.resolve({
        storage: rows.find((row) => row.uuid === uuid),
      });
    }
    deleted.push(uuid);
    return Promise.resolve({});
  };
  await imageCloud(input, request).prune([id(3)]);
  assertEquals(deleted, [id(6)]);
});
Deno.test("templatize labels only its returned UUID and promotion retains the ownership label", async () => {
  const row = server(1);
  row.state = "stopped";
  let template: Record<string, unknown> | undefined;
  const request: CloudRequest = (method, path, body) => {
    if (path === "server?label=orbit-task-image") {
      return Promise.resolve({ servers: { server: [row] } });
    }
    if (path.startsWith("server/")) return Promise.resolve({ server: row });
    if (path === "storage/template") {
      return Promise.resolve({
        storages: { storage: template ? [template] : [] },
      });
    }
    if (path.endsWith("/templatize")) {
      assertEquals(body, {
        storage: { title: `orbit-task-base-${stamp}-unverified` },
      });
      template = {
        uuid: id(20),
        access: "private",
        ...(body!.storage as object),
      };
      return Promise.resolve({ storage: { uuid: id(20) } });
    }
    assertEquals(method, "PUT");
    assertEquals(path, `storage/${id(20)}`);
    Object.assign(template!, body!.storage);
    return Promise.resolve({});
  };
  const cloud = imageCloud(input, request);
  assertEquals(await cloud.templatize(), id(20));
  assertEquals(template!.labels, label());
  assertEquals(await cloud.promote(), id(20));
  assertEquals(template!.labels, [{
    key: "orbit.recipe",
    value: input.identity,
  }, ...label()]);
});
