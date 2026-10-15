# Program: instances.clean-removal-receipt
# Uses: lib/program.py lib/source-removal.py
# Outcomes: cleaned refused
# Timeout: 900
# Input: {"checkout": path, "root": apps root, "common_repository": the repository that holds the Git directory,
#   "layout": "checkout" or "worktree", "operation": removal ID, "member": member ID, "digest": accepted source digest,
#   "receipt": expected completion receipt, "user", "group", "source_identity": "<device>:<inode>",
#   "grouping_directory": the checkout's parent}
# Result: cleaned: {"receipt": the completion receipt}. refused: the journal, receipt, or recovery evidence does not
#   match; its log line names the check, and nothing more is deleted.
# Finishes a receipt-backed removal: it deletes what is left of the quarantine and, for a worktree, only its recorded
# Git administration entry. The receipt authorizes cleanup even when the quarantine is no longer a valid repository.
import os


def main(payload):
    checkout = payload['checkout']
    common_repository = payload['common_repository']
    source_identity = payload['source_identity']
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
        require(identity(quarantine) == source_identity, 'refused', 'the quarantine directory identity')
        require(owner(quarantine) == account, 'refused', 'the quarantine owner')

    if payload['layout'] == 'checkout':
        require(quarantine_present, 'refused', 'a checkout keeps its quarantine until cleanup')
        require(not os.path.lexists(recovery), 'refused', 'a checkout has no recovery record')
        require(identity(quarantine) == source_identity, 'refused', 'the quarantine directory identity')
        require(owner(quarantine) == account, 'refused', 'the quarantine owner')
        remove_source_tree(quarantine, outcome='refused')
    elif payload['layout'] == 'worktree':
        require(is_regular_file(recovery), 'refused', 'the recovery record')
        require(owner(recovery) == account, 'refused', 'the recovery record owner')
        admin, admin_identity, common_identity, worktrees_identity = recovery_record(recovery, common_repository, 'refused')
        common_git = f'{common_repository}/.git'
        worktrees = f'{common_git}/worktrees'
        require(is_real_directory(common_git), 'refused', 'the common Git directory')
        require(identity(common_git) == common_identity, 'refused', 'the common Git directory identity')
        require(owner(common_git) == account, 'refused', 'the common Git directory owner')
        require(is_real_directory(worktrees), 'refused', 'the worktree directory')
        require(identity(worktrees) == worktrees_identity, 'refused', 'the worktree directory identity')
        require(owner(worktrees) == account, 'refused', 'the worktree directory owner')
        matching = worktree_entries(worktrees, checkout, quarantine, admin, 'refused')
        if os.path.lexists(admin):
            require(is_real_directory(admin), 'refused', 'the administration directory')
            require(identity(admin) == admin_identity, 'refused', 'the administration directory identity')
            require(owner(admin) == account, 'refused', 'the administration directory owner')
            if os.path.lexists(f'{admin}/gitdir'):
                require(is_regular_file(f'{admin}/gitdir'), 'refused', 'the administration gitdir file')
                require(holds(f'{admin}/gitdir', f'{quarantine}/.git'), 'refused', 'the administration points at the quarantine')
                require(matching == 1, 'refused', 'one worktree entry for the quarantine')
            else:
                require(matching == 0, 'refused', 'no other worktree entry for the source')
            if os.path.lexists(f'{quarantine}/.git'):
                require(is_regular_file(f'{quarantine}/.git'), 'refused', 'the quarantine Git file')
                require(holds(f'{quarantine}/.git', f'gitdir: {admin}'), 'refused', 'the quarantine Git file points at the administration')
            require(identity(admin) == admin_identity, 'refused', 'the administration directory identity')
            require(owner(admin) == account, 'refused', 'the administration directory owner')
            require(run('rm', '-rf', '--', admin), 'refused', f'delete {admin}')
        else:
            require(matching == 0, 'refused', 'no other worktree entry for the source')
        if quarantine_present:
            require(identity(quarantine) == source_identity, 'refused', 'the quarantine directory identity')
            require(owner(quarantine) == account, 'refused', 'the quarantine owner')
            remove_source_tree(quarantine, outcome='refused')
    else:
        raise Outcome('refused')
    require(not os.path.lexists(quarantine), 'refused', 'the quarantine is gone')
    release_empty_grouping_directory(checkout, payload['root'], payload['grouping_directory'], account, 'refused')
    return 'cleaned', {'receipt': payload['receipt']}


run_program(main)
