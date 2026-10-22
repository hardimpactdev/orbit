# Program: instances.finalize-source-removal
# Uses: lib/program.py lib/source-removal.py
# Outcomes: finalized cross_device refused
# Timeout: 900
# Input: {"checkout": path, "root": apps root, "common_repository": the repository that holds the Git directory,
#   "layout": "checkout" or "worktree", "branch": recorded branch or "", "operation": removal ID, "member": member ID,
#   "digest": accepted source digest, "receipt": completion receipt, "force": bool, "user", "group",
#   "source_identity": "<device>:<inode>" or "absent", "origin": the recorded origin, "worktrees": base64 of the
#   accepted `git worktree list --porcelain -z`, "commit": accepted commit, "grouping_directory": the checkout's
#   parent, "unresolved": bool, "independent_worktree": bool, "own_units": systemd units
#   whose processes stay, "git_read": Git read environment variables}
# Result: finalized: {"receipt": the completion receipt}. cross_device: the checkout is on another filesystem than
#   the removal state, so it stays at its path. refused: a check failed before more was deleted; its log line names it.
# Moves a recorded source into quarantine, writes the completion receipt, and deletes the quarantine. A retry resumes
# from the quarantine or the receipt.
import base64
import os


def same_filesystem(first, second):
    return os.lstat(first).st_dev == os.lstat(second).st_dev


