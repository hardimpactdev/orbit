import { assertEquals } from "jsr:@std/assert@1.0.19";
import { command } from "../tests/_support/platform_fixture.ts";

Deno.test({
  name: "systemd inode probe hides home and keeps the metadata bind empty and read-only",
  // The batch forbids sudo. A native root systemd host can exercise this without elevation.
  ignore: Deno.uid() !== 0 || !await Deno.stat("/run/systemd/system").then((stat) => stat.isDirectory, () => false),
  async fn() {
    const dir = await Deno.makeTempDir({ dir: Deno.env.get("HOME"), prefix: "swamp-inode-probe-" });
    try {
      const probe = `${dir}/empty`, secret = `${dir}/operator-sentinel`;
      await Deno.mkdir(probe, { mode: 0o700 });
      await Deno.writeTextFile(secret, "synthetic native test sentinel", { mode: 0o600 });
      using _control = await Deno.open(secret);
      const expected = JSON.parse(
        (await command([
          "python3",
          "-c",
          "import os,json,sys;s=os.statvfs(sys.argv[1]);print(json.dumps([s.f_fsid,s.f_files]))",
          probe,
        ])).stdout,
      );
      const script = `import os,json,sys
p='/run/swamp-datastore-inode-probe';s=os.statvfs(p)
try: os.open(sys.argv[1],os.O_RDONLY);denied=False
except (PermissionError,FileNotFoundError): denied=True
try: os.open(p+'/forbidden',os.O_WRONLY|os.O_CREAT,0o600);error=0
except OSError as e: error=e.errno
print(json.dumps({'filesystem':[s.f_fsid,s.f_files],'empty':not os.listdir(p),'homeDenied':denied,'writeError':error}))`;
      const result = JSON.parse(
        (await command([
          "systemd-run",
          "--quiet",
          "--wait",
          "--pipe",
          "--collect",
          `--unit=orbit-swamp-inode-probe-test-${crypto.randomUUID()}`,
          "--property=User=root",
          "--property=NoNewPrivileges=yes",
          "--property=ProtectSystem=strict",
          "--property=ProtectHome=yes",
          "--property=PrivateTmp=yes",
          `--property=BindReadOnlyPaths=${probe}:/run/swamp-datastore-inode-probe`,
          "python3",
          "-c",
          script,
          secret,
        ])).stdout,
      );
      assertEquals(result, { filesystem: expected, empty: true, homeDenied: true, writeError: 30 });
    } finally {
      await Deno.remove(dir, { recursive: true });
    }
  },
});
