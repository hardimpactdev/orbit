# Program: instances.prepare-source-removal
# Uses: lib/program.py lib/source-removal.py
# Outcomes: prepared refused
# Timeout: 900
# Input: {"root": apps root, "operation": removal ID, "member": member ID, "digest": accepted source digest,
#   "user", "group", "checkout": path, "common_repository": the repository that holds the Git directory,
#   "layout": "checkout" or "worktree", "source_identity": "<device>:<inode>" or "absent"}
# Result: prepared: the member's journal holds the digest. refused: a check failed; its log line names it.
# Records the removal journal in `<root>/.orbit-removals` before finalization. A retry with the same digest succeeds.
import os


def main(payload):
    root = payload['root']
    account = f"{payload['user']}:{payload['group']}"
    checkout = payload['checkout']
    operation, member = payload['operation'], payload['member']
    state, journal, receipt, quarantine, recovery = removal_paths(root, operation, member)

    require(is_real_directory(root) and canonical(root), 'refused', 'the apps root is a canonical directory')
    require(owner(root) == account, 'refused', 'the apps root owner')
    if os.path.lexists(state):
        require(is_real_directory(state) and canonical(state), 'refused', 'the removal state directory')
    else:
        os.mkdir(state, 0o700)
        os.chmod(state, 0o700)
    require(owner(state) == account, 'refused', 'the removal state directory owner')
    for path in (receipt, quarantine, recovery):
        require(not os.path.lexists(path), 'refused', f'no {path} yet')
    if payload['source_identity'] == 'absent':
        # Authenticated absence owns no Git directory, including in a seed repository.
        require(not os.path.lexists(checkout), 'refused', 'the absent source is still absent')
    elif payload['layout'] == 'worktree':
        require(is_regular_file(f'{checkout}/.git'), 'refused', 'the worktree has a Git file')
        git_dir = git_output('-C', checkout, 'rev-parse', '--absolute-git-dir')
        require(git_dir is not None, 'refused', 'read the worktree administration directory')
        require(
            dirname(git_dir) == f"{payload['common_repository']}/.git/worktrees",
            'refused',
            'the worktree belongs to the recorded repository',
        )
        require(is_real_directory(git_dir), 'refused', 'the worktree administration directory')
    else:
        require(payload['layout'] == 'checkout', 'refused', 'a known source layout')
    if os.path.lexists(journal):
        require(is_regular_file(journal), 'refused', 'the removal journal')
        require(holds(journal, payload['digest']), 'refused', 'the removal journal digest')
        return 'prepared', {}
    write_atomically(state, f'.{operation}.{member}.journal.', journal, payload['digest'])
    return 'prepared', {}


run_program(main)
