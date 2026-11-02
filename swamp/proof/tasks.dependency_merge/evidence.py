"""Public GitHub evidence for the disposable native and topology fixtures."""
import base64
import json

HEAD, BASE, MERGE = 'a' * 40, 'b' * 40, 'c' * 40


def evidence(repository='hardimpactdev/orbit', group=91, case='success'):
    root = '/repos/' + repository
    pr = {'number': 3, 'state': 'open', 'merged': case == 'merged', 'merge_commit_sha': MERGE if case == 'merged' else None,
          'draft': False, 'mergeable': True, 'mergeable_state': 'clean', 'changed_files': 1,
          'head': {'sha': HEAD, 'ref': f'task-{group}', 'repo': {'full_name': repository}},
          'base': {'sha': BASE, 'ref': 'main', 'repo': {'full_name': repository}}}
    rows = {
        root + '/pulls/3': pr,
        root: {'default_branch': 'main', 'archived': False, 'allow_squash_merge': True},
        root + '/pulls/3/files?per_page=100': [[{'filename': 'package.json', 'status': 'modified'}]],
        root + '/actions/workflows?per_page=100': {'total_count': 2, 'workflows': [
            {'id': 1, 'path': '.github/workflows/ci.yml', 'state': 'active'},
            {'id': 2, 'path': '.github/workflows/production-smoke.yml', 'state': 'active'}]},
        root + f'/actions/runs?head_sha={HEAD}&event=pull_request&per_page=100': {'total_count': 2, 'workflow_runs': [
            {'id': i, 'workflow_id': i, 'check_suite_id': i, 'head_sha': HEAD, 'head_branch': f'task-{group}',
             'event': 'pull_request', 'status': 'completed', 'conclusion': 'success'} for i in (1, 2)]},
        root + f'/commits/{HEAD}/check-runs?per_page=100': [{'total_count': 2, 'check_runs': [
            {'id': i, 'name': name, 'head_sha': HEAD, 'app': {'slug': 'github-actions'}, 'check_suite': {'id': i},
             'status': 'in_progress' if case == 'checks' else 'completed', 'conclusion': 'success'}
            for i, name in enumerate(('Required checks', 'smoke'), 1)]}],
    }
    for commit in (HEAD, BASE):
        value = json.dumps({'dependencies': {'library': '^2.0' if case == 'major' and commit == HEAD else '^1.1' if commit == HEAD else '^1.0'}})
        rows[root + f'/contents/package.json?ref={commit}'] = {'type': 'file', 'path': 'package.json', 'encoding': 'base64', 'size': len(value), 'content': base64.b64encode(value.encode()).decode()}
    return rows
