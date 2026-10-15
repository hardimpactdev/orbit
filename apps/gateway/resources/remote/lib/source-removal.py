# Library: checks and steps that the development source removal programs share. A check that fails ends the program
# with the outcome its caller names, through `require`. Paths are compared as the shell compared them: `stat`, `test`,
# and `realpath -e` look at the path itself, and command output loses its trailing newlines.
import base64
import grp
import os
import pwd
import re
import shutil
import signal
import subprocess
import tempfile
import time

ZERO_COMMIT = '0' * 40

# The repository identity rule that the destructive boundary applies to the origin read from the checkout. It runs in
# PHP on the Node, as the Bash removal did, so its URL parsing matches the rule exactly.
REPOSITORY_IDENTITY_PHP = r'''
                $repository = stream_get_contents(STDIN);

                if (
                    preg_match("//u", $repository) !== 1
                    || $repository === ""
                    || preg_match("/[\\p{Z}\\p{C}]/u", $repository) !== 0
                ) {
                    exit(1);
                }

                $matches = [];

                if (preg_match("/\\Agit@([^:\\s?#]+):([^\\s?#]+)\\z/u", $repository, $matches) === 1) {
                    $host = $matches[1];
                    $path = $matches[2];
                } else {
                    $parts = parse_url($repository);

                    if (!is_array($parts)) {
                        exit(1);
                    }

                    $scheme = is_string($parts["scheme"] ?? null) ? $parts["scheme"] : null;
                    $host = is_string($parts["host"] ?? null) ? $parts["host"] : null;
                    $path = is_string($parts["path"] ?? null) ? $parts["path"] : null;

                    if (!is_string($host) || $host === "" || !is_string($path) || $path === "") {
                        exit(1);
                    }

                    if (array_key_exists("query", $parts) || array_key_exists("fragment", $parts)) {
                        exit(1);
                    }

                    $https = $scheme === "https"
                        && !array_key_exists("user", $parts)
                        && !array_key_exists("pass", $parts);
                    $ssh = $scheme === "ssh" && !array_key_exists("pass", $parts);

                    if (!$https && !$ssh) {
                        exit(1);
                    }
                }

                $path = trim($path, "/");

                if (str_ends_with($path, ".git")) {
                    $path = substr($path, 0, -4);
                }

                fwrite(STDOUT, strtolower($host)."/".rtrim($path, "/"));
            '''


def substitution(output):
    """What a shell command substitution keeps of `output`: no NUL bytes and no trailing newlines."""
    return os.fsdecode(output.replace(b'\0', b'').rstrip(b'\n'))


def git(*arguments, environment=None, quiet=False):
    """Run Git without hooks, fsmonitor, or optional locks. Returns the completed process with byte output."""
    variables = dict(os.environ, GIT_OPTIONAL_LOCKS='0')
    variables.update(environment or {})
    return subprocess.run(
        ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', *arguments],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL if quiet else None,
        env=variables,
        check=False,
    )


def git_output(*arguments, quiet=False):
    """Git's output as a command substitution keeps it, or None when Git fails."""
    completed = git(*arguments, quiet=quiet)
    return substitution(completed.stdout) if completed.returncode == 0 else None


def run(*arguments):
    return subprocess.run(list(arguments), stdin=subprocess.DEVNULL, check=False).returncode == 0


def owner(path):
    """`stat -c '%U:%G'` of the path itself, or '' when it cannot be read."""
    try:
        status = os.lstat(path)
    except OSError:
        return ''
    try:
        user = pwd.getpwuid(status.st_uid).pw_name
    except KeyError:
        user = 'UNKNOWN'
    try:
        group = grp.getgrgid(status.st_gid).gr_name
    except KeyError:
        group = 'UNKNOWN'
    return f'{user}:{group}'


def identity(path):
    """`stat -c '%d:%i'` of the path itself, or '' when it cannot be read."""
    try:
        status = os.lstat(path)
    except OSError:
        return ''
    return f'{status.st_dev}:{status.st_ino}'


def canonical(path):
    """Whether `realpath -e` of the path is the path itself."""
    try:
        return os.path.realpath(path, strict=True) == path
    except OSError:
        return False


def holds(path, text):
    """Whether the file holds exactly `text` and one newline, as `printf '%s\\n' text | cmp -s - path` checks."""
    try:
        with open(path, 'rb') as handle:
            return handle.read() == os.fsencode(text) + b'\n'
    except OSError:
        return False


def dirname(path):
    """`dirname` of a path."""
    stripped = path.rstrip('/')
    if stripped == '':
        return '/' if path.startswith('/') else '.'
    if '/' not in stripped:
        return '.'
    return stripped[:stripped.rfind('/')].rstrip('/') or '/'


def no_symlink_below(base, path):
    """Whether no path component after `base` is a symlink. An empty base checks every component from `/`."""
    relative = path[len(base) + 1:] if base else path.removeprefix('/')
    current = base
    for segment in relative.split('/'):
        current = f'{current}/{segment}'
        if os.path.islink(current):
            return False
    return True


