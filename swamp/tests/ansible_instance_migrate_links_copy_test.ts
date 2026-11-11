import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "file copy rewrites tracked, untracked and ignored absolute checkout links, keeps relative links and dependency contents, and never changes source links",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.migrate.retire = [];
      f.write();
      Deno.mkdirSync(`${f.checkout}/public`);
      Deno.mkdirSync(`${f.checkout}/storage/app/public`, { recursive: true });
      Deno.writeTextFileSync(`${f.checkout}/storage/app/public/file.txt`, "kept storage\n");
      const absolute = `${f.checkout}/storage/app/public`;
      Deno.symlinkSync(absolute, `${f.checkout}/public/storage`);
      Deno.writeTextFileSync(`${f.checkout}/.gitignore`, "vendor/\nnode_modules/\nstorage/\nignored-link\n.env\ndatabase/*.sqlite\n");
      git(f.checkout, "add", ".gitignore", "public/storage");
      git(f.checkout, "commit", "--quiet", "-m", "tracked absolute storage link");
      Deno.symlinkSync(absolute, `${f.checkout}/local package`);
      Deno.symlinkSync(absolute, `${f.checkout}/ignored-link`);
      Deno.symlinkSync("../storage/./app/public", `${f.checkout}/public/relative`);
      for (const tree of ["vendor/acme/installed", "node_modules/installed", "apps/web/vendor/acme/installed", "apps/web/node_modules/installed"]) {
        Deno.mkdirSync(`${f.checkout}/${tree}`, { recursive: true });
        Deno.symlinkSync(absolute, `${f.checkout}/${tree}/absolute`);
        Deno.symlinkSync("missing-dependency", `${f.checkout}/${tree}/broken`);
      }

      Deno.writeTextFileSync(`${f.checkout}/apps/web/composer.json`, "{}\n");
      Deno.writeTextFileSync(`${f.checkout}/apps/web/package.json`, "{}\n");
      for (const path of ["vendor/acme/package", "vendor/composer/package", "node_modules/package", "node_modules/@acme/package", "resources/views/vendor/storage"]) {
        Deno.mkdirSync(`${f.checkout}/${path.slice(0, path.lastIndexOf("/"))}`, { recursive: true });
        Deno.symlinkSync(absolute, `${f.checkout}/${path}`);
      }
      const bytes = new Deno.Command("python3", { args: ["-c", `
import os, sys
root = os.fsencode(sys.argv[1])
os.mkdir(root + b'/storage/app/public/data-\\xff')
with open(root + b'/storage/app/public/data-\\xff/file.txt', 'wb') as file:
    file.write(b'copied data')
os.symlink(root + b'/storage/app/public/data-\\xff', root + b'/public/link-\\xfe')
os.symlink(b'../storage/app/public/data-\\xff', root + b'/public/relative-\\xff')
`, f.checkout] }).outputSync();
      assert(bytes.success, new TextDecoder().decode(bytes.stderr));

      f.build();

      const workspace = `${f.rootfs}/srv/workspace`;
      for (const [path, target] of [["public/storage", "../storage/app/public"], ["local package", "storage/app/public"], ["ignored-link", "storage/app/public"],
        ["public/relative", "../storage/./app/public"], ["vendor/acme/package", "../../storage/app/public"], ["vendor/composer/package", "../../storage/app/public"],
        ["node_modules/package", "../storage/app/public"], ["node_modules/@acme/package", "../../storage/app/public"],
        ["resources/views/vendor/storage", "../../../storage/app/public"]]) {
        assertEquals(Deno.readLinkSync(`${workspace}/${path}`), target);
        assertEquals(Deno.readLinkSync(`${f.checkout}/${path}`), path === "public/relative" ? target : absolute);
        assertEquals(Deno.readTextFileSync(`${workspace}/${path}/file.txt`), "kept storage\n");
      }
      for (const tree of ["vendor/acme/installed", "node_modules/installed", "apps/web/vendor/acme/installed", "apps/web/node_modules/installed"]) {
        assertEquals(Deno.readLinkSync(`${workspace}/${tree}/absolute`), absolute);
        assertEquals(Deno.readLinkSync(`${workspace}/${tree}/broken`), "missing-dependency");
      }
      const byteCheck = new Deno.Command("python3", { args: ["-c", `
import os, sys
source, workspace = map(os.fsencode, sys.argv[1:])
assert os.readlink(source + b'/public/link-\\xfe') == source + b'/storage/app/public/data-\\xff'
assert os.readlink(workspace + b'/public/link-\\xfe') == b'../storage/app/public/data-\\xff'
assert os.readlink(workspace + b'/public/relative-\\xff') == b'../storage/app/public/data-\\xff'
assert os.path.isdir(workspace + b'/public/link-\\xfe')
`, f.checkout, workspace] }).outputSync();
      assert(byteCheck.success, new TextDecoder().decode(byteCheck.stderr));
      assertEquals(f.workspace("status", "--short", "--", "public/storage"), "M public/storage");
    } finally {
      f.cleanup();
    }
  },
});
