# Program: instances.inspect-source
# Uses: lib/program.py lib/source-removal.py
# Outcomes: inspected path_mismatch ownership_mismatch layout_mismatch origin_mismatch branch_mismatch failed
# Timeout: 900
# Input: {"checkout": path, "root": apps root, "user": managed user, "group": managed group,
#   "layout": "checkout" or "worktree", "branch": expected branch or "", "inspect_content": bool,
#   "unresolved": bool, "allow_absent": bool, "origin": the Project origin, "prepare_id": source preparation ID or ""}
# Result: inspected: base64 of "top", "common", "origin", "branch", "commit", "dirty" ("", "0", or "1"),
#   "source_identity" ("<device>:<inode>" or "absent"), and "worktrees" (`git worktree list --porcelain -z`).
#   Each mismatch names the identity check that refused. failed: Git could not read the working tree status.
# Reads a development checkout's Git evidence for Instance removal. It changes nothing.
import base64
import os


def encoded(value):
    return base64.b64encode(value if isinstance(value, bytes) else os.fsencode(value)).decode()


def main(payload):
    checkout = payload['checkout']
    root = payload['root']
    account = f"{payload['user']}:{payload['group']}"
    layout = payload['layout']
    expected_branch = payload['branch']

    require(checkout.startswith(root + '/'), 'path_mismatch', 'the checkout is inside the apps root')
    require(no_symlink_below('', checkout), 'path_mismatch', 'no symlink in the checkout path')
    if payload['allow_absent'] and not os.path.lexists(checkout):
        require(layout in ('checkout', 'worktree'), 'path_mismatch', 'a known source layout')
        return 'inspected', {
            'top': encoded(checkout),
            'common': encoded(f'{checkout}/.git'),
            'origin': encoded(payload['origin']),
            'branch': encoded(expected_branch),
            'commit': encoded(ZERO_COMMIT),
            'dirty': encoded(''),
            'source_identity': encoded('absent'),
            'worktrees': encoded(os.fsencode(f'worktree {checkout}') + b'\0\0'),
        }
    require(os.path.isdir(checkout), 'path_mismatch', 'the checkout is a directory')
    require(canonical(checkout), 'path_mismatch', 'the checkout path is canonical')

    require(owner(checkout) == account, 'ownership_mismatch', 'the checkout owner')
    require(owner(dirname(checkout)) == account, 'ownership_mismatch', 'the checkout parent owner')
    if payload['prepare_id'] != '':
        require(not os.path.islink(f'{checkout}/.git'), 'ownership_mismatch', 'the Git directory is not a symlink')
        prepare_git = git_output('-C', checkout, 'rev-parse', '--absolute-git-dir')
        require(prepare_git is not None, 'ownership_mismatch', 'read the Git directory')
        marker = f'{prepare_git}/orbit-source-prepare'
        require(is_regular_file(marker), 'ownership_mismatch', 'the source preparation receipt')
        require(owner(marker) == account, 'ownership_mismatch', 'the source preparation receipt owner')
        require(
            read_text(marker) == f"{payload['prepare_id']}:{identity(checkout)}",
            'ownership_mismatch',
            'the source preparation receipt names this attempt and directory',
        )

    top = git_output('-C', checkout, 'rev-parse', '--show-toplevel')
    git_dir = git_output('-C', checkout, 'rev-parse', '--absolute-git-dir')
    common = git_output('-C', checkout, 'rev-parse', '--path-format=absolute', '--git-common-dir')
    require(None not in (top, git_dir, common), 'layout_mismatch', 'read the Git layout')
    require(top == checkout, 'layout_mismatch', 'the checkout is the work tree top')
    if layout == 'checkout':
        require(is_real_directory(f'{checkout}/.git'), 'layout_mismatch', 'the checkout has its own Git directory')
        require(git_dir == f'{checkout}/.git', 'layout_mismatch', 'the Git directory is in the checkout')
        require(common == f'{checkout}/.git', 'layout_mismatch', 'the checkout shares no Git directory')
    elif layout == 'worktree':
        require(is_regular_file(f'{checkout}/.git'), 'layout_mismatch', 'the worktree has a Git file')
        require(git_dir != common, 'layout_mismatch', 'the worktree has its own administration directory')
        require(is_real_directory(common), 'layout_mismatch', 'the common Git directory')
    else:
        raise Outcome('layout_mismatch')
    if payload['unresolved']:
        commit = git_output('-C', checkout, 'rev-parse', '--verify', 'HEAD^{commit}', quiet=True) or ZERO_COMMIT
    else:
        commit = git_output('-C', checkout, 'rev-parse', '--verify', 'HEAD^{commit}')
        require(commit is not None, 'layout_mismatch', 'the checkout has a HEAD commit')

    origin = origin_url(checkout)
    require(origin is not None, 'origin_mismatch', 'the checkout has one origin')

    branch = git_output('-C', checkout, 'symbolic-ref', '--quiet', '--short', 'HEAD') or ''
    if not payload['unresolved']:
        require(branch == expected_branch, 'branch_mismatch', 'the checked-out branch')

    dirty = ''
    if payload['inspect_content']:
        state = clean(checkout)
        require(state is not None, 'failed', 'read the working tree status')
        dirty = '0' if state else '1'

    return 'inspected', {
        'top': encoded(top),
        'common': encoded(common),
        'origin': encoded(origin),
        'branch': encoded(branch),
        'commit': encoded(commit),
        'dirty': encoded(dirty),
        'source_identity': encoded(identity(checkout)),
        'worktrees': encoded(worktree_inventory(checkout)),
    }


run_program(main)