def is_regular_file(path):
    """`test -f path && test ! -L path`."""
    return os.path.isfile(path) and not os.path.islink(path)


def is_real_directory(path):
    """`test -d path && test ! -L path`."""
    return os.path.isdir(path) and not os.path.islink(path)


def write_atomically(directory, prefix, path, text):
    """Write `text` and a newline to a private file in `directory`, then rename it to `path`."""
    descriptor, candidate = tempfile.mkstemp(prefix=prefix, dir=directory)
    try:
        with os.fdopen(descriptor, 'wb') as handle:
            handle.write(os.fsencode(text) + b'\n')
        os.chmod(candidate, 0o600)
        os.rename(candidate, path)
    except BaseException:
        try:
            os.unlink(candidate)
        except OSError:
            pass
        raise


def origin_url(checkout):
    """The checkout's `remote.origin.url` without its one trailing newline, or None when Git has none."""
    completed = git('-C', checkout, 'config', '--get', 'remote.origin.url')
    if completed.returncode != 0 or not completed.stdout.endswith(b'\n'):
        return None
    return os.fsdecode(completed.stdout.replace(b'\0', b'')[:-1])


def repository_identity(origin):
    """The repository identity of an origin by the rule in REPOSITORY_IDENTITY_PHP, or None when it is invalid."""
    completed = subprocess.run(
        ['php', '-r', REPOSITORY_IDENTITY_PHP],
        input=os.fsencode(origin),
        stdout=subprocess.PIPE,
        check=False,
    )
    return substitution(completed.stdout) if completed.returncode == 0 else None


def worktree_inventory(checkout):
    """The raw `git worktree list --porcelain -z` output. A failed listing is empty, as the shell pipeline was."""
    return git('-C', checkout, 'worktree', 'list', '--porcelain', '-z').stdout


def clean(checkout):
    """Whether the checkout has no changes, untracked files included. None when Git cannot read its status."""
    completed = git('-C', checkout, 'status', '--porcelain', '--untracked-files=all')
    if completed.returncode != 0:
        return None
    return completed.stdout.rstrip(b'\n') == b''


def is_published(origin, commit, git_read, outcome):
    """Whether a current branch or tag of the origin contains the commit. The origin's refs go into a temporary
    repository outside the checkout. Only the fetch sees the Git read environment. A failed fetch ends with `outcome`.
    """
    scratch = tempfile.mkdtemp()
    try:
        repository = f'{scratch}/repository.git'
        require(git('init', '--bare', '--quiet', repository).returncode == 0, outcome, 'initialize a scratch repository')
        require(
            git(f'--git-dir={repository}', 'remote', 'add', 'origin', origin).returncode == 0,
            outcome,
            'add the origin to the scratch repository',
        )
        fetched = git(
            f'--git-dir={repository}', 'fetch', '--quiet', '--no-tags', '--filter=blob:none', 'origin',
            '+refs/heads/*:refs/remotes/origin/*', '+refs/tags/*:refs/tags/*',
            environment=git_read,
        )
        require(fetched.returncode == 0, outcome, 'fetch the origin refs')
        if git(f'--git-dir={repository}', 'cat-file', '-e', f'{commit}^{{commit}}', quiet=True).returncode != 0:
            return False
        listing = git(f'--git-dir={repository}', 'for-each-ref', '--format=%(refname)', 'refs/remotes/origin', 'refs/tags')
        for advertised in listing.stdout.split(b'\n')[:-1]:
            tip = git_output(f'--git-dir={repository}', 'rev-parse', '--verify', os.fsdecode(advertised) + '^{commit}', quiet=True)
            if tip is None:
                continue
            if git(f'--git-dir={repository}', 'merge-base', '--is-ancestor', commit, tip).returncode == 0:
                return True
        return False
    finally:
        shutil.rmtree(scratch, ignore_errors=True)


def read_text(path):
    try:
        with open(path, 'rb') as handle:
            return substitution(handle.read())
    except OSError:
        return ''


def process_unit(pid):
    """The systemd unit of a process: the last part of its cgroup path."""
    return read_text(f'/proc/{pid}/cgroup').rsplit('/', 1)[-1]


def tree_processes(tree, own_units):
    """This account's processes whose working directory or executable is inside the tree, without the processes of
    the units in `own_units` and without a tmux or screen server, which hosts sessions that work elsewhere."""
    uid = os.geteuid()
    found = []
    try:
        entries = os.listdir('/proc')
    except OSError:
        return found
    for entry in entries:
        if not entry.isdigit() or int(entry) == os.getpid():
            continue
        for link in ('cwd', 'exe'):
            path = f'/proc/{entry}/{link}'
            try:
                if os.lstat(path).st_uid != uid:
                    continue
                target = os.readlink(path)
            except OSError:
                continue
            if target != tree and not target.startswith(tree + '/') and target != tree + ' (deleted)':
                continue
            unit = process_unit(entry)
            if unit != '' and unit in own_units:
                continue
            if read_text(f'/proc/{entry}/comm') in ('tmux: server', 'screen', 'SCREEN'):
                continue
            found.append(int(entry))
    return found


