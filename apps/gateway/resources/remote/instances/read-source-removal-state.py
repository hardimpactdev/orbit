# Program: instances.read-source-removal-state
# Uses: lib/program.py lib/source-removal.py
# Outcomes: present quarantined receipt_pending_cleanup completed refused
# Timeout: 900
# Input: {"checkout": path, "root": apps root, "operation": removal ID, "member": member ID,
#   "digest": accepted source digest, "receipt": expected completion receipt, "source_identity": "<device>:<inode>"
#   or "absent", "user", "group", "grouping_directory": the checkout's parent}
# Result: the outcome is the source's state, with no data. refused: the journal, receipt, or source does not match the
#   accepted removal; its log line names the check.
# Reports whether a recorded source is still in place, in quarantine, waiting for receipt cleanup, or removed. For a
# removed source it also retries removal of the empty parent directory, because deletion and that cleanup are not
# atomic.
import os


def main(payload):
    checkout = payload['checkout']
    root = payload['root']
    account = f"{payload['user']}:{payload['group']}"
    source_identity = payload['source_identity']
    receipt = payload['receipt']
    state, journal, receipt_path, quarantine, recovery = removal_paths(root, payload['operation'], payload['member'])

    require_state(state, journal, payload['digest'], account, 'refused')
    if source_identity == 'absent':
        require(not os.path.lexists(checkout), 'refused', 'the absent source is still absent')
        require(not os.path.lexists(quarantine), 'refused', 'an absent source has no quarantine')
        if os.path.lexists(receipt_path):
            require(is_regular_file(receipt_path), 'refused', 'the completion receipt')
            require(holds(receipt_path, receipt), 'refused', 'the completion receipt matches')
            return 'completed', {}
        return 'present', {}
    if os.path.lexists(checkout):
        for path in (quarantine, receipt_path, recovery):
            require(not os.path.lexists(path), 'refused', f'no {path} beside the source')
        require(is_real_directory(checkout), 'refused', 'the source is a directory')
        require(identity(checkout) == source_identity, 'refused', 'the source directory identity')
        return 'present', {}
    if os.path.lexists(quarantine):
        require(is_real_directory(quarantine), 'refused', 'the quarantine is a directory')
        require(identity(quarantine) == source_identity, 'refused', 'the quarantine directory identity')
        require(owner(quarantine) == account, 'refused', 'the quarantine owner')
        if os.path.lexists(receipt_path):
            require(is_regular_file(receipt_path), 'refused', 'the completion receipt')
            require(holds(receipt_path, receipt), 'refused', 'the completion receipt matches')
            return 'receipt_pending_cleanup', {}
        return 'quarantined', {}
    require(is_regular_file(receipt_path), 'refused', 'the completion receipt')
    require(holds(receipt_path, receipt), 'refused', 'the completion receipt matches')
    require(owner(receipt_path) == account, 'refused', 'the completion receipt owner')
    # Deletion and the parent's removal are not atomic. Authenticate the completed removal, then retry that cleanup.
    release_empty_grouping_directory(checkout, root, payload['grouping_directory'], account, 'refused')
    return 'completed', {}


run_program(main)
