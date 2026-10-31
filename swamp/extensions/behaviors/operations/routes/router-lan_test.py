"""Router LAN transactions reuse the shared UFW engine and preserve foreign rules."""
import json,pathlib,re,unittest
HERE=pathlib.Path(__file__).parent
BASE=(HERE/'firewall-program.ts').read_text().split('.raw`',1)[1].rsplit('`;',1)[0]
EXT=re.search(r'String.raw`(.*?)`;', (HERE/'router-lan.ts').read_text(),re.S)[1]
class RouterLanTest(unittest.TestCase):
    def setUp(self):
        self.ns={'__name__':'fixture'};exec(BASE+EXT,self.ns);self.rows=[];self.calls=[];self.fail=None
        self.ns['active']=lambda:list(self.rows)
        self.ns['delete_comments']=lambda comments:self.delete(comments)
        self.ns['change']=lambda args:self.change(args)
    def shape(self,n,port='443'):
        return dict(comment='orbit:router-lan-https:'+str(n),action='allow',direction='in',source='192.168.6.1',destination='192.168.6.18',port=port,protocol='tcp',in_interface=None,out_interface=None,family='v4')
    def add(self,shape):self.rows.append(dict(number=len(self.rows)+1,comment=shape['comment'],shape=shape))
    def delete(self,comments):self.calls.append(('delete',set(comments)));self.rows[:]=[r for r in self.rows if r['comment'] not in comments]
    def change(self,args):
        comment=args[-1];self.calls.append(('add',comment))
        if self.fail==comment:self.fail=None;raise self.ns['Refusal']('node.firewall_convergence_failed')
        shape=next(s for s in self.desired+self.original if s['comment']==comment and s['port']==args[args.index('port')+1]);self.add(shape)
    def run_lan(self,desired,expand=True,prune=True):
        self.desired=desired;self.original=[r['shape'] for r in self.rows if r['shape']];return self.ns['router_lan'](dict(desired=desired,expand=expand,prune=prune))
    def test_replaces_owned_drift_and_prunes_only_router_families(self):
        self.add(self.shape(1,'444'));self.add(self.shape(2));foreign=dict(self.shape(3),comment='orbit:router-lan-https-operator');self.add(foreign)
        self.assertEqual(dict(matches=True),self.run_lan([self.shape(1)]));self.assertEqual([foreign,self.shape(1)],[r['shape'] for r in self.rows])
    def test_failed_expansion_restores_exact_snapshot(self):
        self.add(self.shape(1));before=json.dumps(self.rows);self.fail=self.shape(3)['comment']
        with self.assertRaises(Exception):self.run_lan([self.shape(2),self.shape(3)])
        self.assertEqual(before,json.dumps(self.rows))
    def test_failed_replacement_restores_previous_shape(self):
        self.add(self.shape(1,'444'));before=[r['shape'] for r in self.rows];self.fail=self.shape(1)['comment']
        with self.assertRaises(Exception):self.run_lan([self.shape(1)])
        self.assertEqual(before,[r['shape'] for r in self.rows])
    def test_prune_only_and_expand_only_keep_their_separate_intent(self):
        self.add(self.shape(1));self.run_lan([self.shape(2)],prune=False);self.assertEqual(2,len(self.rows));self.run_lan([],expand=False);self.assertEqual([],self.rows)
    def test_malformed_owned_inventory_refuses_before_change(self):
        self.rows=[dict(number=1,comment=self.shape(1)['comment'],shape=None)]
        with self.assertRaises(Exception):self.run_lan([self.shape(1)])
        self.assertEqual([],self.calls)
    def test_duplicate_desired_or_foreign_comment_refuses_before_change(self):
        for desired in [[self.shape(1)]*2,[dict(self.shape(1),comment='orbit:route-1-lan')]]:
            with self.assertRaises(Exception):self.run_lan(desired)
        self.assertEqual([],self.calls)
if __name__=='__main__':unittest.main()
