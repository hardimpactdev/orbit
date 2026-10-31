"""Ported named UFW behavior tests; all paths and command output are disposable."""
from collections import deque
import importlib.util
from pathlib import Path
import unittest

source=Path(__file__).resolve().parents[1]/'routes/local_programs_test.py'
spec=importlib.util.spec_from_file_location('route_local_programs',source)
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)

class NamedRules(unittest.TestCase):
    def setUp(self):
        self.program=module.program('firewall-program.ts')
        self.calls=[];self.replies=deque()
        self.input=dict(node_id=7,name='web',action='allow',source='any',port='443',protocol='tcp',operation='converge')
        def ufw(args):
            self.calls.append(args)
            reply=self.replies.popleft()
            return reply if isinstance(reply,tuple) else (0,reply)
        self.program.ufw=ufw
    def live(self,*rows): return 'Status: active\n'+'\n'.join(rows)
    v4='[1] 443/tcp ALLOW IN Anywhere # orbit:node:7:firewall:web'
    v6='[2] 443/tcp (v6) ALLOW IN Anywhere (v6) # orbit:node:7:firewall:web'
    def run_rule(self): return self.program.named_rule(self.input)
    def test_inactive_never_enables_or_mutates(self):
        self.replies.append('Status: inactive')
        self.assertEqual(self.run_rule(),{'backend':'inactive'})
        self.assertEqual(self.calls,[['status','numbered']])
    def test_create_idempotent_and_allow_is_prepended(self):
        complete=self.live(self.v4,self.v6)
        self.replies.extend([self.live(),'added',complete,complete])
        self.assertEqual(self.run_rule(),{'backend':'active'})
        self.assertEqual(self.calls[1],['prepend','allow','in','from','any','to','any','port','443','proto','tcp','comment','orbit:node:7:firewall:web'])
        self.assertEqual(self.run_rule(),{'backend':'active'})
        self.assertEqual(len(self.calls),4)
    def test_missing_family_is_repaired_without_deleting_survivor(self):
        self.replies.extend([self.live(self.v4),'added',self.live(self.v4,self.v6)])
        self.run_rule()
        self.assertEqual(self.calls[1][0],'prepend')
        self.assertFalse(any('delete' in call for call in self.calls))
    def test_stale_owned_shape_is_replaced_without_touching_unmanaged(self):
        stale='[1] 443/tcp ALLOW IN 192.0.2.0/24 # orbit:node:7:firewall:web'
        foreign='[2] 443/tcp ALLOW IN Anywhere # operator-owned'
        self.replies.extend([self.live(stale,foreign),'deleted','added',self.live(foreign,self.v4,self.v6)])
        self.run_rule()
        self.assertEqual(self.calls[1][:5],['delete','allow','in','from','192.0.2.0/24'])
        self.assertNotIn('operator-owned',str(self.calls))
    def test_ownership_collision_refuses_before_mutation(self):
        for rows in [(self.v4,self.v4,self.v6),('[1] OpenSSH ALLOW IN Anywhere # orbit:node:7:firewall:web',),('[1] 443/tcp on eth0 ALLOW IN Anywhere # orbit:node:7:firewall:web',),('[1] 80/tcp ALLOW IN Anywhere # orbit:node:7:firewall:web','[2] 80/tcp (v6) ALLOW IN Anywhere (v6) # orbit:node:7:firewall:web')]:
            self.calls=[];self.replies.append(self.live(*rows))
            with self.assertRaises(self.program.Refusal) as caught: self.run_rule()
            self.assertEqual(caught.exception.code,'firewall.rule_collision')
            self.assertEqual(self.calls,[['status','numbered']])
    def test_named_removal_is_exact_and_idempotent(self):
        self.input['operation']='remove'
        self.replies.extend([self.live(self.v4,self.v6),'deleted',self.live(),self.live()])
        self.assertEqual(self.run_rule(),{'backend':'absent'})
        self.assertEqual(self.calls[1][0],'delete')
        self.assertEqual(self.calls[1][-1],'orbit:node:7:firewall:web')
        self.assertEqual(self.run_rule(),{'backend':'absent'})
    def test_invalid_input_never_reads_or_changes_host(self):
        self.input['name']='web;whoami'
        with self.assertRaises(self.program.Refusal): self.run_rule()
        self.assertEqual(self.calls,[])
    def test_failed_apply_and_confirmation_remain_distinct(self):
        self.replies.extend([self.live(),(1,'private error')])
        with self.assertRaises(self.program.Refusal) as caught: self.run_rule()
        self.assertEqual(caught.exception.code,'firewall.apply_failed')
        self.replies.extend([self.live(),'added',self.live(self.v4)])
        with self.assertRaises(self.program.Refusal) as caught: self.run_rule()
        self.assertEqual(caught.exception.code,'firewall.verify_failed')

if __name__=='__main__': unittest.main()
