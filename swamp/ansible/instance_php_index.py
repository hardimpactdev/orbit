"""The Phpactor symbol index of a Laravel default, prepared before its seed (docs/reference/development-containers.md#php-symbol-index).

`agent` runs it in the default container in the `php_index` phase, after the Project's deploy steps and its Processes and before the
seed, with the application's folder (the one with `artisan`) as its working folder and the container's deploy environment: `HOME` is
`/home/agent` and `PATH` has the Project's PHP. Arguments: the bound in seconds and the runtime folder (`/opt/orbit-runtime/current`),
or `seeded`.

Phpactor names its index by itself: `$HOME/.cache/phpactor/index/<folder name>-<hash of the folder's path>`, the folder being the
working folder of `phpactor index:build` or the root URI of its language server. A clone has the same home and application path, so a
fresh Phpactor in the clone finds the seed's index with no setting in the Project. Phpactor reindexes a file whose ctime is not older
than the index's last update, so a build on an index that exists is incremental.

The run keeps a fingerprint of what the index read in STAMP: Phpactor's and PHP's paths (a new version is a new path), the folder,
each PHP file and folder below it with its ctime and size, and the index's own timestamp files. With the fingerprint unchanged it
starts no Phpactor. Otherwise it runs `phpactor index:build` at the lowest CPU and I/O priority within the bound and then writes the
new fingerprint to PENDING. Only the seed phase, once the seed that holds the index is taken, makes it the recorded one (`seeded`), so a
run whose snapshot failed leaves the fingerprint pending, and the next run builds again and retakes the seed. A build that fails or runs
past the bound exits 1 with the end of Phpactor's output on stderr, and records nothing.

A missing `php` always fails. A runtime folder that predates Phpactor, with neither its `phpactor` part folder nor its `bin/phpactor`
link (a release before the Node's converge), gets no index work: the run starts nothing, records nothing and exits 0 with the reason,
so the deployment goes on with a seed that has no new index. A runtime folder that ships Phpactor but whose `bin/phpactor` is missing
or not executable fails.

Prints one JSON line: `{"index": "unchanged"}`, `{"index": "updated", "seconds": <float>}` or `{"index": "skipped", "reason": ...}`.
"""

import hashlib
import json
import os
import shutil
import subprocess
import sys
import time

STAMP = os.path.join(os.path.expanduser("~"), ".cache", "orbit-php-index.json")
PENDING = os.path.join(os.path.expanduser("~"), ".cache", "orbit-php-index.pending.json")
INDEXES = os.path.join(os.path.expanduser("~"), ".cache", "phpactor", "index")
# Phpactor's own extensions (indexer.supported_extensions).
EXTENSIONS = (".php", ".phar")


def fingerprint(root, phpactor, php):
    """A digest of what a build would read; a folder's ctime moves when an entry in it is added, removed or renamed."""
    digest = hashlib.sha256()
    digest.update(json.dumps([phpactor, php, root]).encode())
    for folder, folders, files in os.walk(root):
        folders[:] = sorted(name for name in folders if not (folder == root and name == ".git"))
        info = os.lstat(folder)
        digest.update(f"d {os.path.relpath(folder, root)} {info.st_ctime_ns}\n".encode())
        for name in sorted(files):
            if name.endswith(EXTENSIONS):
                info = os.lstat(os.path.join(folder, name))
                digest.update(f"f {os.path.relpath(os.path.join(folder, name), root)} {info.st_ctime_ns} {info.st_size}\n".encode())
    # The index itself: a removed or rebuilt index is not the one the fingerprint was taken with.
    if os.path.isdir(INDEXES):
        for name in sorted(os.listdir(INDEXES)):
            index = os.path.join(INDEXES, name)
            for stamp in sorted(n for n in os.listdir(index) if n.startswith("timestamp.")) if os.path.isdir(index) else []:
                digest.update(f"i {name}/{stamp} {os.lstat(os.path.join(index, stamp)).st_ctime_ns}\n".encode())
    return digest.hexdigest()


def recorded():
    try:
        with open(STAMP, encoding="utf-8") as handle:
            return json.load(handle)
    except (OSError, ValueError):
        return {}


def main():
    if sys.argv[1] == "seeded":
        if os.path.exists(PENDING):
            os.replace(PENDING, STAMP)
        return 0
    seconds, runtime = int(sys.argv[1]), sys.argv[2]
    root = os.getcwd()
    php = shutil.which("php")
    if php is None:
        print("php must be on PATH", file=sys.stderr)
        return 1
    program = os.path.join(runtime, "bin", "phpactor")
    if not os.path.lexists(program) and not os.path.exists(os.path.join(runtime, "phpactor")):
        print(json.dumps({"index": "skipped", "reason": "The Node's runtime folder has no phpactor yet: converge the Node."}))
        return 0
    if not (os.path.isfile(program) and os.access(program, os.X_OK)):
        print(f"The runtime folder ships phpactor, but {program} is missing or not executable", file=sys.stderr)
        return 1
    phpactor, php = os.path.realpath(program), os.path.realpath(php)
    if recorded().get(root) == fingerprint(root, phpactor, php):
        print(json.dumps({"index": "unchanged"}))
        return 0

    started = time.monotonic()
    build = subprocess.run(["timeout", "--kill-after=10", str(seconds), "nice", "-n", "19", "ionice", "-c", "3",
                            program, "index:build", "--no-interaction", "--no-ansi"],
                           stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=False)
    if build.returncode != 0:
        tail = build.stdout.decode(errors="replace").strip()[-400:]
        reason = f"Phpactor did not finish its index within {seconds} s" if build.returncode in (124, 137) \
            else f"phpactor index:build exited {build.returncode}"
        print(f"{reason}: {tail}" if tail else reason, file=sys.stderr)
        return 1

    # Taken after the build, so the fingerprint holds the index's new timestamp. It waits in PENDING until the seed holds the index.
    os.makedirs(os.path.dirname(STAMP), exist_ok=True)
    temporary = PENDING + ".tmp"
    with open(temporary, "w", encoding="utf-8") as handle:
        json.dump({root: fingerprint(root, phpactor, php)}, handle)
    os.replace(temporary, PENDING)
    print(json.dumps({"index": "updated", "seconds": round(time.monotonic() - started, 1)}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
