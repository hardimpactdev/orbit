/**
 * The full move: every phase up to the processes and the seed, then retirement.
 * The harness is swamp/tests/_support/instance_migrate.ts.
 */
import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { createHash } from "node:crypto";
import { migrateResult, writtenFailure } from "../extensions/behaviors/operations/instance-dev-command/migrate_result.ts";
import { has, hasAnsible, Task, git, exists, fixture, gitFiles, migrationProcesses, migrationSchedules } from "./_support/instance_migrate.ts";

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
for (const count of [0, 1]) {
  Deno.test({
    name: `migration counts ${count} files that Git does not ignore beside a large ignored tree`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        Deno.mkdirSync(`${f.checkout}/vendor/generated`);
        for (let i = 0; i < 1000; i++) Deno.writeTextFileSync(`${f.checkout}/vendor/generated/file-${i}`, "ignored dependency\n");
        if (count === 1) Deno.writeTextFileSync(`${f.checkout}/draft.txt`, "not ignored\n");
        assertEquals(git(f.checkout, "ls-files", "--others", "--exclude-standard"), count === 1 ? "draft.txt" : "");
        f.buildBatched();
        const receipt = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/untracked-files.json`));
        assertEquals(receipt, { untracked_files_copied: count });
        // The ignored tree, .env and SQLite still enter the unchanged inclusive archive.
        assertEquals(f.read("vendor/generated/file-999"), "ignored dependency\n");
        assertEquals(f.read("database/database.sqlite"), "sqlite\n");
        assert(f.read(".env").includes("APP_KEY="));
        if (count === 1) assertEquals(f.read("draft.txt"), "not ignored\n");
        const nodeFiles = [...Deno.readDirSync(`${f.folder}/mirror`)].map((entry) => entry.name);
        assertEquals(nodeFiles.filter((name) => name.endsWith(".git")), ["7.git"]);
        assertEquals(nodeFiles.filter((name) => name.endsWith(".git.json")).length, 1);
        assertEquals(nodeFiles.length, 2);
      } finally {
        f.cleanup();
      }
    },
  });
}
Deno.test({
  name: "untracked non-ignored files and nested repository leaves migrate with an exact archived file count",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      // Include a newline in a name: manifests must use NUL separators, not lines.
      Deno.writeTextFileSync(`${f.checkout}/generated\nwayfinder.ts`, "generated output\n");
      const nested = `${f.checkout}/nested`;
      Deno.mkdirSync(nested);
      git(nested, "init", "--quiet");
      for (let i = 0; i < 31; i++) Deno.writeTextFileSync(`${nested}/file-${i}`, `nested ${i}\n`);
      const leaves = (path: string): number => [...Deno.readDirSync(path)].reduce(
        (count, entry) => count + (entry.isDirectory ? leaves(`${path}/${entry.name}`) : 1), 0);
      const nestedLeaves = leaves(nested);
      f.buildBatched();
      assertEquals(f.read("generated\nwayfinder.ts"), "generated output\n");
      assertEquals(f.read("vendor/pkg/a.php"), "<?php // dependency\n");
      assertEquals(f.read("database/database.sqlite"), "sqlite\n");
      for (let i = 0; i < 31; i++) assertEquals(f.read(`nested/file-${i}`), `nested ${i}\n`);
      const receipt = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/untracked-files.json`));
      assertEquals(receipt.untracked_files_copied, 1 + nestedLeaves);
      const completed = f.run("retire");
      assert(completed.ok, completed.output);
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers["instance-7"].status, "Running");
      assertEquals(Deno.readTextFileSync(`${f.retained()}/generated\nwayfinder.ts`), "generated output\n");
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "source status changes after export refuse retirement and preserve every later file",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      Deno.writeTextFileSync(`${f.checkout}/.gitignore`, Deno.readTextFileSync(`${f.checkout}/.gitignore`) + "*.log\ncache/\n");
      git(f.checkout, "add", ".gitignore");
      git(f.checkout, "commit", "--quiet", "-m", "ignore generated output");
      git(f.checkout, "update-ref", "refs/remotes/origin/main", "HEAD");
      Deno.writeTextFileSync(`${f.checkout}/draft.txt`, "copied version\n");
      f.buildBatched();
      Deno.writeTextFileSync(`${f.checkout}/draft.txt`, "late edited version\n");
      Deno.writeTextFileSync(`${f.checkout}/late.txt`, "late untracked work\n");
      Deno.writeTextFileSync(`${f.checkout}/runtime.log`, "late ignored output\n");
      Deno.mkdirSync(`${f.checkout}/cache`);
      Deno.writeTextFileSync(`${f.checkout}/cache/generated`, "late ignored cache\n");
      const completed = f.run(["processes", "seed", "retire"]);
      assert(!completed.ok, completed.output);
      assertEquals(f.result().error_code, "instance.migrate_git_source_changed");
      const commit = f.workspace("rev-parse", "HEAD");
      const copied = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/untracked-files.json`));
      const projected = JSON.parse(migrateResult(
        { instance_id: f.container.instance_id, container: f.container, route_mutations: null },
        { container: "succeeded", quiesce: "succeeded", workspace: "succeeded", files: "succeeded",
          processes: "succeeded", seed: "succeeded", agent: "skipped", t3: "skipped", register: "skipped",
          route: "skipped", retire: "failed", restore_route: "skipped", restore: "skipped" },
        { status: "succeeded", outputs: { stdout: `orbit-commit=${commit}` } }, null, {...copied, ...f.result()},
      ).stdout);
      assertEquals([projected.ready, projected.moved, projected.restored], [false, true, false]);
      assertEquals(f.read("draft.txt"), "copied version\n");
      for (const path of ["late.txt", "runtime.log", "cache/generated"]) assert(!exists(`${f.rootfs}/srv/workspace/${path}`));
      assertEquals(projected.error_code, "instance.migrate_git_source_changed");
      const retained = f.checkout;
      assertEquals(Deno.readTextFileSync(`${retained}/draft.txt`), "late edited version\n");
      assertEquals(Deno.readTextFileSync(`${retained}/late.txt`), "late untracked work\n");
      assertEquals(Deno.readTextFileSync(`${retained}/runtime.log`), "late ignored output\n");
      assertEquals(Deno.readTextFileSync(`${retained}/cache/generated`), "late ignored cache\n");
      assert(exists(f.migrate.awake.path));
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "a retained default copies its unpushed commit and changed, staged, untracked, deleted and ignored files",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("default", true);
    try {
      // A retained default can copy its local files; its checkout is not scheduled for deletion yet.
      f.migrate.retire = [];
      f.write();
      const head = git(f.checkout, "rev-parse", "HEAD");
      const status = git(f.checkout, "status", "--porcelain");
      f.buildBatched();
      // The Route shows the wake page from the first phase on.
      assert(!exists(f.migrate.awake.path));
      assertEquals(f.result(), { commit: head });
      // A standalone repository at the checkout's own commit, which no origin has.
      assertEquals(f.workspace("rev-parse", "HEAD"), head);
      assertEquals(f.workspace("symbolic-ref", "--short", "HEAD"), "main");
      assert(Deno.statSync(`${f.rootfs}/srv/workspace/.git`).isDirectory);
      assertEquals(f.read("local.php"), "<?php // committed, never pushed\n");
      assertEquals(f.read("index.php"), "<?php echo 2;\n");
      assertEquals(f.read("staged.php"), "<?php // staged\n");
      assertEquals(f.workspace("diff", "--cached", "--name-only"), git(f.checkout, "diff", "--cached", "--name-only"));
      assertEquals(f.read("note.txt"), "untracked\n");
      assertEquals(f.read("vendor/pkg/a.php"), "<?php // dependency\n");
      assertEquals(f.read("database/database.sqlite"), "sqlite\n");
      assert(!exists(`${f.rootfs}/srv/workspace/old.php`));
      // .env came as it was; the path into the checkout points into the workspace now.
      assertEquals(f.read(".env"), `APP_KEY=base64:kept\nDB_DATABASE=${f.rootfs}/srv/workspace/database/database.sqlite\n`);
      // The way to the database server came with the container.
      assertEquals(f.state()["network-acls"].find((acl: {name: string}) => acl.name === f.container.acl).egress, f.container.acl_rules.egress);
      assertEquals(f.ufw().map((line) => JSON.parse(line).comment), ["orbit:instance-7-database"]);
      // The move uses create's resource limits and full root device, even for an image-based migration.
      assertEquals(f.state().containers["instance-7"].config, ["security.idmap.isolated=true", "limits.cpu=4", "limits.memory=8GiB", "limits.processes=4096"]);
      assertEquals(f.state().containers["instance-7"].devices.filter((device: string) => device.startsWith("root,")), [
        "root,type=disk", "root,path=/", "root,pool=orbit-instances", "root,size=40GiB",
      ]);
      // Its NIC accepts only its own IPv4 and MAC address, as a create's.
      assertEquals(f.state().containers["instance-7"].devices.filter((device: string) => device.includes("_filtering=")), [
        "eth0,security.ipv4_filtering=true", "eth0,security.mac_filtering=true",
      ]);
      // Container staging is removed; the private source snapshot stays until retirement or restore.
      assertEquals([...Deno.readDirSync(`${f.rootfs}/srv`)].map((entry) => entry.name), ["workspace"]);
      const nodeFiles = [...Deno.readDirSync(`${f.folder}/mirror`)].map((entry) => entry.name);
      assertEquals(nodeFiles.filter((name) => name.endsWith(".git")), ["7.git"]);
      const snapshots = nodeFiles.filter((name) => name.endsWith(".git.json"));
      assertEquals(snapshots.length, 1);
      assertEquals(Deno.statSync(`${f.folder}/mirror/${snapshots[0]}`).mode! & 0o777, 0o600);
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
      assertEquals(Deno.readTextFileSync(`${f.checkout}/.env`), `APP_KEY=base64:kept\nDB_DATABASE=${f.checkout}/database/database.sqlite\n`);

      const retire = f.run("retire");
      assert(retire.ok, retire.output);
      assert(exists(f.checkout));
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers["instance-7"].status, "Running");
      const again = f.run("retire");
      assert(again.ok, again.output);
      assertMatch(again.output, /changed=0\s/);
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "a linked worktree migrates before its active owner and preserves every file and sibling registration",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("feature");
    try {
      const sibling = f.apps + "/sibling";
      git(f.main, "worktree", "add", "--quiet", "-b", "sibling", sibling);
      const otherCommit = git(f.main, "commit-tree", git(f.main, "rev-parse", "HEAD^{tree}"), "-p", "HEAD", "-m", "other local-only branch");
      git(f.main, "update-ref", "refs/heads/other-local", otherCommit);
      Deno.writeTextFileSync(f.main + "/index.php", "shared stash");
      git(f.main, "stash", "push", "--quiet", "-m", "shared-store");
      Deno.writeTextFileSync(f.checkout + "/untracked-kept.txt", "untracked survives");
      const admin = git(f.checkout, "rev-parse", "--absolute-git-dir");
      const owner = gitFiles(f.main, admin);
      const other = gitFiles(sibling);
      const child = gitFiles(f.checkout);
      const head = git(f.checkout, "rev-parse", "HEAD");
      f.buildBatched();
      assertEquals(f.read("untracked-kept.txt"), "untracked survives");
      assertEquals(f.read("vendor/pkg/a.php"), "<?php // dependency\n");
      assert(f.read(".env").includes("APP_KEY=base64:kept"));
      const retired = f.run("retire");
      assert(retired.ok, retired.output);
      assertEquals(gitFiles(f.main), owner);
      assertEquals(gitFiles(sibling), other);
      assert(!exists(admin));
      assert(!exists(f.checkout));
      for (const [name, digest] of Object.entries(child)) {
        if (name === "/.git") continue;
        assertEquals(createHash("sha256").update(Deno.readFileSync(f.retained() + name)).digest("hex"), digest);
      }
      assertEquals(git(f.retained(), "rev-parse", "HEAD"), head);
      assertEquals(git(f.retained(), "symbolic-ref", "--short", "HEAD"), "feature");
      assert(!exists(f.retained() + "/.git/objects/info/alternates"));
      assert(!git(f.main, "worktree", "list", "--porcelain").includes("worktree " + f.checkout + "\n"));
      assert(git(f.main, "worktree", "list", "--porcelain").includes("worktree " + sibling + "\n"));
      const repeat = f.run("retire");
      assert(repeat.ok, repeat.output);
      assertEquals(gitFiles(f.main), owner);
    } finally { f.cleanup(); }
  },
});
for (const blocker of ["unstaged", "staged", "unpushed", "identity", "nested"] as const) {
  Deno.test({
    name: "linked worktree preflight handles " + blocker + " without touching its active owner or sibling",
    ignore: !hasAnsible,
    fn() {
      const f = fixture("feature");
      try {
        const sibling = blocker === "nested" ? f.checkout + "/nested-sibling" : f.apps + "/sibling";
        git(f.main, "worktree", "add", "--quiet", "-b", "sibling", sibling);
        if (blocker === "unstaged" || blocker === "staged") {
          Deno.writeTextFileSync(f.checkout + "/index.php", "tracked edit");
          if (blocker === "staged") git(f.checkout, "add", "index.php");
        } else if (blocker === "unpushed") git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "not pushed");
        else if (blocker === "identity") {
          const admin = git(f.checkout, "rev-parse", "--absolute-git-dir");
          Deno.writeTextFileSync(admin + "/gitdir", sibling + "/.git\n");
        }
        const before = gitFiles(f.apps);
        const refused = f.run("container");
        if (["unstaged", "staged", "unpushed"].includes(blocker)) {
          assert(refused.ok, refused.output);
          f.build();
          assertEquals(f.workspace("rev-parse", "HEAD"), git(f.checkout, "rev-parse", "HEAD"));
          if (blocker !== "unpushed") assertEquals(f.read("index.php"), "tracked edit");
          assertEquals(gitFiles(f.apps), before);
          return;
        }
        assert(!refused.ok, refused.output);
        assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
        assertEquals(gitFiles(f.apps), before);
        assert(exists(f.migrate.awake.path));
        assertEquals(f.state().containers ?? {}, {});
        assert(!exists(f.checkout + ".orbit-retired-git"));
        if (blocker === "nested") {
          assert(f.result().error_message.includes(sibling));
          const retirement = f.run("retire");
          assert(!retirement.ok, retirement.output);
          assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
          assert(f.result().error_message.includes(sibling));
          assertEquals(gitFiles(f.apps), before);
          assertEquals(git(sibling, "symbolic-ref", "--short", "HEAD"), "sibling");
        }
      } finally { f.cleanup(); }
    },
  });
}
Deno.test({
  name: "linked retirement interrupted before unregister fails explicitly on repeat",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("feature");
    try {
      const admin = git(f.checkout, "rev-parse", "--absolute-git-dir");
      f.buildBatched();
      const file = f.folder + "/tasks/instance-migrate-retire.yml";
      const original = Deno.readTextFileSync(file);
      Deno.writeTextFileSync(file, original.replace("shutil.rmtree(private)", "raise OSError('fixture: interrupted before unregister')"));
      const first = f.run("retire");
      assert(!first.ok, first.output);
      assertEquals(f.result().error_code, "instance.migrate_retire_failed");
      assert(!exists(f.checkout));
      assert(exists(f.retained() + "/.git/index"));
      assert(exists(admin));
      const before = gitFiles(f.apps);
      Deno.writeTextFileSync(file, original);
      const repeat = f.run("retire");
      assert(!repeat.ok, repeat.output);
      assertEquals(f.result().error_code, "instance.migrate_retire_failed");
      assert(f.result().error_message.includes("Incomplete linked retirement"));
      assertEquals(gitFiles(f.apps), before);
      assert(exists(admin));
    } finally { f.cleanup(); }
  },
});
// The last worktree of a moved default plans the owner's old checkout with common_of: its own registration is not "another" worktree.
for (const blocker of [null, "prunable", "sibling", "locked-prunable", "sibling-before-retire", "owner-change"] as const) {
  Deno.test({
    name: blocker === null || blocker === "prunable"
      ? `the last worktree moves into a standalone workspace and retires its owner checkout in the same batch${blocker ? " past a prunable entry whose folder is gone" : ""}`
      : `the owner of the last worktree still refuses retirement for ${blocker}`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture("feature");
      try {
        f.migrate.retire.push({ path: f.main, mirror: `${f.folder}/mirror/7.git`, common_of: f.checkout });
        f.write();
        const retainedOf = (path: string) =>
          `${f.checkout}.orbit-retired-git/${createHash("sha256").update(new TextEncoder().encode(path)).digest("hex")}`;
        // Registered elsewhere, like the /tmp worktrees of a real owner: the folder can be removed without Git.
        const stale = `${f.folder}/stale-worktree`;
        if (blocker === "prunable" || blocker === "locked-prunable") {
          git(f.main, "worktree", "add", "--quiet", "-b", "stale", stale);
          if (blocker === "locked-prunable") git(f.main, "worktree", "lock", stale);
          Deno.removeSync(stale, { recursive: true });
        }
        if (blocker === "sibling") git(f.main, "worktree", "add", "--quiet", "-b", "sibling", `${f.apps}/sibling`);
        if (blocker === "owner-change") Deno.writeTextFileSync(`${f.main}/index.php`, "<?php echo 'owner edit';\n");
        if (blocker === "owner-change" || blocker === "sibling" || blocker === "locked-prunable") {
          const before = gitFiles(f.apps);
          const refused = f.run("container");
          assert(!refused.ok, refused.output);
          assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
          const other = blocker === "sibling" ? `${f.apps}/sibling` : stale;
          assert(f.result().error_message.includes(blocker === "owner-change"
            ? 'Changed files (1 total; first 20): [" M index.php"]' : `Other worktrees (1 total; first 20): ["${other}"]`));
          if (blocker === "owner-change") assert(f.result().error_message.includes("Other worktrees (0 total; first 20)"));
          assertEquals(gitFiles(f.apps), before);
          assert(exists(f.migrate.awake.path));
          assertEquals(f.state().containers ?? {}, {});
          return;
        }
        const head = git(f.checkout, "rev-parse", "HEAD");
        f.build();
        // The workspace is a repository of its own on the recorded branch: no gitfile, no alternates, complete objects.
        const workspace = `${f.rootfs}/srv/workspace`;
        assert(Deno.statSync(`${workspace}/.git`).isDirectory);
        assertEquals(f.workspace("rev-parse", "--path-format=absolute", "--git-common-dir"), `${workspace}/.git`);
        assert(!exists(`${workspace}/.git/objects/info/alternates`));
        f.workspace("fsck", "--full", "--strict");
        assertEquals(f.workspace("rev-parse", "HEAD"), head);
        assertEquals(f.workspace("symbolic-ref", "--short", "HEAD"), "feature");
        assertEquals(f.read("index.php"), "<?php echo 2;\n");
        if (blocker === "sibling-before-retire") {
          // A worktree added to the owner after the container and quiesce checks still blocks at retirement.
          const sibling = `${f.apps}/sibling`;
          git(f.main, "worktree", "add", "--quiet", "-b", "sibling", sibling);
          const owner = gitFiles(f.main);
          const refused = f.run("retire");
          assert(!refused.ok, refused.output);
          assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
          assert(f.result().error_message.includes(`Other worktrees (1 total; first 20): ["${sibling}"]`));
          // Cutover has happened: the container stays the Instance, the owner stays intact at its path.
          assertEquals(f.state().containers["instance-8"].status, "Running");
          assertEquals(gitFiles(f.main), owner);
          assertEquals(git(sibling, "symbolic-ref", "--short", "HEAD"), "sibling");
          assert(!exists(retainedOf(f.main)));
          return;
        }
        const retire = f.run("retire");
        assert(retire.ok, retire.output);
        assertEquals(f.state().containers["instance-8"].status, "Running");
        // Nothing is deleted: both old checkouts are retained beside the worktree's path, each a usable repository.
        assert(!exists(f.checkout));
        assert(!exists(f.main));
        assertEquals(git(retainedOf(f.checkout), "rev-parse", "HEAD"), head);
        assertEquals(git(retainedOf(f.checkout), "rev-parse", "--path-format=absolute", "--git-common-dir"), `${retainedOf(f.checkout)}/.git`);
        assertEquals(git(retainedOf(f.main), "symbolic-ref", "--short", "HEAD"), "main");
        assert(Deno.statSync(`${retainedOf(f.main)}/.git`).isDirectory);
        // The moving worktree's registration is gone; a prunable entry stays in the retained .git, never pruned.
        assertEquals(git(retainedOf(f.main), "worktree", "list", "--porcelain").match(/^worktree /gm)?.length, blocker ? 2 : 1);
        assertEquals(f.workspace("rev-parse", "HEAD"), head);
      } finally {
        f.cleanup();
      }
    },
  });
}
Deno.test({
  name: "a clean standalone non-default checkout moves with T3 and leaves an unrelated default repository intact",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("feature", false, false);
    try {
      f.migrate.retire.push({ path: f.main, mirror: `${f.folder}/mirror/7.git`, common_of: f.checkout });
      f.write();
      const head = git(f.checkout, "rev-parse", "HEAD");
      f.build();
      assertEquals(f.workspace("rev-parse", "HEAD"), head);
      assertEquals(f.workspace("symbolic-ref", "--short", "HEAD"), "feature");
      assert(Deno.statSync(`${f.rootfs}/srv/workspace/.git`).isDirectory);
      assertEquals(f.state().containers["instance-8"].added.t3, {
        type: "proxy", settings: ["listen=tcp:10.44.0.7:3800", "connect=tcp:10.253.0.8:3773", "nat=true"],
      });
      const retire = f.run("retire");
      assert(retire.ok, retire.output);
      assert(!exists(f.checkout));
      assert(exists(`${f.main}/.git`));
      assertEquals(f.state().containers["instance-8"].status, "Running");
    } finally {
      f.cleanup();
    }
  },
});
for (const failing of [false, true]) {
  Deno.test({
    name: failing
      ? "a failed clear of the old path's caches warns and the move goes on; a store of another driver than file stays"
      : "a moved Laravel checkout clears its config, route, event, compiled and view caches and its file cache store as orbit",
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        // PHP in the container: it records each call, names the cache store's driver and can fail `config:clear`.
        // Beside the checkout: the Incus boundary maps paths under /tmp into the container.
        const calls = `${f.checkout}.php-calls`;
        const php = `${f.checkout}.php`;
        Deno.writeTextFileSync(php, `#!/bin/sh
