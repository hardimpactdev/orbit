# Program: instances.remove-source
# Uses: lib/program.py lib/source-removal.py
# Outcomes: removed path_mismatch ownership_mismatch layout_mismatch origin_mismatch branch_mismatch worktrees_mismatch unsafe_content source_changed failed
# Timeout: 900
# Input: {"checkout": path, "root": apps root, "grouping_directory": the checkout's parent, "user", "group",
#   "branch": inspected branch or "", "commit": inspected commit, "source_identity": "<device>:<inode>",
#   "repository_identity": the Project repository identity, "force": bool,
#   "own_units": systemd units whose processes stay, "git_read": Git read environment variables}
# Result: removed: the checkout and its empty parent are gone. A mismatch names the identity check that refused.
#   unsafe_content: normal removal found dirty or unpublished source. source_changed: the directory or HEAD differs
#   from the inspection. failed: another step failed. Every refusal leaves the checkout in place.
# Checks an active checkout again at the destructive boundary and deletes it in the same run.
import os


def linked_worktrees(checkout, inventory):
    """Count the live entries of a `git worktree list --porcelain -z` listing. Each must be the checkout itself.
    A prunable entry's directory is gone, so it does not keep the checkout in use."""
    count = 0
    path = None
    prunable = False

    def flush():
        nonlocal count, path, prunable
        if path and not prunable:
            count += 1
            require(path == os.fsencode(checkout), 'worktrees_mismatch', 'no other linked worktree')
        path = None
        prunable = False

    for field in inventory.split(b'\0')[:-1]:
        if field.startswith(b'worktree '):
            flush()
            path = field[len(b'worktree '):]
        elif field == b'prunable' or field.startswith(b'prunable '):
            prunable = True
        elif field == b'':
            flush()
    flush()
    return count


def main(payload):
    checkout = payload['checkout']
    root = payload['root']
    grouping_directory = payload['grouping_directory']
    account = f"{payload['user']}:{payload['group']}"

    require(grouping_directory == dirname(checkout), 'path_mismatch', 'the grouping directory holds the checkout')
    require(checkout.startswith(root + '/'), 'path_mismatch', 'the checkout is inside the apps root')
    require(no_symlink_below(root, checkout), 'path_mismatch', 'no symlink below the apps root')
    require(os.path.isdir(checkout) and canonical(checkout), 'path_mismatch', 'the checkout is a canonical directory')
    require(
        is_real_directory(grouping_directory) and canonical(grouping_directory),
        'path_mismatch',
        'the grouping directory is a canonical directory',
    )
    require(identity(checkout) == payload['source_identity'], 'source_changed', 'the checkout directory identity')
    require(owner(checkout) == account, 'ownership_mismatch', 'the checkout owner')
    require(owner(grouping_directory) == account, 'ownership_mismatch', 'the grouping directory owner')

    require(is_real_directory(f'{checkout}/.git'), 'layout_mismatch', 'the checkout has its own Git directory')
    require(git_output('-C', checkout, 'rev-parse', '--show-toplevel') == checkout, 'layout_mismatch', 'the work tree top')
    require(
        git_output('-C', checkout, 'rev-parse', '--absolute-git-dir') == f'{checkout}/.git',
        'layout_mismatch',
        'the Git directory is in the checkout',
    )
    require(
        git_output('-C', checkout, 'rev-parse', '--path-format=absolute', '--git-common-dir') == f'{checkout}/.git',
        'layout_mismatch',
        'the checkout shares no Git directory',
    )
    branch = git_output('-C', checkout, 'symbolic-ref', '--quiet', '--short', 'HEAD') or ''
    require(branch == payload['branch'], 'branch_mismatch', 'the checked-out branch')
    commit = git_output('-C', checkout, 'rev-parse', '--verify', 'HEAD^{commit}') or ''
    require(commit == payload['commit'], 'source_changed', 'the HEAD commit')
    origin = origin_url(checkout)
    require(origin is not None, 'origin_mismatch', 'the checkout has one origin')
    require(
        repository_identity(origin) == payload['repository_identity'],
        'origin_mismatch',
        'the origin is the Project repository',
    )
    require(
        linked_worktrees(checkout, worktree_inventory(checkout)) == 1,
        'worktrees_mismatch',
        'the checkout is its only worktree',
    )

    if not payload['force']:
        state = clean(checkout)
        require(state is not None, 'failed', 'read the working tree status')
        require(state, 'unsafe_content', 'the source is clean')
        require(is_published(origin, payload['commit'], payload['git_read'], 'failed'), 'unsafe_content', 'the HEAD is published')

    require(stop_tree_processes(checkout, payload['own_units']), 'failed', 'stop the processes inside the checkout')
    remove_source_tree(checkout)
    release_empty_grouping_directory(checkout, root, grouping_directory, account, 'failed')
    return 'removed', {}


run_program(main)
