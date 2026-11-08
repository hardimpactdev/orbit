"""All 50 firewall:allow cases, ported to native pytest functions. No runtime YAML interpreter."""
import pytest


@pytest.mark.proof(id='new-restricted-allow', batch='fresh-fleet', node='app-dev', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'app-dev', 'to': '${port}/tcp', 'from': '${gateway.src}'})], 'must_fail': ['gateway-connects']})
def test_new_restricted_allow(proof):
    """An address opens the port only for that source, ahead of a broad deny; matching uses the normalized source."""
    p = proof
    p.listener(on='app-dev', proto=['tcp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp'], port='${port}', expect='blocked')
    p.act('ufw', 'firewall_records', 'listeners')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(id='gateway-connects', source='gateway', target='app-dev', port='${port}', expect='connect')
    p.probe(source='app-prod', target='app-dev', port='${port}', expect='blocked')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', port='${control}', expect='connect')
    p.record(list='firewall:list', node='app-dev', where={'port': '${port}', 'action': 'allow', 'protocol': 'tcp', 'source': '${gateway.src}', 'status': 'active'}, count=1)
    p.ufw(on='app-dev', family=4, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${gateway.src}'}, {'ref': 'deny'}])
    p.unchanged(since='act', excluding={'port': '${port}'})
    p.doctor(node='app-dev', drift=0)
    p.finish()


@pytest.mark.proof(id='already-applied', batch='fresh-fleet', node='app-prod', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'app-prod', 'to': '${port}/tcp', 'from': '${gateway.src}'})], 'must_fail': ['gateway-connects']})
def test_already_applied(proof):
    """A repeat checks the Node, succeeds as skipped in both renderings, and changes no rule or UFW line."""
    p = proof
    p.listener(on='app-prod', proto=['tcp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-prod', action='deny', proto=['tcp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-dev'], target='app-prod', family=4, proto=['tcp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-dev'], target='app-prod', family=4, proto=['tcp'], port='${port}', expect='blocked')
    p.command(run=['firewall:allow', '${port}', '--node=${app-prod.id}', '--from=${gateway.src}', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.probe(source='gateway', target='app-prod', port='${port}', expect='connect')
    p.act('ufw', 'firewall_records')
    p.command(run=['firewall:allow', '${port}', '--node=${app-prod.id}', '--from=${gateway.src}', '--protocol=tcp'], forms=['json', 'human'], expect={'exit': 0, 'status': 'succeeded', 'skipped': 'firewall.rule_exists', 'human': 'Skipped — already applied (firewall.rule_exists)'})
    p.observe()
    p.record(list='firewall:list', node='app-prod', where={'port': '${port}', 'action': 'allow', 'status': 'active'}, count=1)
    p.ufw(on='app-prod', family=4, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${gateway.src}'}, {'ref': 'deny'}])
    p.probe(id='gateway-connects', source='gateway', target='app-prod', port='${port}', expect='connect')
    p.probe(source='app-dev', target='app-prod', port='${port}', expect='blocked')
    p.unchanged(since='act', what=['ufw', 'firewall_records'])
    p.finish()


@pytest.mark.proof(id='partial-application', batch='fresh-fleet', node='gateway', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'gateway', 'to': '${port}/udp', 'from': '${app-dev.src}'})], 'must_fail': ['gateway-connects']})
def test_partial_application(proof):
    """An existing TCP allow is kept and the missing UDP allow is added instead of reporting a skip."""
    p = proof
    p.listener(on='gateway', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='gateway', action='deny', proto=['tcp', 'udp'], port='${port}', above='proof-network')
    p.probe(source=['app-dev', 'app-prod'], target='gateway', family=4, proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.probe(source=['app-dev', 'app-prod'], target='gateway', family=4, proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.ufw_rule(name='existing', on='gateway', action='allow', proto='tcp', port='${port}', source='${app-dev.src}', above='deny')
    p.probe(source='app-dev', target='gateway', proto='tcp', port='${port}', expect='connect')
    p.probe(source='app-dev', target='gateway', proto='udp', port='${port}', expect='blocked')
    p.act('ufw', 'firewall_records', 'listeners')
    p.command(run=['firewall:allow', '${port}', '--node=${gateway.id}', '--from=${app-dev.src}'], expect={'exit': 0, 'status': 'succeeded', 'steps': {'name': 'Change the rules', 'count': 1}})
    p.observe()
    p.probe(id='gateway-connects', source='app-dev', target='gateway', proto=['tcp', 'udp'], port='${port}', expect='connect')
    p.probe(source='app-prod', target='gateway', proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.ufw(on='gateway', family=4, rows=[{'ref': 'existing'}, {'ref': 'deny'}])
    p.ufw(on='gateway', family=4, rows=[{'to': '${port}/udp', 'action': 'ALLOW', 'from': '${app-dev.src}'}, {'ref': 'deny'}])
    p.record(list='firewall:list', node='gateway', where={'port': '${port}', 'action': 'allow', 'protocol': 'any', 'status': 'active'}, count=1)
    p.unchanged(since='act', excluding={'port': '${port}'})
    p.finish()


@pytest.mark.proof(id='interrupted-reorder', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_interrupted_reorder(proof):
    """The command stops after it deleted a misplaced line. The failure stays visible and a repeat puts the line back in place."""
    p = proof
    p.listener(on='app-dev', proto=['tcp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp'], port='${port}', expect='blocked')
    p.ufw_rule(name='misplaced', on='app-dev', action='allow', proto='tcp', port='${port}', source='${gateway.src}', below='deny')
    p.fault(on='app-dev', command='ufw', fail_after={'match': 'delete', 'count': 1})
    p.act('ufw', 'firewall_records', 'listeners')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=tcp'], expect={'status': 'failed'})
    p.observe()
    p.record(list='firewall:list', node='app-dev', where={'port': '${port}', 'status': 'failed'}, count=1)
    p.ufw(on='app-dev', family=4, rows=[{'ref': 'deny'}], absent=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${gateway.src}'}])
    p.unchanged(since='act', excluding={'port': '${port}'})
    p.act('ufw', 'firewall_records', 'listeners')
    p.fault(clear=True)
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.record(list='firewall:list', node='app-dev', where={'port': '${port}', 'status': 'active'}, count=1)
    p.ufw(on='app-dev', family=4, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${gateway.src}'}, {'ref': 'deny'}])
    p.probe(source='gateway', target='app-dev', port='${port}', expect='connect')
    p.unchanged(since='act', excluding={'port': '${port}'})
    p.finish()


@pytest.mark.proof(id='conflict', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_conflict(proof):
    """A deny with exactly the same normalized scope refuses the allow without a new record or Operation."""
    p = proof
    p.listener(on='app-dev', proto=['tcp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp'], port='${port}', expect='blocked')
    p.command(run=['firewall:deny', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}/32', '--protocol=tcp'], expect={'exit': 1, 'code': 'firewall.rule_conflict', 'request': 'none'})
    p.observe()
    p.probe(source=['gateway', 'app-prod'], target='app-dev', port='${port}', expect='blocked')
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='busy-target', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_busy_target(proof):
    """While another firewall write runs on the Node, a second one is refused and touches nothing."""
    p = proof
    p.listener(on='app-dev', proto=['tcp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp'], port='${port}', expect='blocked')
    p.busy_node(on='app-dev')
    p.act('ufw', 'firewall_records')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=tcp'], expect={'exit': 1, 'code': 'operation.target_busy', 'request': 'none'})
    p.observe()
    p.probe(source='gateway', target='app-dev', port='${port}', expect='blocked')
    p.unchanged(since='act', what=['ufw', 'firewall_records'])
    p.finish()


@pytest.mark.proof(id='protocols[tcp]', batch='fresh-fleet', node='app-dev', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'app-dev', 'to': '${port}/tcp', 'from': '${gateway.src}'})], 'must_fail': ['gateway-connects']})
def test_protocols__tcp(proof):
    """TCP, UDP and omitted protocol open exactly the selected transports for one source; the other source stays blocked."""
    p = proof
    p.listener(on='app-dev', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp', 'udp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.act()
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(id='gateway-connects', source='gateway', target='app-dev', proto=['tcp'], port='${port}', expect='connect')
    p.probe(source='app-prod', target='app-dev', proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.record(list='firewall:list', node='app-dev', where={'port': '${port}', 'action': 'allow', 'protocol': 'tcp', 'source': '${gateway.src}', 'status': 'active'}, count=1)
    p.ufw(on='app-dev', family=4, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${gateway.src}'}, {'ref': 'deny'}])
    p.probe(source=['gateway', 'app-prod'], target='app-dev', proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.finish()


@pytest.mark.proof(id='protocols[udp]', batch='fresh-fleet', node='app-prod', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'app-prod', 'to': '${port}/udp', 'from': '${gateway.src}'})], 'must_fail': ['gateway-connects']})
def test_protocols__udp(proof):
    """TCP, UDP and omitted protocol open exactly the selected transports for one source; the other source stays blocked."""
    p = proof
    p.listener(on='app-prod', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-prod', action='deny', proto=['tcp', 'udp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-dev'], target='app-prod', family=4, proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-dev'], target='app-prod', family=4, proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.act()
    p.command(run=['firewall:allow', '${port}', '--node=${app-prod.id}', '--from=${gateway.src}', '--protocol=udp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(id='gateway-connects', source='gateway', target='app-prod', proto=['udp'], port='${port}', expect='connect')
    p.probe(source='app-dev', target='app-prod', proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.record(list='firewall:list', node='app-prod', where={'port': '${port}', 'action': 'allow', 'protocol': 'udp', 'source': '${gateway.src}', 'status': 'active'}, count=1)
    p.ufw(on='app-prod', family=4, rows=[{'to': '${port}/udp', 'action': 'ALLOW', 'from': '${gateway.src}'}, {'ref': 'deny'}])
    p.probe(source=['gateway', 'app-dev'], target='app-prod', proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.finish()


@pytest.mark.proof(id='protocols[both]', batch='fresh-fleet', node='gateway', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'gateway', 'to': '${port}/tcp', 'from': '${app-dev.src}'}), ('ufw_delete', {'on': 'gateway', 'to': '${port}/udp', 'from': '${app-dev.src}'})], 'must_fail': ['gateway-connects']})
def test_protocols__both(proof):
    """TCP, UDP and omitted protocol open exactly the selected transports for one source; the other source stays blocked."""
    p = proof
    p.listener(on='gateway', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='gateway', action='deny', proto=['tcp', 'udp'], port='${port}', above='proof-network')
    p.probe(source=['app-dev', 'app-prod'], target='gateway', family=4, proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.probe(source=['app-dev', 'app-prod'], target='gateway', family=4, proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.act()
    p.command(run=['firewall:allow', '${port}', '--node=${gateway.id}', '--from=${app-dev.src}'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(id='gateway-connects', source='app-dev', target='gateway', proto=['tcp', 'udp'], port='${port}', expect='connect')
    p.probe(source='app-prod', target='gateway', proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.record(list='firewall:list', node='gateway', where={'port': '${port}', 'action': 'allow', 'protocol': 'any', 'source': '${app-dev.src}', 'status': 'active'}, count=1)
    p.ufw(on='gateway', family=4, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${app-dev.src}'}, {'to': '${port}/udp', 'action': 'ALLOW', 'from': '${app-dev.src}'}, {'ref': 'deny'}])
    p.probe(source=['app-dev', 'app-prod'], target='gateway', proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.finish()


@pytest.mark.proof(id='family-ipv6', batch='dual-stack', node='app-dev', lock='node', timeout=180)
def test_family_ipv6(proof):
    """An IPv6 address opens that IPv6 source only and creates no IPv4 allow."""
    p = proof
    p.listener(on='app-dev', proto=['tcp'], family=6, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=6, proto=['tcp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=6, proto=['tcp'], port='${port}', expect='blocked')
    p.act()
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src6}', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(source='gateway', target='app-dev', family=6, port='${port}', expect='connect')
    p.probe(source='app-prod', target='app-dev', family=6, port='${port}', expect='blocked')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=6, port='${control}', expect='connect')
    p.record(list='firewall:list', node='app-dev', where={'port': '${port}', 'source': '${gateway.src6}', 'status': 'active'}, count=1)
    p.ufw(on='app-dev', family=6, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${gateway.src6}'}, {'ref': 'deny'}])
    p.ufw(on='app-dev', family=4, rows=[], absent=[{'to': '${port}/tcp', 'action': 'ALLOW'}])
    p.finish()


@pytest.mark.proof(id='port-range', batch='fresh-fleet', node='app-prod', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'app-prod', 'to': '${range.spec}/tcp', 'from': '${gateway.src}'})], 'must_fail': ['gateway-connects']})
def test_port_range(proof):
    """Both endpoints and the middle of a range open for one source; an outside port and the other source remain blocked."""
    p = proof
    p.listener(on='app-prod', proto='tcp', block={'as': 'range', 'size': 5})
    p.listener(on='app-prod', proto='tcp', ports=['outside', 'control'])
    p.ufw_rule(name='deny-range', on='app-prod', action='deny', proto='tcp', port='${range.spec}', above='proof-network')
    p.ufw_rule(name='deny-outside', on='app-prod', action='deny', proto='tcp', port='${outside}', above='proof-network')
    p.probe(source=['gateway', 'app-dev'], target='app-prod', port=['${range.lo}', '${range.mid}', '${range.hi}', '${outside}'], expect='blocked')
    p.probe(source=['gateway', 'app-dev'], target='app-prod', port='${control}', expect='connect')
    p.act('ufw', 'firewall_records', 'listeners')
    p.command(run=['firewall:allow', '${range.spec}', '--node=${app-prod.id}', '--from=${gateway.src}', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(id='gateway-connects', source='gateway', target='app-prod', port=['${range.lo}', '${range.mid}', '${range.hi}'], expect='connect')
    p.probe(source='gateway', target='app-prod', port='${outside}', expect='blocked')
    p.probe(source='app-dev', target='app-prod', port=['${range.lo}', '${range.mid}', '${range.hi}'], expect='blocked')
    p.probe(source=['gateway', 'app-dev'], target='app-prod', port='${control}', expect='connect')
    p.record(list='firewall:list', node='app-prod', where={'port': '${range.spec}', 'status': 'active'}, count=1)
    p.ufw(on='app-prod', family=4, rows=[{'to': '${range.spec}/tcp', 'action': 'ALLOW', 'from': '${gateway.src}'}, {'ref': 'deny-range'}])
    p.unchanged(since='act', excluding={'port': '${range.spec}'})
    p.finish()


@pytest.mark.proof(id='failure-after-partial-change', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_failure_after_partial_change(proof):
    """A partial failure keeps the failed record and applied line; an ordinary repeat completes the rule without duplicates."""
    p = proof
    p.listener(on='app-dev', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp', 'udp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.fault(on='app-dev', command='ufw', fail_after={'match': 'allow', 'count': 1})
    p.act('ufw', 'firewall_records', 'listeners')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}'], expect={'exit': 1, 'status': 'failed', 'code': 'operation.native_failed'})
    p.observe()
    p.record(list='firewall:list', node='app-dev', where={'port': '${port}', 'status': 'failed'}, count=1)
    p.ufw(on='app-dev', family=4, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${gateway.src}'}, {'ref': 'deny'}], absent=[{'to': '${port}/udp', 'action': 'ALLOW', 'from': '${gateway.src}'}])
    p.probe(source='gateway', target='app-dev', proto='tcp', port='${port}', expect='connect')
    p.probe(source='gateway', target='app-dev', proto='udp', port='${port}', expect='blocked')
    p.unchanged(since='act', excluding={'port': '${port}'})
    p.act('ufw', 'firewall_records', 'listeners')
    p.fault(clear=True)
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.record(list='firewall:list', node='app-dev', where={'port': '${port}', 'status': 'active'}, count=1)
    p.ufw(on='app-dev', family=4, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${gateway.src}'}, {'to': '${port}/udp', 'action': 'ALLOW', 'from': '${gateway.src}'}, {'ref': 'deny'}])
    p.probe(source='gateway', target='app-dev', proto=['tcp', 'udp'], port='${port}', expect='connect')
    p.probe(source='app-prod', target='app-dev', proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.unchanged(since='act', excluding={'port': '${port}'})
    p.finish()


@pytest.mark.proof(id='protected-port', batch='fresh-fleet', node='gateway', lock='node', timeout=180)
def test_protected_port(proof):
    """An allow contained in SSH 22 repeats protected access, succeeds as skipped in both forms, and creates no operator rule."""
    p = proof
    p.act('ufw', 'firewall_records')
    p.command(run=['firewall:allow', '22', '--node=${gateway.id}'], forms=['json', 'human'], expect={'exit': 0, 'status': 'succeeded', 'skipped': 'firewall.rule_exists', 'human': 'Skipped — already applied (firewall.rule_exists)'})
    p.observe()
    p.probe(source='app-dev', target='gateway', port=22, expect='connect')
    p.record(list='firewall:list', node='gateway', where={'port': '22', 'action': 'allow'}, count=0)
    p.unchanged(since='act', what=['ufw', 'firewall_records'])
    p.finish()


@pytest.mark.proof(id='busy-protected-port', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_busy_protected_port(proof):
    """Protected SSH access cannot bypass another firewall write holding the Node; refusal adds no rule or Operation and changes no UFW line."""
    p = proof
    p.busy_node(on='app-dev')
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '22', '--node=${app-dev.id}'], expect={'exit': 1, 'code': 'operation.target_busy', 'request': 'none'})
    p.observe()
    p.probe(source='gateway', target='app-dev', port=22, expect='connect')
    p.record(list='firewall:list', node='app-dev', where={'port': '22', 'action': 'allow'}, count=0)
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='missing-input-prompts', batch='fresh-fleet', node='app-dev', lock='node', timeout=180)
def test_missing_input_prompts(proof):
    """In a terminal, omitted input is prompted. An invalid port is asked again."""
    p = proof
    p.listener(on='app-dev', proto='tcp', ports=['port', 'port2'])
    p.act()
    p.command(run=['firewall:allow'], tty=[{'see': 'port', 'send': 'abc'}, {'see': 'port', 'send': '${port}'}, {'see': 'Node', 'choose': 'app-dev'}], expect={'exit': 0, 'status': 'succeeded'})
    p.command(run=['firewall:allow', '${port2}'], tty=[{'see': 'Node', 'choose': 'app-dev'}], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.record(list='firewall:list', node='app-dev', where={'port': ['${port}', '${port2}'], 'status': 'active'}, count=2)
    p.finish()


@pytest.mark.proof(id='missing-input-cancel', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_missing_input_cancel(proof):
    """Ctrl-C at a prompt ends the command without a request."""
    p = proof
    p.act('ufw', 'firewall_records')
    p.command(run=['firewall:allow'], tty=[{'see': 'port', 'send': '^C'}], expect={'exit': 1, 'code': 'input.cancelled', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records'])
    p.finish()


@pytest.mark.proof(id='missing-input-noninteractive', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_missing_input_noninteractive(proof):
    """An omitted Node fails in both noninteractive forms instead of inferring a target."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '5432'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.node_id_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='required-only', batch='fresh-fleet', node='app-prod', lock='node', timeout=180)
def test_required_only(proof):
    """Required inputs alone record an any-source both-protocol allow and produce IPv4 and IPv6 lines; traffic causality is uncovered above."""
    p = proof
    p.listener(on='app-prod', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.probe(source=['gateway', 'app-dev'], target='app-prod', family=4, proto=['tcp', 'udp'], port=['${port}', '${control}'], expect='connect')
    p.act('ufw', 'firewall_records', 'listeners')
    p.command(run=['firewall:allow', '${port}', '--node=${app-prod.id}'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(source=['gateway', 'app-dev'], target='app-prod', proto=['tcp', 'udp'], port=['${port}', '${control}'], expect='connect')
    p.record(list='firewall:list', node='app-prod', where={'port': '${port}', 'action': 'allow', 'protocol': 'any', 'source': 'any', 'status': 'active'}, count=1)
    p.ufw(on='app-prod', family=4, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': 'Anywhere'}, {'to': '${port}/udp', 'action': 'ALLOW', 'from': 'Anywhere'}])
    p.ufw(on='app-prod', family=6, rows=[{'to': '${port}/tcp (v6)', 'action': 'ALLOW', 'from': 'Anywhere (v6)'}, {'to': '${port}/udp (v6)', 'action': 'ALLOW', 'from': 'Anywhere (v6)'}])
    p.unchanged(since='act', excluding={'port': '${port}'})
    p.doctor(node='app-prod', drift=0)
    p.finish()


@pytest.mark.proof(id='protocol-isolation[tcp]', batch='fresh-fleet', node='gateway', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'gateway', 'to': '${port}/tcp', 'from': '${app-dev.src}'})], 'must_fail': ['gateway-connects']})
def test_protocol_isolation__tcp(proof):
    """Selecting one protocol leaves the other blocked for the allowed source too."""
    p = proof
    p.listener(on='gateway', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='gateway', action='deny', proto=['tcp', 'udp'], port='${port}', above='proof-network')
    p.probe(source=['app-dev', 'app-prod'], target='gateway', family=4, proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.probe(source=['app-dev', 'app-prod'], target='gateway', family=4, proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.act()
    p.command(run=['firewall:allow', '${port}', '--node=${gateway.id}', '--from=${app-dev.src}', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(id='gateway-connects', source='app-dev', target='gateway', proto='tcp', port='${port}', expect='connect')
    p.probe(source='app-dev', target='gateway', proto='udp', port='${port}', expect='blocked')
    p.probe(source='app-prod', target='gateway', proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.probe(source=['app-dev', 'app-prod'], target='gateway', proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.ufw(on='gateway', family=4, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${app-dev.src}'}], absent=[{'to': '${port}/udp', 'action': 'ALLOW', 'from': '${app-dev.src}'}])
    p.finish()


@pytest.mark.proof(id='protocol-isolation[udp]', batch='fresh-fleet', node='app-dev', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'app-dev', 'to': '${port}/udp', 'from': '${gateway.src}'})], 'must_fail': ['gateway-connects']})
def test_protocol_isolation__udp(proof):
    """Selecting one protocol leaves the other blocked for the allowed source too."""
    p = proof
    p.listener(on='app-dev', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp', 'udp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.act()
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=udp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(id='gateway-connects', source='gateway', target='app-dev', proto='udp', port='${port}', expect='connect')
    p.probe(source='gateway', target='app-dev', proto='tcp', port='${port}', expect='blocked')
    p.probe(source='app-prod', target='app-dev', proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.ufw(on='app-dev', family=4, rows=[{'to': '${port}/udp', 'action': 'ALLOW', 'from': '${gateway.src}'}], absent=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${gateway.src}'}])
    p.finish()


@pytest.mark.proof(id='broad-allow-keeps-narrow-deny', batch='fresh-fleet', node='app-prod', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'app-prod', 'to': '${port}/tcp', 'from': '${gateway.src}'})], 'must_fail': ['gateway-blocked']})
def test_broad_allow_keeps_narrow_deny(proof):
    """An explicit any-source allow preserves an existing narrower deny and sits below it."""
    p = proof
    p.listener(on='app-prod', proto=['tcp'], family=4, ports=['port', 'control'])
    p.probe(source=['gateway', 'app-dev'], target='app-prod', family=4, proto=['tcp'], port=['${port}', '${control}'], expect='connect')
    p.command(run=['firewall:deny', '${port}', '--node=${app-prod.id}', '--from=${gateway.src}', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.probe(source='gateway', target='app-prod', port='${port}', expect='blocked')
    p.probe(source='app-dev', target='app-prod', port='${port}', expect='connect')
    p.act()
    p.command(run=['firewall:allow', '${port}', '--node=${app-prod.id}', '--from=any', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(id='gateway-blocked', source='gateway', target='app-prod', port='${port}', expect='blocked')
    p.probe(source='app-dev', target='app-prod', port='${port}', expect='connect')
    p.probe(source=['gateway', 'app-dev'], target='app-prod', port='${control}', expect='connect')
    p.ufw(on='app-prod', family=4, rows=[{'to': '${port}/tcp', 'action': 'DENY', 'from': '${gateway.src}'}, {'to': '${port}/tcp', 'action': 'ALLOW', 'from': 'Anywhere'}])
    p.record(list='firewall:list', node='app-prod', where={'port': '${port}', 'action': 'allow', 'source': 'any', 'status': 'active'}, count=1)
    p.finish()


@pytest.mark.proof(id='label', batch='fresh-fleet', node='gateway', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'gateway', 'to': '${port}/tcp', 'from': '${app-dev.src}'})], 'must_fail': ['gateway-connects']})
def test_label(proof):
    """A label is recorded verbatim and new UFW comments use the sanitized label, not rule identity."""
    p = proof
    p.listener(on='gateway', proto=['tcp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='gateway', action='deny', proto=['tcp'], port='${port}', above='proof-network')
    p.probe(source=['app-dev', 'app-prod'], target='gateway', family=4, proto=['tcp'], port='${control}', expect='connect')
    p.probe(source=['app-dev', 'app-prod'], target='gateway', family=4, proto=['tcp'], port='${port}', expect='blocked')
    p.act()
    p.command(run=['firewall:allow', '${port}', '--node=${gateway.id}', '--from=${app-dev.src}', '--protocol=tcp', "--label=office's port"], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(id='gateway-connects', source='app-dev', target='gateway', port='${port}', expect='connect')
    p.probe(source='app-prod', target='gateway', port='${port}', expect='blocked')
    p.record(list='firewall:list', node='gateway', where={'port': '${port}', 'label': "office's port", 'status': 'active'}, count=1)
    p.ufw(on='gateway', family=4, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${app-dev.src}', 'comment': 'orbit offices port'}, {'ref': 'deny'}])
    p.finish()


@pytest.mark.proof(id='label-independent-repeat', batch='fresh-fleet', node='app-dev', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'app-dev', 'to': '${port}/tcp', 'from': '${gateway.src}'})], 'must_fail': ['gateway-connects']})
def test_label_independent_repeat(proof):
    """Changing only the label reuses one rule and skips correct UFW lines without changing their comments or order."""
    p = proof
    p.listener(on='app-dev', proto=['tcp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp'], port='${port}', expect='blocked')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=tcp', '--label=original'], expect={'exit': 0, 'status': 'succeeded'})
    p.probe(source='gateway', target='app-dev', port='${port}', expect='connect')
    p.act('ufw')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=tcp', '--label=revised'], forms=['json', 'human'], expect={'exit': 0, 'status': 'succeeded', 'skipped': 'firewall.rule_exists', 'human': 'Skipped — already applied (firewall.rule_exists)'})
    p.observe()
    p.record(list='firewall:list', node='app-dev', where={'port': '${port}'}, count=1)
    p.record(list='firewall:list', node='app-dev', where={'port': '${port}', 'action': 'allow', 'protocol': 'tcp', 'source': '${gateway.src}', 'label': 'revised', 'status': 'active'}, count=1)
    p.ufw(on='app-dev', family=4, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${gateway.src}', 'comment': 'orbit original'}, {'ref': 'deny'}])
    p.probe(id='gateway-connects', source='gateway', target='app-dev', port='${port}', expect='connect')
    p.probe(source='app-prod', target='app-dev', port='${port}', expect='blocked')
    p.unchanged(since='act', what=['ufw'])
    p.finish()


@pytest.mark.proof(id='any-source-ipv6', batch='dual-stack', node='app-prod', lock='node', timeout=180)
def test_any_source_ipv6(proof):
    """Omitting the source produces both IPv6 protocol lines without disrupting listeners; opening traffic remains an explicit proof gap."""
    p = proof
    p.listener(on='app-prod', proto=['tcp', 'udp'], family=6, ports=['port', 'control'])
    p.probe(source=['gateway', 'app-dev'], target='app-prod', family=6, proto=['tcp', 'udp'], port=['${port}', '${control}'], expect='connect')
    p.act()
    p.command(run=['firewall:allow', '${port}', '--node=${app-prod.id}'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(source=['gateway', 'app-dev'], target='app-prod', family=6, proto=['tcp', 'udp'], port=['${port}', '${control}'], expect='connect')
    p.ufw(on='app-prod', family=6, rows=[{'to': '${port}/tcp (v6)', 'action': 'ALLOW', 'from': 'Anywhere (v6)'}, {'to': '${port}/udp (v6)', 'action': 'ALLOW', 'from': 'Anywhere (v6)'}])
    p.finish()


@pytest.mark.proof(id='range-beyond-protected-port[extends-above]', batch='fresh-fleet', node='gateway', lock='node', timeout=180)
def test_range_beyond_protected_port__extends_above(proof):
    """A range extending past SSH 22 is a normal allow, not a protected-access skip or a protected-port refusal."""
    p = proof
    p.act('ufw', 'firewall_records', 'listeners')
    p.command(run=['firewall:allow', '22:23', '--node=${gateway.id}', '--from=${app-dev.src}', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.record(list='firewall:list', node='gateway', where={'port': '22:23', 'action': 'allow', 'source': '${app-dev.src}', 'protocol': 'tcp', 'status': 'active'}, count=1)
    p.ufw(on='gateway', family=4, rows=[{'to': '22:23/tcp', 'action': 'ALLOW', 'from': '${app-dev.src}'}])
    p.unchanged(since='act', excluding={'port': '22:23'})
    p.finish()


@pytest.mark.proof(id='range-beyond-protected-port[extends-below]', batch='fresh-fleet', node='app-dev', lock='node', timeout=180)
def test_range_beyond_protected_port__extends_below(proof):
    """A range extending past SSH 22 is a normal allow, not a protected-access skip or a protected-port refusal."""
    p = proof
    p.act('ufw', 'firewall_records', 'listeners')
    p.command(run=['firewall:allow', '21:22', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.record(list='firewall:list', node='app-dev', where={'port': '21:22', 'action': 'allow', 'source': '${gateway.src}', 'protocol': 'tcp', 'status': 'active'}, count=1)
    p.ufw(on='app-dev', family=4, rows=[{'to': '21:22/tcp', 'action': 'ALLOW', 'from': '${gateway.src}'}])
    p.unchanged(since='act', excluding={'port': '21:22'})
    p.finish()


@pytest.mark.proof(id='protocol-overlap[deny-both-allow-tcp]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_protocol_overlap__deny_both_allow_tcp(proof):
    """Both versus single protocol overlaps are refused for either action and in both creation orders."""
    p = proof
    p.listener(on='app-dev', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp', 'udp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.command(run=['firewall:deny', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}'], expect={'exit': 0, 'status': 'succeeded'})
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=tcp'], expect={'exit': 1, 'code': 'firewall.rule_overlap', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='protocol-overlap[deny-tcp-allow-both]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_protocol_overlap__deny_tcp_allow_both(proof):
    """Both versus single protocol overlaps are refused for either action and in both creation orders."""
    p = proof
    p.listener(on='app-dev', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp', 'udp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.command(run=['firewall:deny', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}'], expect={'exit': 1, 'code': 'firewall.rule_overlap', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='protocol-overlap[allow-both-allow-udp]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_protocol_overlap__allow_both_allow_udp(proof):
    """Both versus single protocol overlaps are refused for either action and in both creation orders."""
    p = proof
    p.listener(on='app-dev', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp', 'udp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}'], expect={'exit': 0, 'status': 'succeeded'})
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=udp'], expect={'exit': 1, 'code': 'firewall.rule_overlap', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='protocol-overlap[allow-udp-allow-both]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_protocol_overlap__allow_udp_allow_both(proof):
    """Both versus single protocol overlaps are refused for either action and in both creation orders."""
    p = proof
    p.listener(on='app-dev', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp', 'udp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=udp'], expect={'exit': 0, 'status': 'succeeded'})
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}'], expect={'exit': 1, 'code': 'firewall.rule_overlap', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-input[node-zero]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_input__node_zero(proof):
    """Invalid supplied input is refused locally in human and JSON forms without a rule or Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '5432', '--node=0'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.node_id_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-input[node-text]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_input__node_text(proof):
    """Invalid supplied input is refused locally in human and JSON forms without a rule or Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '5432', '--node=abc'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.node_id_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-input[port-zero]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_input__port_zero(proof):
    """Invalid supplied input is refused locally in human and JSON forms without a rule or Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '0', '--node=${app-dev.id}'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.port_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-input[port-high]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_input__port_high(proof):
    """Invalid supplied input is refused locally in human and JSON forms without a rule or Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '65536', '--node=${app-dev.id}'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.port_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-input[range-reversed]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_input__range_reversed(proof):
    """Invalid supplied input is refused locally in human and JSON forms without a rule or Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '8100:8000', '--node=${app-dev.id}'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.port_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-input[range-high]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_input__range_high(proof):
    """Invalid supplied input is refused locally in human and JSON forms without a rule or Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '65535:65536', '--node=${app-dev.id}'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.port_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-input[service-name]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_input__service_name(proof):
    """Invalid supplied input is refused locally in human and JSON forms without a rule or Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', 'postgres', '--node=${app-dev.id}'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.port_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-input[source-text]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_input__source_text(proof):
    """Invalid supplied input is refused locally in human and JSON forms without a rule or Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '5432', '--node=${app-dev.id}', '--from=not-an-address'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.source_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-input[cidr-v4]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_input__cidr_v4(proof):
    """Invalid supplied input is refused locally in human and JSON forms without a rule or Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '5432', '--node=${app-dev.id}', '--from=${gateway.src}/33'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.source_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-input[cidr-v6]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_input__cidr_v6(proof):
    """Invalid supplied input is refused locally in human and JSON forms without a rule or Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '5432', '--node=${app-dev.id}', '--from=::/129'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.source_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-input[protocol]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_input__protocol(proof):
    """Invalid supplied input is refused locally in human and JSON forms without a rule or Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '5432', '--node=${app-dev.id}', '--protocol=icmp'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.protocol_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-input[explicit-any-protocol]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_input__explicit_any_protocol(proof):
    """Invalid supplied input is refused locally in human and JSON forms without a rule or Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '5432', '--node=${app-dev.id}', '--protocol=any'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.protocol_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='invalid-request-input[long-label]', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_invalid_request_input__long_label(proof):
    """A label over 255 characters is refused before storing a rule."""
    p = proof
    p.listener(on='app-dev', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp', 'udp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=4, proto=['tcp', 'udp'], port='${port}', expect='blocked')
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--label=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'], expect={'exit': 1, 'code': 'validation.failed', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='missing-node-prompt', batch='fresh-fleet', node='app-prod', lock='node', timeout=180)
def test_missing_node_prompt(proof):
    """Supplying the port but no Node prompts for the target instead of inferring it or refusing."""
    p = proof
    p.listener(on='app-prod', proto=['tcp', 'udp'], family=4, ports=['port', 'control'])
    p.probe(source=['gateway', 'app-dev'], target='app-prod', family=4, proto=['tcp', 'udp'], port=['${port}', '${control}'], expect='connect')
    p.act()
    p.command(run=['firewall:allow', '${port}'], tty=[{'see': 'Node'}, {'see': '/ Search'}, {'see': 'ID'}, {'see': 'Name', 'choose': 'app-prod'}], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.record(list='firewall:list', node='app-prod', where={'port': '${port}', 'action': 'allow', 'source': 'any', 'protocol': 'any', 'status': 'active'}, count=1)
    p.probe(source=['gateway', 'app-dev'], target='app-prod', proto=['tcp', 'udp'], port='${control}', expect='connect')
    p.finish()


@pytest.mark.proof(id='source-host-cidr', batch='fresh-fleet', node='gateway', lock='node', timeout=180)
@pytest.mark.negative(**{'after': 'act', 'do': [('ufw_delete', {'on': 'gateway', 'to': '${port}/tcp', 'from': '${app-dev.src}'})], 'must_fail': ['gateway-connects']})
def test_source_host_cidr(proof):
    """A host CIDR opens the port only for that source, ahead of a broad deny; matching uses the normalized source."""
    p = proof
    p.listener(on='gateway', proto=['tcp'], family=4, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='gateway', action='deny', proto=['tcp'], port='${port}', above='proof-network')
    p.probe(source=['app-dev', 'app-prod'], target='gateway', family=4, proto=['tcp'], port='${control}', expect='connect')
    p.probe(source=['app-dev', 'app-prod'], target='gateway', family=4, proto=['tcp'], port='${port}', expect='blocked')
    p.act('ufw', 'firewall_records', 'listeners')
    p.command(run=['firewall:allow', '${port}', '--node=${gateway.id}', '--from=${app-dev.src}/32', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(id='gateway-connects', source='app-dev', target='gateway', port='${port}', expect='connect')
    p.probe(source='app-prod', target='gateway', port='${port}', expect='blocked')
    p.probe(source=['app-dev', 'app-prod'], target='gateway', port='${control}', expect='connect')
    p.record(list='firewall:list', node='gateway', where={'port': '${port}', 'action': 'allow', 'protocol': 'tcp', 'source': '${app-dev.src}', 'status': 'active'}, count=1)
    p.ufw(on='gateway', family=4, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${app-dev.src}'}, {'ref': 'deny'}])
    p.unchanged(since='act', excluding={'port': '${port}'})
    p.doctor(node='gateway', drift=0)
    p.finish()


@pytest.mark.proof(id='family-ipv6-host-cidr', batch='dual-stack', node='app-dev', lock='node', timeout=180)
def test_family_ipv6_host_cidr(proof):
    """An IPv6 host CIDR opens that IPv6 source only and creates no IPv4 allow."""
    p = proof
    p.listener(on='app-dev', proto=['tcp'], family=6, ports=['port', 'control'])
    p.ufw_rule(name='deny', on='app-dev', action='deny', proto=['tcp'], port='${port}', above='proof-network')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=6, proto=['tcp'], port='${control}', expect='connect')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=6, proto=['tcp'], port='${port}', expect='blocked')
    p.act()
    p.command(run=['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src6}/128', '--protocol=tcp'], expect={'exit': 0, 'status': 'succeeded'})
    p.observe()
    p.probe(source='gateway', target='app-dev', family=6, port='${port}', expect='connect')
    p.probe(source='app-prod', target='app-dev', family=6, port='${port}', expect='blocked')
    p.probe(source=['gateway', 'app-prod'], target='app-dev', family=6, port='${control}', expect='connect')
    p.record(list='firewall:list', node='app-dev', where={'port': '${port}', 'source': '${gateway.src6}', 'status': 'active'}, count=1)
    p.ufw(on='app-dev', family=6, rows=[{'to': '${port}/tcp', 'action': 'ALLOW', 'from': '${gateway.src6}'}, {'ref': 'deny'}])
    p.ufw(on='app-dev', family=4, rows=[], absent=[{'to': '${port}/tcp', 'action': 'ALLOW'}])
    p.finish()


@pytest.mark.proof(id='protected-contained-range', batch='fresh-fleet', node='app-prod', lock='node', timeout=180)
def test_protected_contained_range(proof):
    """An allow contained in SSH 22 repeats protected access, succeeds as skipped in both forms, and creates no operator rule."""
    p = proof
    p.act('ufw', 'firewall_records')
    p.command(run=['firewall:allow', '22:22', '--node=${app-prod.id}'], forms=['json', 'human'], expect={'exit': 0, 'status': 'succeeded', 'skipped': 'firewall.rule_exists', 'human': 'Skipped — already applied (firewall.rule_exists)'})
    p.observe()
    p.probe(source='gateway', target='app-prod', port=22, expect='connect')
    p.record(list='firewall:list', node='app-prod', where={'port': '22:22', 'action': 'allow'}, count=0)
    p.unchanged(since='act', what=['ufw', 'firewall_records'])
    p.finish()


@pytest.mark.proof(id='protected-source-tcp', batch='fresh-fleet', node='gateway', lock='node', timeout=180)
def test_protected_source_tcp(proof):
    """An allow contained in SSH 22 repeats protected access, succeeds as skipped in both forms, and creates no operator rule."""
    p = proof
    p.act('ufw', 'firewall_records')
    p.command(run=['firewall:allow', '22', '--node=${gateway.id}', '--from=${app-dev.src}', '--protocol=tcp'], forms=['json', 'human'], expect={'exit': 0, 'status': 'succeeded', 'skipped': 'firewall.rule_exists', 'human': 'Skipped — already applied (firewall.rule_exists)'})
    p.observe()
    p.probe(source='app-dev', target='gateway', port=22, expect='connect')
    p.record(list='firewall:list', node='gateway', where={'port': '22', 'action': 'allow'}, count=0)
    p.unchanged(since='act', what=['ufw', 'firewall_records'])
    p.finish()


@pytest.mark.proof(id='missing-port-noninteractive', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_missing_port_noninteractive(proof):
    """An omitted port fails in both noninteractive forms instead of inferring a target."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '--node=${app-dev.id}'], forms=['human', 'json'], expect={'exit': 1, 'code': 'firewall.port_invalid', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()


@pytest.mark.proof(id='missing-node-cancel', batch='fresh-fleet', node='app-dev', lock='all', timeout=180)
def test_missing_node_cancel(proof):
    """Ctrl-C at the Node prompt ends the command without an Operation."""
    p = proof
    p.act('ufw', 'firewall_records', 'operations')
    p.command(run=['firewall:allow', '5432'], tty=[{'see': 'Node', 'send': '^C'}], expect={'exit': 1, 'code': 'input.cancelled', 'request': 'none'})
    p.observe()
    p.unchanged(since='act', what=['ufw', 'firewall_records', 'operations'])
    p.finish()
