# Existing installed loader excerpt; patch fixture, no runtime execution.
def consume_accepted_feed(manifest, head):
    """Retire only a manifest whose exact revision has an accepted proof."""
    proof = STATE / 'last-proof.json'
    if manifest['target'] != head or not proof.exists():
        return False
    accepted = json.loads(proof.read_text())
    audit = accepted.get('behavior_versions', {})
    if accepted.get('loaded_sha') != head or not audit.get('checked') or audit.get('checked') != audit.get('matched') or audit.get('errors') or audit.get('mismatches'):
        return False
    path = FEED / 'ready.json'
    assert json.loads(path.read_text()) == manifest, 'feed_changed_under_lock'
    atomic(STATE / 'last-consumed-feed.json', {'at': now(), 'accepted_sha': head, 'manifest': manifest})
    path.unlink()
    return True
