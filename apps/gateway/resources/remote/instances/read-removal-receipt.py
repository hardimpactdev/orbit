# Program: instances.read-removal-receipt
# Uses: lib/program.py lib/source-removal.py
# Outcomes: complete incomplete intact refused
# Timeout: 900
# Input: {"checkout": path, "root": apps root, "common_repository": the repository that holds the Git directory,
#   "layout": "checkout" or "worktree", "operation": removal ID, "member": member ID, "digest": accepted source digest,
#   "receipt": expected completion receipt, "user", "group", "source_identity": "<device>:<inode>"}
# Result: no data. complete: nothing of the source is left. incomplete: cleanup must finish. intact: a quarantined
#   worktree still has its whole Git structure. refused: the journal, receipt, or recovery evidence does not match;
#   its log line names the check.
# Reports how much of a receipt-backed removal is left. The receipt authorizes cleanup even when a partial deletion
# left a damaged Git directory. It changes nothing.
import os


def main(payload):
    checkout = payload['checkout']
    common_repository = payload['common_repository']
    account = f"{payload['user']}:{payload['group']}"
    state, journal, receipt_path, quarantine, recovery = removal_paths(
        payload['root'], payload['operation'], payload['member'],
    )

    require_state(state, journal, payload['digest'], account, 'refused')
    require(is_regular_file(receipt_path), 'refused', 'the completion receipt')
    require(owner(receipt_path) == account, 'refused', 'the completion receipt owner')
    require(holds(receipt_path, payload['receipt']), 'refused', 'the completion receipt matches')
    require(not os.path.lexists(checkout), 'refused', 'the source left its path')
    quarantine_present = os.path.lexists(quarantine)
    if quarantine_present:
        require(is_real_directory(quarantine) and canonical(quarantine), 'refused', 'the quarantine directory')
        require(identity(quarantine) == payload['source_identity'], 'refused', 'the quarantine directory identity')
        require(owner(quarantine) == account, 'refused', 'the quarantine owner')

    if payload['layout'] == 'checkout':
        require(quarantine_present, 'refused', 'a checkout keeps its quarantine until cleanup')
        require(not os.path.lexists(recovery), 'refused', 'a checkout has no recovery record')
        return 'incomplete', {}
    require(payload['layout'] == 'worktree', 'refused', 'a known source layout')

    require(is_regular_file(recovery), 'refused', 'the recovery record')
    require(owner(recovery) == account, 'refused', 'the recovery record owner')
    admin, admin_identity, common_identity, worktrees_identity = recovery_record(recovery, common_repository, 'refused')
    common_git = f'{common_repository}/.git'
    worktrees = f'{common_git}/worktrees'
    common_present = os.path.lexists(common_git)
    if common_present:
        require(is_real_directory(common_git), 'refused', 'the common Git directory')
        require(identity(common_git) == common_identity, 'refused', 'the common Git directory identity')
        require(owner(common_git) == account, 'refused', 'the common Git directory owner')
    else:
        require(not os.path.lexists(common_repository), 'refused', 'the common repository is gone with its Git directory')
    worktrees_present = os.path.lexists(worktrees)
    if worktrees_present:
        require(common_present, 'refused', 'the worktree directory is inside the common Git directory')
        require(is_real_directory(worktrees), 'refused', 'the worktree directory')
        require(identity(worktrees) == worktrees_identity, 'refused', 'the worktree directory identity')
        require(owner(worktrees) == account, 'refused', 'the worktree directory owner')
    matching = worktree_entries(worktrees, checkout, quarantine, admin, 'refused') if worktrees_present else 0
    admin_present = os.path.lexists(admin)
    admin_complete = False
    if admin_present:
        require(is_real_directory(admin), 'refused', 'the administration directory')
        require(identity(admin) == admin_identity, 'refused', 'the administration directory identity')
        require(owner(admin) == account, 'refused', 'the administration directory owner')
        if os.path.lexists(f'{admin}/gitdir'):
            require(is_regular_file(f'{admin}/gitdir'), 'refused', 'the administration gitdir file')
            require(holds(f'{admin}/gitdir', f'{quarantine}/.git'), 'refused', 'the administration points at the quarantine')
            require(matching == 1, 'refused', 'one worktree entry for the quarantine')
            admin_complete = True
        else:
            require(matching == 0, 'refused', 'no other worktree entry for the source')
    else:
        require(matching == 0, 'refused', 'no other worktree entry for the source')
    git_file_present = os.path.lexists(f'{quarantine}/.git')
    if git_file_present:
        require(is_regular_file(f'{quarantine}/.git'), 'refused', 'the quarantine Git file')
        require(holds(f'{quarantine}/.git', f'gitdir: {admin}'), 'refused', 'the quarantine Git file points at the administration')
    if not quarantine_present:
        return ('incomplete' if admin_present else 'complete'), {}
    if admin_complete and git_file_present:
        return 'intact', {}
    return 'incomplete', {}


run_program(main)