def stop_tree_processes(tree, own_units):
    """Stop the tree's processes: SIGTERM, then SIGKILL, each for up to 5 seconds. Returns whether they stopped."""
    for signum in (signal.SIGTERM, signal.SIGKILL):
        started = time.monotonic()
        while True:
            processes = tree_processes(tree, own_units)
            if not processes:
                return True
            for pid in processes:
                try:
                    os.kill(pid, signum)
                except OSError:
                    pass
            if time.monotonic() - started >= 5:
                break
            time.sleep(0.1)
    log(f'Processes inside {tree} did not stop.')
    return False


def remove_source_tree(tree, common_repository=None, outcome='failed'):
    """Delete the tree. A linked worktree is removed through its common repository, which keeps its branch."""
    if common_repository is not None:
        removed = git(f'--git-dir={common_repository}/.git', 'worktree', 'remove', '--force', tree).returncode == 0
    else:
        removed = run('rm', '-rf', '--', tree)
    require(removed, outcome, f'delete {tree}')


def release_empty_grouping_directory(checkout, root, grouping_directory, account, outcome):
    """Remove the checkout's parent, the Project's directory, when the account owns it and it is empty."""
    require(not os.path.lexists(checkout), outcome, 'the checkout is gone')
    require(grouping_directory != root, outcome, 'the grouping directory is not the apps root')
    require(grouping_directory == dirname(checkout), outcome, 'the grouping directory holds the checkout')
    require(grouping_directory.startswith(root + '/'), outcome, 'the grouping directory is inside the apps root')
    if not os.path.lexists(grouping_directory):
        return
    if os.path.islink(grouping_directory) or not os.path.isdir(grouping_directory):
        return
    if not canonical(grouping_directory) or owner(grouping_directory) != account:
        return
    try:
        with os.scandir(grouping_directory) as entries:
            if any(True for _ in entries):
                return
    except OSError:
        pass
    try:
        os.rmdir(grouping_directory)
    except OSError as error:
        log(f'rmdir {grouping_directory}: {error.strerror}')
        raise Outcome(outcome) from error


def removal_paths(root, operation, member):
    """The removal state directory and this member's journal, receipt, quarantine, and recovery paths."""
    state = f'{root}/.orbit-removals'
    prefix = f'{state}/{operation}.{member}'
    return state, f'{prefix}.journal', f'{prefix}.receipt', f'{prefix}.quarantine', f'{prefix}.recovery'


def require_state(state, journal, digest, account, outcome):
    """The state directory and the member's journal exist, belong to the account, and the journal holds the digest."""
    require(is_real_directory(state) and canonical(state), outcome, 'the removal state directory')
    require(owner(state) == account, outcome, 'the removal state directory owner')
    require(is_regular_file(journal), outcome, 'the removal journal')
    require(owner(journal) == account, outcome, 'the removal journal owner')
    require(holds(journal, digest), outcome, 'the removal journal digest')


def recovery_record(recovery, common_repository, outcome):
    """The worktree recovery record: its administration directory and three identities, read and checked."""
    try:
        with open(recovery, 'rb') as handle:
            content = handle.read()
    except OSError:
        content = None
    require(content is not None, outcome, 'read the recovery record')
    fields = content.split(b'\n')
    if fields and fields[-1] == b'':
        fields.pop()
    require(len(fields) == 4, outcome, 'the recovery record has four fields')
    admin_encoded, admin_identity, common_identity, worktrees_identity = (os.fsdecode(field) for field in fields)
    try:
        admin = substitution(base64.b64decode(admin_encoded, validate=True))
    except ValueError:
        admin = None
    require(admin is not None, outcome, 'decode the administration directory')
    require(base64.b64encode(os.fsencode(admin)).decode() == admin_encoded, outcome, 'the administration directory encoding')
    for value in (admin_identity, common_identity, worktrees_identity):
        require(re.fullmatch(r'[0-9]+:[0-9]+', value) is not None, outcome, 'a recorded identity')
    require(dirname(admin) == f'{common_repository}/.git/worktrees', outcome, 'the administration directory location')
    return admin, admin_identity, common_identity, worktrees_identity


def worktree_entries(worktrees, checkout, quarantine, admin, outcome):
    """How many Git worktree entries point at the checkout or its quarantine. Each one must be `admin`."""
    matching = 0
    try:
        names = sorted(name for name in os.listdir(worktrees) if not name.startswith('.'))
    except OSError:
        names = []
    for name in names:
        candidate = f'{worktrees}/{name}'
        gitdir = f'{candidate}/gitdir'
        if is_regular_file(gitdir) and (holds(gitdir, f'{checkout}/.git') or holds(gitdir, f'{quarantine}/.git')):
            require(candidate == admin, outcome, 'one worktree entry for the source')
            matching += 1
    require(matching <= 1, outcome, 'at most one worktree entry for the source')
    return matching