if [ "$1" = -r ]; then echo "$(pwd) -r" >> '${calls}'; echo ${failing ? "redis" : "file"}; exit 0; fi
echo "$(pwd) $*" >> '${calls}'
[ "$2" = config:clear ] && exit ${failing ? 1 : 0}
exit 0
`);
        Deno.chmodSync(php, 0o755);
        f.container.php = php;
        Deno.writeTextFileSync(`${f.checkout}/artisan`, "<?php\n");
        f.write();
        f.build();
        const lines = Deno.readTextFileSync(calls).trim().split("\n");
        assert(lines.every((line) => line.startsWith(`${f.rootfs}/srv/workspace `)), lines.join("\n"));
        const tasks = ["config:clear", "route:clear", "event:clear", "view:clear", "clear-compiled"].map((task) => `artisan ${task} --no-interaction`);
        assertEquals(lines.map((line) => line.slice(line.indexOf(" ") + 1)),
          [...tasks, "-r", ...(failing ? [] : ["artisan cache:clear --no-interaction"])]);
        const receipt = `${f.folder}/run/cache-clear.json`;
        if (failing) {
          assertEquals(JSON.parse(Deno.readTextFileSync(receipt)), {warnings: [{code: "instance.migrate_cache_clear_failed",
            message: "Laravel caches from the old path were not all cleared: config:clear. Run php artisan optimize:clear in the Instance."}]});
        } else assert(!exists(receipt));
      } finally {
        f.cleanup();
      }
    },
  });
}
for (const broken of [false, true]) {
  Deno.test({
    name: broken
      ? "a Git failure while listing ignored files removes no tool cache and warns, and the move goes on"
      : "a move removes only ignored files in cache folders that name the old path",
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const named = `phar://${f.checkout}/vendor/phpstan/phpstan/phpstan.phar/stubs/a.stub`;
        Deno.writeTextFileSync(`${f.checkout}/.gitignore`, "vendor/\nnode_modules/\n.env\ndatabase/*.sqlite\nstorage/framework/cache/*\nstorage/logs/\n");
        const files: Record<string, string> = {
          "storage/framework/cache/phpstan/cache/PHPStan/a.php": named,
          "storage/framework/cache/rector/b.php": named,
          "storage/framework/cache/tracked.php": named,
          "storage/framework/cache/plain/c.php": "no path here\n",
          "storage/logs/laravel.log": named,
          "vendor/pkg/cache/d.php": named,
          // Untracked but not ignored, under a cache folder: a file of the work, never a build output.
          "app/cache/e.php": named,
          "node_modules/x/cache/f.php": named,
          "outside/target.php": named,
        };
        for (const [path, content] of Object.entries(files)) {
          Deno.mkdirSync(`${f.checkout}/${path.slice(0, path.lastIndexOf("/"))}`, { recursive: true });
          Deno.writeTextFileSync(`${f.checkout}/${path}`, content);
        }
        // A link under a cache folder whose target names the path: neither the link nor its target is read or removed.
        Deno.symlinkSync("../../../outside/target.php", `${f.checkout}/storage/framework/cache/link.php`);
        git(f.checkout, "add", ".gitignore");
        git(f.checkout, "add", "-f", "storage/framework/cache/tracked.php");
        git(f.checkout, "commit", "--quiet", "-m", "caches");
        git(f.checkout, "update-ref", "refs/remotes/origin/main", "HEAD");
        if (broken) {
          // A broken index makes `git ls-files` exit non-zero; the next task puts it back for the steps after.
          const file = `${f.folder}/tasks/instance-migrate-phase.yml`;
          const tasks = parse(Deno.readTextFileSync(file)) as Task[];
          const at = tasks.findIndex((task) => task.name === "Remove the tool caches built at the old path");
          const index = `${f.rootfs}/srv/workspace/.git/index`;
          tasks.splice(at + 1, 0, { name: "Restore the index", when: "phase == 'files'", "ansible.builtin.command": { argv: ["mv", `${index}.saved`, index] } });
          tasks.splice(at, 0, { name: "Break the index", when: "phase == 'files'",
            "ansible.builtin.shell": { cmd: `mv '${index}' '${index}.saved' && echo broken > '${index}'` } });
          Deno.writeTextFileSync(file, stringify(tasks));
        }
        f.build();
        const kept = (path: string) => exists(`${f.rootfs}/srv/workspace/${path}`);
        for (const path of ["storage/framework/cache/tracked.php", "storage/framework/cache/plain/c.php", "storage/logs/laravel.log", "vendor/pkg/cache/d.php",
          "app/cache/e.php", "node_modules/x/cache/f.php", "storage/framework/cache/link.php", "outside/target.php"]) {
          assert(kept(path), path);
        }
        const receipt = `${f.folder}/run/tool-cache-clear.json`;
        if (broken) {
          assert(kept("storage/framework/cache/phpstan/cache/PHPStan/a.php"));
          assert(kept("storage/framework/cache/rector/b.php"));
          assertEquals(JSON.parse(Deno.readTextFileSync(receipt)).warnings[0].code, "instance.migrate_tool_cache_clear_failed");
        } else {
          assert(!kept("storage/framework/cache/phpstan/cache/PHPStan/a.php"));
          assert(!kept("storage/framework/cache/rector/b.php"));
          assert(!exists(receipt));
        }
      } finally {
        f.cleanup();
      }
    },
  });
}
Deno.test({
  name: "a failed move removes its container, its ACL, its firewall rule and its mirror, and the checkout serves as it was",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const status = git(f.checkout, "status", "--porcelain");
      const head = git(f.checkout, "rev-parse", "HEAD");
      f.build();
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assertEquals(f.state().containers, {});
      assertEquals(f.state()["network-acls"].filter((acl: {name: string}) => acl.name !== "orbitinst0-egress"), []);
      assertEquals(f.ufw(), []);
      assert(!exists(f.container.mirror));
      assert(!exists(f.rootfs));
      assert(exists(f.migrate.awake.path));
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
      assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
      // The restore keeps the result of the phase that failed before it, and a second restore finds nothing to remove.
      assertEquals(f.result(), { commit: head });
      const again = f.run("restore");
      assert(again.ok, again.output);
      assertMatch(again.output, /changed=0\s/);
      // The same command moves the Instance again, from a new container.
      f.build();
      assertEquals(f.workspace("rev-parse", "HEAD"), head);
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "a move starts with a new container: one that an earlier failed move left goes first",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace"]) assert(f.run(phase).ok);
      Deno.writeTextFileSync(`${f.rootfs}/srv/workspace/leftover.txt`, "from the failed move\n");
      f.build();
      assert(!exists(`${f.rootfs}/srv/workspace/leftover.txt`));
      assertEquals(f.read("index.php"), "<?php echo 2;\n");
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "a database server that listens is reached from the container",
  ignore: !hasAnsible || !has("timeout"),
  async fn() {
    const f = fixture();
    const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
    const accepting = (async () => {
      for await (const connection of listener) connection.close();
    })();
    try {
      f.migrate.databases.push({ host: "127.0.0.1", port: (listener.addr as Deno.NetAddr).port });
      f.write();
      const done = await new Promise<void>((resolve, reject) => {
        try {
          f.build();
          resolve();
        } catch (error) {
          reject(error);
        }
      });
      assertEquals(done, undefined);
    } finally {
      listener.close();
      await accepting.catch(() => {});
      f.cleanup();
    }
  },
});
for (const blocker of ["changes", "worktree", "commits", "stashes"] as const) {
  Deno.test({
    name: `retirement rechecks ${blocker} created after preflight and leaves the container and source intact`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.buildBatched();
        const path = blocker === "changes" ? `${f.checkout}/index.php` : `${f.apps}/late-worktree`;
        if (blocker === "changes") Deno.writeTextFileSync(path, "late edit\n");
        else if (blocker === "worktree") git(f.checkout, "worktree", "add", "--quiet", "--detach", path);
        else if (blocker === "commits") git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "late commit");
        else {
          Deno.writeTextFileSync(`${f.checkout}/late-stash.txt`, "late stash\n");
          git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
        }
        const refs = git(f.checkout, "show-ref");
        const state = f.state();
        const retire = f.run("retire");
        assert(!retire.ok, retire.output);
        const code = blocker === "worktree" ? "instance.migrate_retirement_blocked" : "instance.migrate_git_source_changed";
        assertEquals(f.result().error_code, code);
        const detail = blocker === "changes" ? "index.php" : blocker === "worktree" ? path : blocker === "commits" ? '"commits": 1' : "Stashes: 1";
        assert(f.result().error_message.includes(blocker === "worktree" ? detail : "All data stays"), f.result().error_message);
        assertEquals(git(f.checkout, "show-ref"), refs);
        assertEquals(f.result().preflight_refused, false);
        assert(exists(f.migrate.awake.path), "The container Route must serve after a retirement refusal.");
        assert(exists(f.checkout));
        if (["changes", "worktree"].includes(blocker)) assert(exists(path));
        assertEquals(f.state(), state);
        assertEquals(f.state().containers["instance-7"].status, "Running");
      } finally {
        f.cleanup();
      }
    },
  });
}
for (const [desired, kind] of [["enabled", "current"], ["disabled", "agent"], ["enabled", "direct"]] as const) {
  Deno.test({
    name: `Schedule migration repoints a ${kind} timer with ${desired} intent only after successful cutover`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const schedules = migrationSchedules(f, desired, kind);
        f.build();
        assertEquals(schedules.host()[schedules.schedule.timer], { state: "stopped", enabled: false });
        assertEquals(Deno.readTextFileSync(schedules.servicePath), schedules.service);
        assertEquals(Deno.readTextFileSync(schedules.timerPath), schedules.timer);
        assertEquals(Deno.readTextFileSync(schedules.scriptPath), schedules.script);
        assertEquals(Deno.readTextFileSync(schedules.callbackPath), schedules.callback);
        assert(exists(f.checkout));
        const retired = f.run("retire");
        assert(retired.ok, retired.output);
        assert(!exists(schedules.servicePath));
        assert(!exists(schedules.timerPath));
        const service = Deno.readTextFileSync(f.rootfs + "/etc/systemd/system/" + schedules.schedule.service);
        const timer = Deno.readTextFileSync(f.rootfs + "/etc/systemd/system/" + schedules.schedule.timer);
        assert(service.includes("User=web\n"));
        assert(service.includes("WorkingDirectory=/srv/workspace\n"));
        assert(timer.includes("Persistent=false\n"));
        assert(timer.includes("OnCalendar=" + schedules.schedule.calendar));
        assert(!exists(schedules.scriptPath));
        assert(!exists(schedules.callbackPath));
        assertEquals(schedules.host()[schedules.schedule.timer], {state: "stopped", enabled: false});
        assert(!exists(f.checkout));
        schedules.assertUnrelated();
      } finally { f.cleanup(); }
    },
  });
}
for (const desired of ["enabled", "disabled"] as const) {
  Deno.test({
    name: `Schedule rollback restores exact observed timer activation when its ${desired} intent differs`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const schedules = migrationSchedules(f, desired, "agent");
        // Actual activation can differ from the recorded intent; rollback restores what was running.
        schedules.states[schedules.schedule.timer] = { state: desired === "enabled" ? "started" : "stopped", enabled: desired !== "enabled" };
        schedules.writeStates();
        const before = schedules.host();
        f.build();
        assertEquals(schedules.host()[schedules.schedule.timer], { state: "stopped", enabled: false });
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(f.state().containers, {});
        assertEquals(schedules.host(), before);
        assertEquals(Deno.readTextFileSync(schedules.servicePath), schedules.service);
        assertEquals(Deno.readTextFileSync(schedules.timerPath), schedules.timer);
        assertEquals(Deno.readTextFileSync(schedules.scriptPath), schedules.script);
        assertEquals(Deno.readTextFileSync(schedules.callbackPath), schedules.callback);
        assert(exists(f.checkout));
        assert(exists(f.migrate.awake.path));
        schedules.assertUnrelated();
      } finally { f.cleanup(); }
    },
  });
}
Deno.test({
  name: "Schedule cutover resumes the timer even when a late Git retirement check refuses",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const schedules = migrationSchedules(f, "enabled", "direct");
      f.build();
      Deno.writeTextFileSync(`${f.checkout}/index.php`, "late edit kept for recovery\n");
      const retired = f.run("retire");
      assert(!retired.ok, retired.output);
      assertEquals(f.result().error_code, "instance.migrate_git_source_changed");
      assertEquals(f.result().preflight_refused, false);
      assertEquals(f.state().containers["instance-7"].status, "Running");
      assertEquals(schedules.host()[schedules.schedule.timer], { state: "stopped", enabled: false });
      assert(!exists(schedules.servicePath));
      assert(!exists(schedules.timerPath));
      const service = Deno.readTextFileSync(f.rootfs + "/etc/systemd/system/" + schedules.schedule.service);
      assert(service.includes("User=web\n"));
      assert(!service.includes(f.checkout));
      assert(!exists(schedules.scriptPath));
      assert(!exists(schedules.callbackPath));
      assertEquals(Deno.readTextFileSync(`${f.checkout}/index.php`), "late edit kept for recovery\n");
      assert(exists(f.migrate.awake.path));
      schedules.assertUnrelated();
    } finally { f.cleanup(); }
  },
});
for (const presetDesired of ["running", "stopped"] as const) {
  Deno.test({
    name: `Process migration carries a ${presetDesired} vp-dev through the shared units and retires only moved host files`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const host = migrationProcesses(f, presetDesired);
        const prepared = f.run("container");
        assert(prepared.ok, prepared.output);
        assertEquals(host()[f.migrate.processes[0].name], { state: "started", enabled: true });
        const stopped = f.run("quiesce");
        assert(stopped.ok, stopped.output);
        assertEquals(host()[f.migrate.processes[0].name], { state: "stopped", enabled: false });
        for (const phase of ["workspace", "files"]) {
          const result = f.run(phase);
          assert(result.ok, result.output);
        }
        const installed = f.run("processes");
        assert(installed.ok, installed.output);
        assert(!installed.output.includes("process-secret"));
        for (const unit of f.container.processes) {
          assertEquals(Deno.readTextFileSync(`${f.rootfs}/etc/systemd/system/${unit.name}`), unit.content);
          assertEquals(Deno.readTextFileSync(`${f.rootfs}${unit.environment}`), `QUEUE_TOKEN=process-secret-${unit.id}\n`);
          const enabled = exists(`${f.rootfs}/etc/systemd/system/multi-user.target.wants/${unit.name}`);
          assertEquals(enabled, unit.desired === "running");
          const starts = f.state().systemctl.filter((args: string[]) =>
            ["start", "restart", "enable"].includes(args[0]) && args.includes(unit.name));
          assertEquals(starts.length > 0, unit.desired === "running");
        }
        const retired = f.run("retire");
        assert(retired.ok, retired.output);
        for (const unit of f.migrate.processes) assert(!exists(unit.environment_directory));
        assertEquals(host()[f.migrate.processes[0].name], { state: "stopped", enabled: false });
        assertEquals(host()["orbit-process-999-other.service"], { state: "started", enabled: true });
        assertEquals(Deno.readTextFileSync(`${f.folder}/host/unrelated/orbit-process-999-other.service`), "unrelated unit\n");
        assertEquals(Deno.readTextFileSync(`${f.folder}/host/unrelated/vars.env`), "OTHER_TOKEN=kept\n");
      } finally { f.cleanup(); }
    },
  });

  Deno.test({
    name: `Process migration restores a ${presetDesired} vp-dev only after removing its failed container`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const host = migrationProcesses(f, presetDesired);
        f.build();
        const installed = f.run("processes");
        assert(installed.ok, installed.output);
        assertEquals(host()[f.migrate.processes[0].name], { state: "stopped", enabled: false });
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(host()[f.migrate.processes[0].name], { state: "started", enabled: true });
        assertEquals(host()[f.migrate.processes[1].name], { state: "stopped", enabled: false });
        assertEquals(f.state().containers, {});
        for (const unit of f.migrate.processes) {
          assertEquals(Deno.readTextFileSync(unit.path), "old unit\n");
          assertEquals(Deno.readTextFileSync(`${unit.environment_directory}/old.env`), "OLD_TOKEN=kept\n");
          assertEquals(host()[unit.name], { state: unit.desired === "running" ? "started" : "stopped", enabled: unit.desired === "running" });
        }
        assertEquals(host()["orbit-process-999-other.service"], { state: "started", enabled: true });
        assert(exists(f.checkout));
      } finally { f.cleanup(); }
    },
  });
}
Deno.test({
  name: "migration carries every divergent unpushed local branch without changing the source",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const pushed = git(f.checkout, "rev-parse", "HEAD");
      git(f.checkout, "checkout", "--quiet", "-b", "spare", pushed);
      Deno.writeTextFileSync(f.checkout + "/spare-only", "local spare content");
      git(f.checkout, "add", "spare-only");
      git(f.checkout, "commit", "--quiet", "-m", "local spare");
      git(f.checkout, "branch", "another");
      git(f.checkout, "checkout", "--quiet", "main");
      git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "divergent main");
      const refs = git(f.checkout, "for-each-ref", "--format=%(refname) %(objectname)", "refs/heads/");
      const before = gitFiles(f.apps);
      f.build();
      assertEquals(f.workspace("for-each-ref", "--format=%(refname) %(objectname)", "refs/heads/"), refs);
      assertEquals(f.workspace("show", "spare:spare-only"), "local spare content");
      assertEquals(f.workspace("rev-parse", "HEAD"), git(f.checkout, "rev-parse", "HEAD"));
      assertEquals(gitFiles(f.apps), before);
      f.workspace("fsck", "--full", "--strict");
    } finally { f.cleanup(); }
  },
});
for (const detached of [false, true]) {
  Deno.test({
    name: detached ? "a detached selected HEAD still refuses as a changed branch" : "a checkout with no remote tracking refs carries every local commit",
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        if (detached) {
          git(f.checkout, "checkout", "--quiet", "--detach");
          git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "detached local commit");
          const result = f.run("container");
          assert(!result.ok, result.output);
          assertEquals(f.result().error_code, "instance.migrate_branch_changed");
          assert(exists(f.migrate.awake.path));
        } else {
          git(f.checkout, "update-ref", "-d", "refs/remotes/origin/main");
          f.build();
          assertEquals(f.workspace("rev-parse", "HEAD"), git(f.checkout, "rev-parse", "HEAD"));
        }
      } finally { f.cleanup(); }
    },
  });
}
Deno.test({
  name: "migration carries duplicate stash messages and every ordered stash entry and content",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const name of ["first", "second"]) {
        Deno.writeTextFileSync(f.checkout + "/" + name + ".txt", name);
        git(f.checkout, "stash", "push", "--quiet", "--include-untracked", "-m", "duplicate");
      }
      const stashes = git(f.checkout, "stash", "list", "--format=%H%x00%gs");
      const before = gitFiles(f.apps);
      f.build();
      assertEquals(f.workspace("stash", "list", "--format=%H%x00%gs"), stashes);
      for (const [index, name] of [[0, "second"], [1, "first"]] as const) {
        assertEquals(f.workspace("show", "stash@{" + index + "}^3:" + name + ".txt"), name);
      }
      assertEquals(gitFiles(f.apps), before);
      f.workspace("stash", "apply", "stash@{1}");
      assertEquals(f.read("first.txt"), "first");
      f.workspace("fsck", "--full", "--strict");
    } finally { f.cleanup(); }
  },
});
Deno.test({
  name: "a clean default with pushed commits and no stash retains all Git metadata and repeats without changes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      git(f.checkout, "branch", "spare");
      const head = git(f.checkout, "rev-parse", "HEAD");
      f.build();
      const retire = f.run("retire");
      assert(retire.ok, retire.output);
      assert(!exists(f.checkout));
      assert(exists(f.migrate.awake.path));
      assertEquals(git(f.retained(), "rev-parse", "refs/heads/main"), head);
      assertEquals(git(f.retained(), "rev-parse", "refs/heads/spare"), head);
      const again = f.run("retire");
      assert(again.ok, again.output);
      assertMatch(again.output, /changed=0\s/);
    } finally {
      f.cleanup();
    }
  },
});
for (const common of [false, true]) {
  Deno.test({
    name: `a ${common ? "common_of" : "direct"} repository refuses a worktree created before its final preflight`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.build();
        if (common) {
          // The linked checkout has gone; its common Git directory identifies this retirement candidate by equality.
          f.migrate.retire[0].common_of = `${f.apps}/already-retired`;
          f.write();
          const playbook = `${f.folder}/instance-migrate.yml`;
          const plays = parse(Deno.readTextFileSync(playbook)) as Task[];
          for (const play of plays) {
            if (play.hosts === "migrate_node") (play.vars as Task).migrate_common = `${f.checkout}/.git`;
          }
          Deno.writeTextFileSync(playbook, stringify(plays));
        }
        const late = `${f.apps}/unmanaged-late`;
        const file = `${f.folder}/tasks/instance-migrate-retire.yml`;
        const tasks = parse(Deno.readTextFileSync(file)) as Task[];
        // The batch check has passed; the shared per-checkout preflight still refuses this blocker.
        tasks.unshift({
          name: "Fixture creates an unmanaged worktree before final preflight",
          "ansible.builtin.command": { argv: ["git", "-C", f.checkout, "worktree", "add", "--quiet", "--detach", late] },
          changed_when: true,
        });
        Deno.writeTextFileSync(file, stringify(tasks));
        const head = git(f.checkout, "rev-parse", "HEAD");
        const state = f.state();
        const retire = f.run("retire");
        assert(!retire.ok, retire.output);
        assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
        assert(f.result().error_message.includes(late), f.result().error_message);
        assertEquals(f.result().preflight_refused, false);
        assert(exists(`${f.checkout}/.git`));
        assert(exists(late));
        assertEquals(git(late, "rev-parse", "HEAD"), head);
        assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
        assertEquals(f.state(), state);
        assert(exists(f.migrate.awake.path));
      } finally {
        f.cleanup();
      }
    },
  });
}
Deno.test({
  name: "a worktree created after retirement listing refuses the source snapshot before retention",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.build();
      const late = `${f.apps}/after-final-listing`;
      const file = `${f.folder}/tasks/instance-migrate-retire.yml`;
      const tasks = parse(Deno.readTextFileSync(file)) as Task[];
      const retain = tasks.findIndex((task) => task.name === "Recheck the moved source snapshot immediately before retirement");
      tasks.splice(retain, 0, {
        name: "Fixture adds a late worktree after the final preflight listing",
        "ansible.builtin.command": { argv: ["git", "-C", f.checkout, "worktree", "add", "--quiet", "--detach", late] },
        changed_when: true,
      });
      Deno.writeTextFileSync(file, stringify(tasks));
      const head = git(f.checkout, "rev-parse", "HEAD");
      const retire = f.run("retire");
      assert(!retire.ok, retire.output);
      assertEquals(f.result().error_code, "instance.migrate_git_source_changed");
      assert(exists(f.checkout));
      assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
      assertEquals(git(late, "rev-parse", "HEAD"), head);
      assertEquals(Deno.readTextFileSync(`${late}/.git`).trim(), `gitdir: ${f.checkout}/.git/worktrees/after-final-listing`);
      assert(exists(`${late}/index.php`));
      assert(exists(f.migrate.awake.path));
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "migration carries a surviving stash ref with an expired reflog without inventing entries",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      Deno.writeTextFileSync(f.checkout + "/expired-stash.txt", "kept stash data");
      git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
      const stash = git(f.checkout, "rev-parse", "refs/stash");
      git(f.checkout, "reflog", "expire", "--expire=all", "refs/stash");
      f.build();
      assertEquals(f.workspace("stash", "list"), "");
      assertEquals(f.workspace("rev-parse", "refs/stash"), stash);
      assertEquals(f.workspace("show", stash + "^3:expired-stash.txt"), "kept stash data");
      f.workspace("fsck", "--full", "--strict");
    } finally { f.cleanup(); }
  },
});
Deno.test({
  name: "a worker receipt failure leaves the source untouched and a retry reuses the prepared retention time",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.build();
      const file = `${f.folder}/tasks/instance-migrate-retire.yml`;
      const original = Deno.readTextFileSync(file);
      const tasks = parse(original) as Task[];
      const record = tasks.find((task) => task.name === "Record retained Git data for the terminal result")!;
      (record["ansible.builtin.copy"] as Task).dest = `${f.folder}/missing-parent/retained-git.json`;
      Deno.writeTextFileSync(file, stringify(tasks));
      const head = git(f.checkout, "rev-parse", "HEAD");
      const failed = f.run("retire");
      assert(!failed.ok, failed.output);
      assert(exists(`${f.checkout}/.git`));
      assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
      assert(!exists(`${f.folder}/run/retained-git.json`));
      const prepared = JSON.parse(Deno.readTextFileSync(`${f.checkout}.orbit-retired-git/retained.json`));
      Deno.writeTextFileSync(file, original);
      const retry = f.run("retire");
      assert(retry.ok, retry.output);
      const recorded = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/retained-git.json`));
      assertEquals(recorded.retained_git_at, prepared.retained_git_at);
      assertEquals(git(f.retained(), "rev-parse", "HEAD"), head);
      assert(!exists(f.checkout));
    } finally {
      f.cleanup();
    }
  },
});
for (const fault of ["exit127", "restart", "pid", "job", "inactive", "never-active"]) {
  Deno.test({
    name: `migration refuses a moved Process with ${fault} before cutover and restores its host ownership`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const host = migrationProcesses(f, "running");
        const before = host();
        const process = f.container.processes.find((process) => process.desired === "running")!;
        if (fault === "exit127") process.content = "[Service]\nType=simple\nExecStart=/bin/sh -c 'exit 127'\nRestart=always\nRestartSec=1\n";
        f.write();
        f.build();
        const state = f.state();
        state.movedReadiness = {unit: process.name, fault, reads: []};
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        const failed = f.run("processes");
        assert(!failed.ok, failed.output);
        const receipt = f.result();
        assertEquals(receipt.error_code, "instance.migrate_processes_failed");
        assert(receipt.error_message.includes(process.name), JSON.stringify(receipt));
        const observed = f.state().movedReadiness;
        if (fault !== "never-active") {
          assertEquals(observed.reads.length, 2);
          assert(observed.reads[1] - observed.reads[0] >= 5000, "the real settling window must elapse");
        }
        if (fault === "exit127") assertEquals(observed.exitStatus, 127);
        assert(!exists(`${f.folder}/run/cutover-complete`));
        assert(!exists(f.migrate.awake.path));
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(f.result(), receipt, "restoration keeps the original named failure");
        assertEquals(host(), before);
        assertEquals(f.state().containers, {});
        assert(exists(f.migrate.awake.path));
        assert(exists(f.checkout));
      } finally { f.cleanup(); }
    },
  });
}
Deno.test({
  name: "only migration settles desired-running Processes; a stable Process passes with its PID and restart count unchanged",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      migrationProcesses(f, "stopped");
      f.build();
      const state = f.state();
      state.movedReadiness = {unit: f.container.processes.find((process) => process.desired === "running")!.name, fault: "stable", reads: []};
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const passed = f.run("processes");
      assert(passed.ok, passed.output);
      const reads = f.state().movedReadiness.reads;
      assertEquals(reads.length, 2);
      assert(reads[1] - reads[0] >= 5000);
      const calls = f.state().systemctl as string[][];
      for (const process of f.container.processes.filter((process) => process.desired !== "running")) {
        assert(!calls.some((call) => call[0] === "show" && call.includes(process.name)));
      }
      // The same container phase without a migration intent keeps its existing readiness check.
      const intent = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/intent.json`));
      delete intent.migrate;
      Deno.writeTextFileSync(`${f.folder}/run/intent.json`, JSON.stringify(intent));
      const other = f.run("processes");
      assert(other.ok, other.output);
      assertEquals(f.state().movedReadiness.reads, reads);
    } finally { f.cleanup(); }
  },
});
Deno.test({
  name: "24 moved workers share one five-second settle window inside the 120-second process phase budget",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      migrationProcesses(f, "stopped");
      const host = JSON.parse(Deno.readTextFileSync(`${f.folder}/host-processes.json`));
      for (let id = 73; id < 96; id++) {
        const name = `orbit-process-${id}-worker.service`;
        const directory = `${f.folder}/host/${id}`;
        Deno.mkdirSync(directory, {recursive: true});
        const path = `${directory}/${name}`;
        Deno.writeTextFileSync(path, "old unit\n");
        f.migrate.processes.push({name, path, environment_directory: directory, desired: "running", executables: ["/bin/sleep"]});
        f.container.processes.push({id, name, content: "[Service]\nUser=web\nExecStart=/bin/sleep infinity\n", environment: null, desired: "running"});
        host[name] = {state: "started", enabled: true};
      }
      Deno.writeTextFileSync(`${f.folder}/host-processes.json`, JSON.stringify(host));
      f.write();
      f.build();
      const started = Date.now();
      const passed = f.run("processes");
      assert(passed.ok, passed.output);
      assert(Date.now() - started < 120000, "the production process phase budget is fixed at 120 seconds");
      assertEquals(passed.output.match(/Pausing for 5 seconds/g)?.length, 1, passed.output);
      const samples = f.state().movedReadinessSamples as {unit: string; at: number}[];
      const workers = f.container.processes.filter((process) => process.desired === "running");
      assertEquals(workers.length, 24);
      assertEquals(samples.length, 48);
      assert(samples[24].at - samples[23].at >= 5000, "all initial samples precede the single settling window");
      for (const worker of workers) assertEquals(samples.filter((sample) => sample.unit === worker.name).length, 2);
    } finally { f.cleanup(); }
  },
});
Deno.test({
  name: "empty untracked storage and nested ignored directories migrate with exact modes and resolving links",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      Deno.mkdirSync(`${f.checkout}/public`);
      Deno.mkdirSync(`${f.checkout}/storage/app/public`, { recursive: true });
      Deno.writeTextFileSync(`${f.checkout}/storage/app/.gitignore`, "*\n!.gitignore\n!public/\n");
      Deno.writeTextFileSync(`${f.checkout}/public/.gitkeep`, "");
      Deno.writeTextFileSync(`${f.checkout}/.gitignore`, Deno.readTextFileSync(`${f.checkout}/.gitignore`) + "storage/framework/testing/\n");
      Deno.mkdirSync(`${f.checkout}/database/migrations`);
      for (let i = 0; i < 3; i++) Deno.writeTextFileSync(`${f.checkout}/database/migrations/file-${i}.php`, "<?php // migration\n");
      git(f.checkout, "add", ".");
      git(f.checkout, "commit", "--quiet", "-m", "storage shape");
      git(f.checkout, "update-ref", "refs/remotes/origin/main", "HEAD");
      const storage = `${f.checkout}/storage/app/public`;
      Deno.chmodSync(storage, 0o3775);
      Deno.symlinkSync(storage, `${f.checkout}/public/storage`);
      Deno.mkdirSync(`${f.checkout}/vendor/empty/nested`, { recursive: true });
      Deno.chmodSync(`${f.checkout}/vendor/empty/nested`, 0o2711);
      Deno.mkdirSync(`${f.checkout}/.git/empty-marker`);
      const ignored = "storage/framework/testing/cutover-release-fixture/migrations";
      Deno.mkdirSync(`${f.checkout}/${ignored}`, { recursive: true });
      for (let i = 0; i < 3; i++) Deno.symlinkSync(`${f.checkout}/database/migrations/file-${i}.php`, `${f.checkout}/${ignored}/file-${i}.php`);
      for (let i = 0; i < 12; i++) Deno.mkdirSync(`${f.checkout}/runtime-empty/slot-${i}`, { recursive: true });
      Deno.chmodSync(`${f.checkout}/runtime-empty/slot-0`, 0o700);
      Deno.chmodSync(`${f.checkout}/runtime-empty/slot-1`, 0o555);
      f.buildBatched();
      const copied = `${f.rootfs}/srv/workspace`;
      assertEquals(Deno.statSync(`${copied}/runtime-empty/slot-0`).mode! & 0o7777, 0o700);
      assertEquals(Deno.statSync(`${copied}/runtime-empty/slot-1`).mode! & 0o7777, 0o555);
      assertEquals(Deno.statSync(`${copied}/storage/app/public`).mode! & 0o7777, 0o3775);
      assertEquals(Deno.statSync(`${copied}/vendor/empty/nested`).mode! & 0o7777, 0o2711);
      assertEquals(Deno.readLinkSync(`${copied}/public/storage`), "../storage/app/public");
      assertEquals(Deno.realPathSync(`${copied}/public/storage`), `${copied}/storage/app/public`);
      assert(!exists(`${copied}/.git/empty-marker`));
      for (let i = 0; i < 12; i++) assert(Deno.statSync(`${copied}/runtime-empty/slot-${i}`).isDirectory);
      for (let i = 0; i < 3; i++) {
        assertEquals(Deno.readLinkSync(`${copied}/${ignored}/file-${i}.php`), `../../../../../database/migrations/file-${i}.php`);
        assertEquals(Deno.realPathSync(`${copied}/${ignored}/file-${i}.php`), `${copied}/database/migrations/file-${i}.php`);
        assertEquals(Deno.readLinkSync(`${f.checkout}/${ignored}/file-${i}.php`), `${f.checkout}/database/migrations/file-${i}.php`);
      }
      assertEquals(Deno.readLinkSync(`${f.checkout}/public/storage`), storage);
      assertEquals(Deno.statSync(storage).mode! & 0o7777, 0o3775);
    } finally { f.cleanup(); }
  },
});
Deno.test({
  name: "copy prediction refuses missing targets and omitted submodule content before quiesce without changing the checkout",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const commit = git(f.checkout, "rev-parse", "HEAD");
      git(f.checkout, "update-index", "--add", "--cacheinfo", "160000", commit, "submodule");
      git(f.checkout, "commit", "--quiet", "-m", "gitlink");
      git(f.checkout, "update-ref", "refs/remotes/origin/main", "HEAD");
      Deno.mkdirSync(`${f.checkout}/submodule`);
      Deno.writeTextFileSync(`${f.checkout}/submodule/file`, "omitted content\n");
      Deno.symlinkSync("submodule/file", `${f.checkout}/valid-on-source`);
      Deno.symlinkSync("missing", `${f.checkout}/dangling`);
      Deno.writeTextFileSync(`${f.checkout}/.git/local-only`, "source metadata\n");
      Deno.symlinkSync(".git/local-only", `${f.checkout}/metadata-link`);
      Deno.writeTextFileSync(`${f.checkout}/.git/info/exclude`, "storage/framework/testing/\n");
      Deno.mkdirSync(`${f.checkout}/storage/framework/testing/migrations`, { recursive: true });
      Deno.symlinkSync(`${f.checkout}/database/missing.php`, `${f.checkout}/storage/framework/testing/migrations/missing`);
      const before = JSON.stringify(f.state());
      const marker = Deno.statSync(f.migrate.awake.path).mtime?.getTime();
      const head = git(f.checkout, "rev-parse", "HEAD");
      for (const phase of ["container", "quiesce"]) {
        const result = f.run(phase);
        assert(!result.ok, result.output);
        assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
        assertEquals(f.result().preflight_refused, phase === "container");
        assert(!exists(`${f.folder}/run/quiesced`));
        for (const name of ["valid-on-source", "dangling", "metadata-link", "storage/framework/testing/migrations/missing"]) assert(f.result().error_message.includes(name), f.result().error_message);
        assertEquals(JSON.stringify(f.state()), before);
        assertEquals(Deno.statSync(f.migrate.awake.path).mtime?.getTime(), marker);
        assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
        assertEquals(Deno.readLinkSync(`${f.checkout}/valid-on-source`), "submodule/file");
        assertEquals(Deno.readLinkSync(`${f.checkout}/dangling`), "missing");
        assertEquals(Deno.readTextFileSync(`${f.checkout}/submodule/file`), "omitted content\n");
      }
    } finally { f.cleanup(); }
  },
});
Deno.test({
  name: "stock unused MySQL passes both real preflights and its warning survives the completed move",
  ignore: !hasAnsible,
  async fn() {
    const f = fixture();
    const holder = new Deno.Command("python3", {
      args:["-u","-c","import socket,sys; s=socket.socket(); s.bind(('127.0.0.1',3306)); print('reserved',flush=True); sys.stdin.read()"],
      stdin:"piped", stdout:"piped", stderr:"inherit",
    }).spawn();
    try {
      const reader = holder.stdout.getReader();
      const ready = await reader.read();
      reader.releaseLock();
      assert(new TextDecoder().decode(ready.value).includes("reserved"));
      const text = "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=laravel\n";
      Deno.writeTextFileSync(`${f.checkout}/.env`, text);
      const prepared = f.run("container");
      assert(prepared.ok, prepared.output);
      assert(!exists(`${f.folder}/run/quiesced`));
      assertEquals(Deno.readTextFileSync(`${f.checkout}/.env`),text);
      f.build();
      for (const phase of ["processes","seed"]) {
        const done = f.run(phase);
        assert(done.ok,done.output);
      }
      const retired = f.run("retire");
      assert(retired.ok,retired.output);
      const commit = f.workspace("rev-parse","HEAD");
      const phases = {container:"succeeded",quiesce:"succeeded",workspace:"succeeded",files:"succeeded",
        agent:"skipped",processes:"succeeded",seed:"succeeded",t3:"skipped",register:"skipped",route:"succeeded",
        retire:"succeeded",restore_route:"skipped",restore:"skipped"};
      const receipt = JSON.parse(migrateResult({instance_id:53,container:f.container,migrate:f.migrate,route_mutations:{}},
        phases,{status:"succeeded",outputs:{stdout:"orbit-commit="+commit}},null,await writtenFailure(`${f.folder}/run`)).stdout);
      assertEquals(receipt.ready,true);
      assertEquals(receipt.warnings,[{code:"instance.migrate_env_service_unused",
        message:".env: DB_HOST=127.0.0.1:3306: no TCP service answered on the Node."}]);
    } finally {
      await holder.stdin.getWriter().close();
      await holder.status;
      f.cleanup();
    }
  },
});