def main(payload):
    checkout = payload['checkout']
    root = payload['root']
    common_repository = payload['common_repository']
    layout = payload['layout']
    operation, member = payload['operation'], payload['member']
    receipt = payload['receipt']
    force = payload['force']
    source_identity = payload['source_identity']
    grouping_directory = payload['grouping_directory']
    account = f"{payload['user']}:{payload['group']}"
    state, journal, receipt_path, quarantine, recovery = removal_paths(root, operation, member)

    require_state(state, journal, payload['digest'], account, 'refused')
    if source_identity == 'absent':
        require(not os.path.lexists(checkout), 'refused', 'the absent source is still absent')
        require(not os.path.lexists(quarantine), 'refused', 'an absent source has no quarantine')
        release_empty_grouping_directory(checkout, root, grouping_directory, account, 'refused')
        if not os.path.lexists(receipt_path):
            write_atomically(state, f'.{operation}.{member}.receipt.', receipt_path, receipt)
        require(is_regular_file(receipt_path), 'refused', 'the completion receipt')
        require(holds(receipt_path, receipt), 'refused', 'the completion receipt matches')
        return 'finalized', {'receipt': receipt}
    if not os.path.lexists(checkout) and not os.path.lexists(quarantine):
        require(is_regular_file(receipt_path), 'refused', 'the completion receipt')
        require(holds(receipt_path, receipt), 'refused', 'the completion receipt matches')
        release_empty_grouping_directory(checkout, root, grouping_directory, account, 'refused')
        return 'finalized', {'receipt': receipt}

    physical = checkout
    if os.path.lexists(checkout):
        require(not os.path.lexists(quarantine), 'refused', 'no quarantine beside the source')
        require(not os.path.lexists(receipt_path), 'refused', 'no completion receipt beside the source')
    else:
        physical = quarantine
        require(is_real_directory(quarantine), 'refused', 'the quarantine is a directory')
        if os.path.lexists(receipt_path):
            require(is_regular_file(receipt_path), 'refused', 'the completion receipt')
            require(holds(receipt_path, receipt), 'refused', 'the completion receipt matches')
    require(physical.startswith(root + '/'), 'refused', 'the source is inside the apps root')
    require(no_symlink_below(root, physical), 'refused', 'no symlink below the apps root')
    require(is_real_directory(physical) and canonical(physical), 'refused', 'the source is a canonical directory')
    require(identity(physical) == source_identity, 'refused', 'the source directory identity')
    require(owner(physical) == account, 'refused', 'the source owner')
    require(owner(dirname(physical)) == account, 'refused', 'the source parent owner')
    require(git_output('-C', physical, 'rev-parse', '--show-toplevel') == physical, 'refused', 'the work tree top')
    git_dir = git_output('-C', physical, 'rev-parse', '--absolute-git-dir')
    common = git_output('-C', physical, 'rev-parse', '--path-format=absolute', '--git-common-dir')
    require(git_dir is not None and common is not None, 'refused', 'read the Git layout')
    if layout == 'checkout':
        require(is_real_directory(f'{physical}/.git'), 'refused', 'the checkout has its own Git directory')
        require(git_dir == f'{physical}/.git' and common == f'{physical}/.git', 'refused', 'the checkout shares no Git directory')
    elif layout == 'worktree':
        require(is_regular_file(f'{physical}/.git'), 'refused', 'the worktree has a Git file')
        require(git_dir != common, 'refused', 'the worktree has its own administration directory')
        require(dirname(common) == common_repository, 'refused', 'the worktree belongs to the recorded repository')
        require(is_real_directory(f'{common_repository}/.git'), 'refused', 'the common Git directory')
    else:
        raise Outcome('refused')
    branch = git_output('-C', physical, 'symbolic-ref', '--quiet', '--short', 'HEAD') or ''
    if not payload['unresolved']:
        require(branch == payload['branch'], 'refused', 'the checked-out branch')
    commit = git_output('-C', physical, 'rev-parse', '--verify', 'HEAD^{commit}', quiet=True) or ZERO_COMMIT
    require(commit == payload['commit'], 'refused', 'the HEAD commit')
    origin = origin_url(physical)
    require(origin is not None and origin == payload['origin'], 'refused', 'the recorded origin')
    if layout != 'worktree' or not force or not payload['independent_worktree']:
        listing = base64.b64encode(worktree_inventory(physical)).decode()
        require(listing == payload['worktrees'], 'refused', 'the accepted linked-worktree inventory')
    if not force:
        require(clean(physical) is True, 'refused', 'the source is clean')
        require(is_published(origin, payload['commit'], payload['git_read'], 'refused'), 'refused', 'the HEAD is published')

    # A rename keeps the checkout's device and inode only within one filesystem. Across a mount, such as a task VM
    # workspace volume, a move copies and deletes, and the identity check below would fail after the copy.
    if physical == checkout and not same_filesystem(checkout, state):
        log(
            f'cross-device: the checkout [{checkout}] and [{state}] are on different filesystems; '
            'Orbit does not move a checkout across a mount.'
        )
        return 'cross_device', {}
    require(stop_tree_processes(physical, payload['own_units']), 'refused', 'stop the processes inside the source')
    if physical == checkout:
        if layout == 'worktree':
            moved = git(f'--git-dir={common_repository}/.git', 'worktree', 'move', checkout, quarantine).returncode == 0
        else:
            moved = run('mv', '--', checkout, quarantine)
        require(moved, 'refused', 'move the source into quarantine')
        physical = quarantine
    require(is_real_directory(physical), 'refused', 'the quarantine is a directory')
    require(identity(physical) == source_identity, 'refused', 'the quarantine directory identity')
    if layout == 'checkout':
        require(not os.path.lexists(recovery), 'refused', 'a checkout has no recovery record')
    else:
        git_dir = git_output('-C', physical, 'rev-parse', '--absolute-git-dir')
        common_git = f'{common_repository}/.git'
        worktrees = f'{common_git}/worktrees'
        require(git_dir is not None and dirname(git_dir) == worktrees, 'refused', 'the worktree administration directory')
        for path, check in ((common_git, 'the common Git directory'), (worktrees, 'the worktree directory'), (git_dir, 'the administration directory')):
            require(is_real_directory(path), 'refused', check)
            require(owner(path) == account, 'refused', f'{check} owner')
        record = '\n'.join((
            base64.b64encode(os.fsencode(git_dir)).decode(),
            identity(git_dir),
            identity(common_git),
            identity(worktrees),
        ))
        if os.path.lexists(recovery):
            require(is_regular_file(recovery), 'refused', 'the recovery record')
            require(owner(recovery) == account, 'refused', 'the recovery record owner')
            require(holds(recovery, record), 'refused', 'the recovery record matches')
        else:
            write_atomically(state, f'.{operation}.{member}.recovery.', recovery, record)
    if not os.path.lexists(receipt_path):
        write_atomically(state, f'.{operation}.{member}.receipt.', receipt_path, receipt)
    else:
        require(is_regular_file(receipt_path), 'refused', 'the completion receipt')
        require(owner(receipt_path) == account, 'refused', 'the completion receipt owner')
        require(holds(receipt_path, receipt), 'refused', 'the completion receipt matches')
    remove_source_tree(quarantine, common_repository if layout == 'worktree' else None, 'refused')
    require(not os.path.lexists(quarantine), 'refused', 'the quarantine is gone')
    release_empty_grouping_directory(checkout, root, grouping_directory, account, 'refused')
    return 'finalized', {'receipt': receipt}


run_program(main)
