"""Real native DAG and real gatewayHttp/TLS with whole-scratch private-material scan.
Local host effects are fakes; shipping placement is checked before local targets are removed.
"""
import base64
import hashlib
import http.server
import json
import os
from pathlib import Path
import shutil
import ssl
import subprocess
import tempfile
import threading
import unittest
import urllib.parse
import uuid
import yaml
from native_harness import NativeTestCase, ROOT


class AnalyticsFixture(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/routes', 'operations/certificates', 'operations/analytics')
        self.private = tempfile.TemporaryDirectory(prefix='analytics-http-private-')
        self.addCleanup(self.private.cleanup)
        tls = Path(self.private.name)
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(tls/'key.pem'),'-out',str(tls/'ca.pem'),'-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1','-addext','basicConstraints=critical,CA:FALSE'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        self.secrets = tuple('analytics-'+purpose+'-'+uuid.uuid4().hex for purpose in ('password','stats','session'))
        self.secrets += tuple(base64.b64encode(value.encode()).decode() for value in self.secrets)
        self.status = 'queued'
        self.cancel = False
        self.empty = False
        self.failure = ''
        self.events = []
        self.projections = []
        self.created = False
        self.peer_reads = []
        self.removed = set()
        self.lock = threading.Lock()
        owner = self
        class Handler(http.server.BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"
            def log_message(self,*_): pass
            def do_GET(self): self.handle_request('GET')
            def do_POST(self): self.handle_request('POST')
            def do_PUT(self): self.handle_request('PUT')
            def reply(self,status=200,body=None,headers=None):
                encoded=(json.dumps(body) if isinstance(body,(dict,list)) else (body or '')).encode()
                self.send_response(status)
                self.send_header('Content-Length',str(len(encoded)))
                for key,value in (headers or {}).items(): self.send_header(key,value)
                self.send_header('Content-Type','application/json' if isinstance(body,(dict,list)) else 'text/html')
                self.end_headers()
                self.wfile.write(encoded)
            def handle_request(self,method):
                raw = self.rfile.read(int(self.headers.get('Content-Length',0)))
                data = json.loads(raw) if raw else {}
                url = urllib.parse.urlsplit(self.path)
                path = url.path
                query = urllib.parse.parse_qs(url.query)
                if path == '/fixture/effect':
                    name = data['name']
                    with owner.lock: owner.events.append(name)
                    if name == owner.failure: return self.reply(500,{'error':'bounded failure'})
                    if name == 'certificate.sign':
                        signed = [{'node_id':leaf['node_id'],'scope':leaf['scope'],'certificate_base64':base64.b64encode(b'-----BEGIN CERTIFICATE-----\npublic-leaf\n-----END CERTIFICATE-----').decode(),'root_certificate_base64':base64.b64encode(b'-----BEGIN CERTIFICATE-----\npublic-root\n-----END CERTIFICATE-----').decode()} for leaf in data['input']['leaves']]
                        return self.reply(body={'certificates':signed})
                    if name == 'certificate.request': return self.reply(body={'state':'request','request_base64':base64.b64encode(b'public-request').decode()})
                    if name == 'certificate.inspect': return self.reply(body={'present':data['input']['scope'] not in owner.removed})
                    if name == 'certificate.remove': owner.removed.add(data['input']['scope'])
                    if name in ('certificate.publish', 'certificate.remove'): return self.reply(body={'applied':True})
                    if name == 'caddy.publish':
                        with owner.lock: owner.events[-1] += ':'+str(data['input']['node_id'])
                    if name == 'firewall.publish': return self.reply(body={'matches':True})
                    return self.reply(body={'ready':True})
                if path.startswith('/api/v1/direct-operations/7'):
                    if not query.get('run_id') and method == 'GET': return self.reply(422,{'error':{'code':'missing.run'}})
                    if path.endswith('/private-material'):
                        if query.get('participant'): return self.reply(403,{'error':'root-only'})
                        if owner.failure=='private-http': return self.reply(503,{'error':owner.secrets[0]})
                        with owner.lock: owner.events.append('private-material')
                        return self.reply(body={'data':{'plausible_credentials':{'email':'native@example.test','password':owner.secrets[0],'apiKey':owner.secrets[1]}}})
                    if path.endswith('/primitive'):
                        return self.reply(500,{'error':'Gateway signing primitive was removed'})
                    if path.endswith('/result'):
                        if query.get('participant'): return self.reply(403,{'error':'root-only'})
                        owner.status = data['status']
                        owner.projections.append(data)
                        with owner.lock: owner.events.append('project:'+data['status']+(':'+next(iter(data.get('result',{})),'') if data.get('result') else ''))
                        return self.reply(body={'data':{}})
                    view = {'instance_id':1,'enabled':owner.kind=='analytics.enable','base_url':owner.plausible_url,'site':{'domain':'example.test','previous_domain':None,'timezone':'UTC','goals':[]},'tracking':{'certificates':owner.leaves(),'cleanup':owner.leaves(cleanup=True),'caddy_node_ids':[] if owner.empty else [14,17],'dns_node_ids':[] if owner.empty else [1], 'router_node_ids':[] if owner.empty or owner.kind=='analytics.disable' else [14], 'ingress_node_ids':[] if owner.empty or owner.kind=='analytics.disable' else [17], 'public_verify_node_ids':[] if owner.empty or owner.kind=='analytics.disable' else [17], 'firewall_node_ids':[] if owner.empty else [17], 'cleanup_caddy_node_ids':[] if owner.empty else [14,17]}}
                    if 'participant' in query:
                        node = int(query['participant'][0]); owner.peer_reads.append(node)
                        content = '# fresh node '+str(node)+'\n'
                        catalog = '{}\n'
                        view = {'participant_node_id':node,'certificates':[v for v in owner.leaves() if v['node_id']==node],'cleanup':[v for v in owner.leaves(True) if v['node_id']==node], 'caddy_publication':{'node_id':node,'user':'orbit','agent_sha256':'a'*64,'caddy':{'content':content,'version':hashlib.sha256(content.encode()).hexdigest()[:32],'listen_addresses':['127.0.0.1'],'unserved':False}},'public_verification':[{'protocol':'tls','agent_sha256':'a'*64,'probe':{'address':'10.0.0.14','port':443,'domain':'analytics.example.test'}}] if node==17 else [],'ingress_firewall':{'desired':[],'remove':[],'enable':False},'private_dns_publication':{'node_id':node,'records':'','catalog':catalog,'digest':hashlib.sha256(catalog.encode()).hexdigest()}}
                    return self.reply(body={'data':{'id':7,'node_id':17,'kind':owner.kind,'status':owner.status,'cancel_requested':owner.cancel,'result':None,'input':view}})
                # Real HTTP Plausible transport; secret-bearing responses must stay transient.
                if path in ('/login','/sites/new') and method=='GET':
                    return self.reply(body='<meta name="csrf-token" content="public-csrf">',headers={'Set-Cookie':'session='+owner.secrets[2]+'; Path=/'})
                if path=='/login' and method=='POST':
                    if data.get('password') != owner.secrets[0]: return self.reply(401)
                    return self.reply(302,headers={'Location':'/sites'})
                if path=='/logout': return self.reply(302,headers={'Location':'/login'})
                if path=='/sites' and method=='POST':
                    owner.created=True
                    with owner.lock: owner.events.append('site-create')
                    return self.reply(302,headers={'Location':'/example.test/installation'})
                if path=='/example.test/settings/general':
                    if not owner.created:return self.reply(404)
                    return self.reply(body='<meta name="csrf-token" content="public-csrf"><select name="site[timezone]"><option selected value="UTC"></option></select>')
                if path=='/api/v2/query':
                    if owner.failure=='stats':return self.reply(401,{'error':owner.secrets[1]})
                    if self.headers.get('Authorization')!='Bearer '+owner.secrets[1]:return self.reply(401)
                    with owner.lock: owner.events.append('stats-readable')
                    return self.reply(body={'results':[]})
                self.reply(404)
        plausible = http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
        gateway = http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(tls/'ca.pem',tls/'key.pem')
        gateway.socket = context.wrap_socket(gateway.socket,server_side=True)
        for server in (plausible,gateway):
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
            self.addCleanup(server.server_close);self.addCleanup(server.shutdown)
        self.plausible_url='http://127.0.0.1:'+str(plausible.server_port)
        self.gateway_url='https://127.0.0.1:'+str(gateway.server_port)
        self.native_env.update(ANALYTICS_TEST_GATEWAY=self.gateway_url,ANALYTICS_TEST_CA=str(tls/'ca.pem'))
        base = self.repo/'extensions/behaviors/operations/analytics'
        # Fake the canonical local-action exports. Public read/sign/project and Plausible transports stay real.
        shutil.copy(ROOT/'tests/fixtures/analytics/effects.ts',base/'native_effects.ts')
        effect = base/'native_effects.ts'
        effect.write_text(effect.read_text().replace('../../../extensions/behaviors/automatic-deploy/','../../automatic-deploy/').replace('../../../extensions/behaviors/operations/certificates/','../certificates/').replace('../../../extensions/behaviors/operations/routes/','../routes/'))
        host = base/'hosts.ts'
        host.write_text('import {participantHttp,certificates,verify,lan} from "./native_effects.ts";\n'+host.read_text().replace('new GatewayOperation(makeHttp(ctx), args)','new GatewayOperation(participantHttp(ctx, args.target_node_id), args)').replace('export const behavior = analyticsCertificates();','export const behavior = analyticsCertificates(undefined, certificates, verify, lan);'))
        lifecycle = base/'lifecycle.ts'
        lifecycle.write_text('import {grace} from "./native_effects.ts";\n'+lifecycle.read_text().replace('export const behavior = analyticsLifecycle();','export const behavior = analyticsLifecycle(undefined, undefined, grace);'))
        cert = self.repo/'extensions/behaviors/operations/certificates/mod.ts'
        source = cert.read_text()
        self.assertIn('...certificates()', source)
        cert.write_text('import {signCertificateBatch as fixtureSign} from "../analytics/native_effects.ts";\n'+source.replace('...certificates()', '...certificates(undefined, fixtureSign)'))
        pub = self.repo/'extensions/behaviors/operations/routes/publication.ts'
        source = pub.read_text().replace('CaddyPublication, publishCaddy as applyCaddy','CaddyPublication')
        # Analytics never calls the Instance-destruction PHP method on this shared model.
        source = source.replace('import { publishDestroyPhp } from "../instance-lifecycle/destroy_php.ts";', 'const publishDestroyPhp = (..._args: unknown[]): Promise<never> => Promise.reject(new Error("fixture.unexpected_destroy_php"));')
        source = source.replace('DnsPublication, publishPrivateDns, runPrivateDns','DnsPublication, runPrivateDns')
        source = source.replace('new GatewayOperation(makeHttp(ctx), args)', 'new GatewayOperation(participantHttp(ctx, args.target_node_id ?? args.node_id), args)')
        source = source.replace('            makeHttp(ctx),\n            args,', '            participantHttp(ctx, args.target_node_id ?? args.node_id),\n            args,')
        source = source.replace('import { convergeFirewall } from "./firewall-local.ts";', '')
        pub.write_text('import {participantHttp,publishCaddy as applyCaddy,publishPrivateDns,managedFirewall as convergeFirewall} from "../analytics/native_effects.ts";\n'+source)

    kind='analytics.enable'
    def leaves(self,cleanup=False):
        if self.empty or (self.kind=='analytics.disable' and not cleanup):return []
        route = 2 if cleanup else 1
        return [{'node_id':14,'route_id':route,'domain':'old.example.test' if cleanup else 'analytics.example.test','scope':f'route-{route}-router','user':'orbit','agent_sha256':'a'*64,**({'in_use':False} if cleanup else {'version':'b'*16,'root_hash':'c'*64})}]


    def workflow(self):
        name='operation-'+self.kind.replace('.','-')+'-direct'
        source=ROOT/'workflows'/('workflow-'+name+'.yaml')
        data=yaml.safe_load(source.read_text())
        for job in data['jobs']:
            for step in job['steps']:
                self.assertIn('target',step);self.assertIn('node',step['labels'])
                self.assertNotIn('queueTimeout',step)
                if step['task']['methodName']=='signBatch':
                    self.assertEqual(step['target'],'orbit-node-209')
                    self.assertEqual(step['labels']['node'],'209')
                    self.assertEqual(step['task']['behaviorName'],'orbit-certificate-signing')
                step.pop('target');step.pop('labels')
                step['task']['globalArgs']={'gatewayUrl':self.gateway_url,'caFile':self.native_env['ANALYTICS_TEST_CA']}
                if step['task']['methodName']=='signBatch': step['task']['globalArgs'].update(vaultName='fixture-ca',secretKey='fixture-ca')
        (self.repo/'workflows'/source.name).write_text(yaml.safe_dump(data,sort_keys=False))
        return name

    def run_workflow(self,success=True):
        name=self.workflow()
        self.cli('workflow','validate',name)
        result = self.cli('workflow','run',name,'--input','operation_id=7','--input','node_id=17','--input','kind='+self.kind,success=success)
        self.assert_private_absent(json.dumps(self.projections))
        return result



class AnalyticsEnableNative(AnalyticsFixture):
    def test_enable_native_fanout_barriers_and_real_http_private_scan(self):
        self.run_workflow()
        self.assertEqual(self.status,'succeeded')
        self.assertEqual(self.events.count('site-create'),1)
        self.assertEqual(self.events.count('certificate.request'),1)
        self.assertEqual(self.events.count('certificate.publish'),1)
        self.assertEqual(self.events.count('certificate.sign'),1)
        self.assertNotIn('project:running:certificate_requests',self.events)
        self.assertEqual(sum(e.startswith('caddy.publish:') for e in self.events),4)
        self.assertEqual(self.events.count('private-dns.publish'),2)
        self.assertEqual(self.events.count('certificate.remove'),1)
        self.assertLess(self.events.index('stats-readable'),self.events.index('certificate.request'))
        self.assertLess(max(i for i,e in enumerate(self.events) if e=='certificate.publish'),self.events.index('caddy.publish:14'))
        self.assertLess(self.events.index('private-dns.publish'),self.events.index('certificate.remove'))
        self.assertEqual(set(self.peer_reads),{1,14,17})
        ordered = ['caddy.publish:14','private-hop.verify','project:running:public_verified','caddy.publish:17','firewall.publish','private-dns.publish','dns.grace','project:running:placement_cleanup','certificate.remove','lan.remove','project:succeeded:ready']
        self.assertEqual([self.events.index(e) for e in ordered], sorted(self.events.index(e) for e in ordered))
        self.run_workflow()
        self.assertEqual(self.events.count('site-create'),1)
        self.assertEqual(sum(e.startswith('caddy.publish:') for e in self.events),4)
        self.assert_private_absent()

    def test_provider_failure_blocks_all_host_effects(self):
        self.failure='stats';self.run_workflow(success=False)
        self.assertEqual(self.status,'failed')
        self.assertNotIn('certificate.request',self.events)
        self.assertFalse(any(e.startswith('caddy.publish:') for e in self.events))
        self.assertEqual(self.projections[-1]['result'],{'error_code':'analytics.change_failed'})

    def test_private_http_failure_is_bounded_and_leaves_no_scratch_secret(self):
        self.failure='private-http';self.run_workflow(success=False)
        self.assertEqual(self.status,'failed')
        self.assertNotIn('site-create',self.events)
        self.assert_private_absent(json.dumps(self.projections))

    def test_caddy_failure_blocks_dns_cleanup_and_success(self):
        self.failure='caddy.publish';self.run_workflow(success=False)
        self.assertEqual(self.status,'failed')
        self.assertNotIn('private-dns.publish',self.events)
        self.assertNotIn('certificate.remove',self.events)

    def test_private_hop_failure_blocks_activation_and_ingress_firewall(self):
        self.failure='private-hop.verify';self.run_workflow(success=False)
        self.assertEqual(self.status,'failed')
        self.assertIn('caddy.publish:14',self.events)
        self.assertNotIn('project:running:public_verified',self.events)
        self.assertNotIn('caddy.publish:17',self.events)
        self.assertNotIn('firewall.publish',self.events)
        self.assertNotIn('private-dns.publish',self.events)

    def test_firewall_failure_blocks_dns_and_retirement(self):
        self.failure='firewall.publish';self.run_workflow(success=False)
        self.assertEqual(self.status,'failed')
        self.assertIn('project:running:public_verified',self.events)
        self.assertNotIn('private-dns.publish',self.events)
        self.assertNotIn('certificate.remove',self.events)

    def test_dns_grace_failure_keeps_retired_placement_and_leaf(self):
        self.failure='dns.grace';self.run_workflow(success=False)
        self.assertEqual(self.status,'failed')
        self.assertIn('private-dns.publish',self.events)
        self.assertNotIn('project:running:placement_cleanup',self.events)
        self.assertNotIn('certificate.remove',self.events)

    def test_cancel_has_no_private_or_host_effects(self):
        self.cancel=True;self.run_workflow()
        self.assertEqual(self.status,'cancelled')
        self.assertEqual(self.events,['project:cancelled'])


class AnalyticsDisableNative(AnalyticsFixture):
    kind='analytics.disable'

    def test_detach_retains_provider_and_withdraws_every_host_before_success(self):
        self.created=True
        self.run_workflow()
        self.assertEqual(self.status,'succeeded')
        self.assertTrue(self.created)
        self.assertNotIn('private-material',self.events)
        self.assertNotIn('site-create',self.events)
        self.assertEqual(sum(e.startswith('caddy.publish:') for e in self.events),4)
        self.assertEqual(self.events.count('private-dns.publish'),2)
        self.assertEqual(self.events.count('certificate.remove'),1)
        self.assertLess(self.events.index('caddy.publish:17'),self.events.index('private-dns.publish'))
        self.assertLess(self.events.index('private-dns.publish'),self.events.index('certificate.remove'))
        self.assertEqual(self.projections[-1]['result'],{'ready':True,'enabled':False,'domain':'example.test','timezone':'UTC','detached':True})
        self.run_workflow()
        self.assertEqual(sum(e.startswith('caddy.publish:') for e in self.events),4)

    def test_empty_detach_succeeds_with_no_host_or_private_actions(self):
        self.empty=True;self.run_workflow()
        self.assertEqual(self.status,'succeeded')
        self.assertFalse(any(e in self.events for e in ['private-material','caddy.publish:14','caddy.publish:17','private-dns.publish','certificate.remove','firewall.publish','dns.grace']))

    def test_failed_withdrawal_keeps_cleanup_unstarted_and_parent_failed(self):
        self.failure='caddy.publish';self.run_workflow(success=False)
        self.assertEqual(self.status,'failed')
        self.assertNotIn('private-dns.publish',self.events)
        self.assertNotIn('certificate.remove',self.events)

    def test_cancel_does_not_detach_or_withdraw(self):
        self.cancel=True;self.run_workflow()
        self.assertEqual(self.events,['project:cancelled'])
        self.assertEqual(self.status,'cancelled')

if __name__=='__main__': unittest.main()
