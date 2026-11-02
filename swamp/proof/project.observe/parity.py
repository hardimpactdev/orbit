"""Project observation reads captured intent, returns typed evidence and starts no Task group."""
import json
import os

with open(os.environ['PROOF_OPERATION_FILE']) as source:
    operation = json.load(source)
assert operation['kind'] == 'project.observe'
assert operation['status'] == 'succeeded'
assert operation['input']['project']['id'] == 32
assert operation['input']['project']['default_branch'] == 'main'
assert operation['input']['pr_urls'] == []
assert operation['result']['project_id'] == 32
assert operation['result']['observations'] == []
assert operation['result']['resume_group_ids'] == []
assert operation['result']['requests'] == []
assert operation['result']['admitted_group_ids'] == []
print('parity: opted-in Project observed without PR reads, clone, or Task admission')
