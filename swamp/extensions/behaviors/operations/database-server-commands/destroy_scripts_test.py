"""Removal refusals, exact SQL scopes and private ACL compensation."""
import json
import stat
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).parent/'scripts'))
import destroy
import destroy_sql as sql
import destroy_valkey as acl
from create_common import Refused


class DestroyActions(unittest.TestCase):
    def setUp(self):
        self.i={'driver':'mysql','container':'orbit-process-42-proof','host':'127.0.0.1','port':3306,'default_open':False,'databases':['proof_app'],'users':['proof_owner'],'test_prefixes':['proof_testing'],'owners':{'proof_app':'proof_owner','proof_testing':'proof_owner'}}
        self.p={'databases':['proof_app','proof_testing_1'],'users':['proof_owner'],'owners':{'proof_app':'proof_owner','proof_testing_1':'proof_owner'}}
        self.m={'password':'private-root-sentinel'}

    def test_names_users_credentials_and_unadmitted_plan_refuse_before_client(self):
        for i,m,p in [({**self.i,'databases':['mysql']},self.m,self.p),({**self.i,'test_prefixes':['my']},self.m,self.p),({**self.i,'users':['root']},self.m,self.p),(self.i,{'password':'private\nunsafe'},self.p),(self.i,self.m,{**self.p,'databases':['foreign']})]:
            with self.assertRaises(AssertionError):destroy.validate(i,m,p)
        destroy.validate(self.i,self.m,self.p)

    def test_literal_prefix_inventory_cannot_treat_underscore_as_wildcard(self):
        with patch.object(sql,'sql',return_value='proof_testing_1\n') as client:
            self.assertEqual(sql.inventory(self.i,self.m,{},'', ''),{'databases':['proof_testing_1']})
            self.assertIn("BINARY LEFT(SCHEMA_NAME,13)=BINARY 'proof_testing'",client.call_args.args[2])
            self.assertNotIn(' LIKE ',client.call_args.args[2])

    def test_mysql_escaped_full_grant_and_live_blockers_refuse_before_drop(self):
        with patch.object(sql,'sql',return_value='foreign\n') as client:
            with self.assertRaisesRegex(Refused,'database.ownership_unproven'):sql.ownership(self.i,self.m,self.p,'','')
            query=client.call_args.args[2]
            self.assertIn('FROM mysql.db',query);self.assertIn("Drop_priv='Y'",query)
            self.assertIn("CONVERT(0x"+"proof\\_app".encode().hex()+" USING utf8mb4)",query)
        with patch.object(sql,'sql',return_value='proof_app\n') as client:
            with self.assertRaisesRegex(Refused,'database.in_use'):sql.blockers(self.i,self.m,self.p,'','')
            for table in ('PROCESSLIST','metadata_locks','VIEWS','ROUTINES','TRIGGERS','EVENTS','KEY_COLUMN_USAGE'):self.assertIn(table,client.call_args.args[2])

    def test_drop_database_and_user_are_independent_exact_actions(self):
        with patch.object(sql,'sql',return_value='') as client:
            sql.drop_database(self.i,self.m,self.p,'proof_app','')
            self.assertEqual(client.call_args.args[2],'DROP DATABASE IF EXISTS `proof_app`;\n')
            sql.drop_user(self.i,self.m,self.p,'proof_owner','')
            self.assertEqual(client.call_args.args[2],"DROP USER IF EXISTS 'proof_owner'@'%';\n")
            with self.assertRaises(Refused):sql.drop_database(self.i,self.m,self.p,'foreign','')
            self.assertEqual(client.call_count,2)

    def test_postgres_fence_records_original_state_and_drop_rechecks_exact_identity(self):
        i={**self.i,'driver':'pgsql','test_prefixes':[]}
        with patch.object(sql,'sql',return_value='') as client:
            sql.fence(i,self.m,self.p,'','')
            query=client.call_args.args[2]
            for token in ('pg_advisory_xact_lock','datallowconn','ON CONFLICT (database_name) DO NOTHING','database_oid<>item.current_oid','ALLOW_CONNECTIONS false'):self.assertIn(token,query)
            self.assertNotIn('DROP DATABASE',query)
            sql.drop_database(i,self.m,self.p,'proof_app','')
            query=client.call_args.args[2]
            self.assertIn('f.database_oid IS DISTINCT FROM d.oid',query);self.assertIn('d.datallowconn',query)
            self.assertIn('DROP DATABASE IF EXISTS "proof_app";',query)

    def test_postgres_restore_preserves_prior_false_and_refuses_replacement_oid(self):
        with patch.object(sql,'sql',return_value='') as client:
            sql.restore({**self.i,'driver':'pgsql'},self.m,self.p,'','')
            query=client.call_args.args[2]
            self.assertIn("CASE WHEN item.allow_connections THEN 'true' ELSE 'false' END",query)
            self.assertIn('item.database_oid<>item.current_oid',query)
            self.assertNotIn('DROP DATABASE',query);self.assertNotIn('CONNECTION LIMIT',query)

    def test_private_acl_snapshot_preserves_first_copy_and_excludes_default_and_foreign_users(self):
        with tempfile.TemporaryDirectory() as path:
            with patch.object(acl,'valkey',return_value=['user default on nopass ~* &* +@all','user proof_owner on #private-hash ~proof:* +get','user foreign on #foreign-hash ~foreign:* +get']):
                self.assertEqual(acl.snapshot(self.i,self.m,self.p,'',path),{'ready':True})
            file=Path(path)/'acl-snapshot.json';first=file.read_text()
            self.assertEqual(stat.S_IMODE(file.stat().st_mode),0o600)
            self.assertEqual(list(json.loads(first)),['proof_owner'])
            with patch.object(acl,'valkey',return_value=[]):acl.snapshot(self.i,self.m,self.p,'',path)
            self.assertEqual(file.read_text(),first)
            with patch.object(acl,'valkey',return_value='OK') as client:
                acl.restore(self.i,self.m,self.p,'proof_owner',path)
                self.assertEqual(client.call_args.args[2:],('ACL','SETUSER','proof_owner','reset','on','#private-hash','~proof:*','+get'))
                self.assertTrue(client.call_args.kwargs['private'])

    def test_corrupt_or_foreign_retained_snapshot_refuses_before_delete(self):
        for content in ('', '{', '{"foreign":["on"]}', '{"proof_owner":"private"}'):
            with tempfile.TemporaryDirectory() as path:
                (Path(path)/'acl-snapshot.json').write_text(content)
                with patch.object(acl,'valkey',return_value=[] ) as client:
                    with self.assertRaisesRegex(Refused,'database.server_command_failed'):
                        acl.snapshot(self.i,self.m,self.p,'',path)
                    self.assertEqual(client.call_args.args[2:],('ACL','LIST'))
                    self.assertEqual(client.call_count,1)
                with self.assertRaises(Refused):acl.restore(self.i,self.m,self.p,'proof_owner',path)

    def test_failed_snapshot_write_does_not_publish_partial_rollback_material(self):
        with tempfile.TemporaryDirectory() as path:
            with patch.object(acl,'valkey',return_value=['user proof_owner on #private']), patch.object(acl.json,'dump',side_effect=OSError('disk full')):
                with self.assertRaises(OSError):acl.snapshot(self.i,self.m,self.p,'',path)
            self.assertEqual(list(Path(path).iterdir()),[])

    def test_acl_delete_preserves_default_keys_and_save_failure_remains_a_refusal(self):
        with patch.object(acl,'valkey',return_value=1) as client:
            self.assertEqual(acl.remove(self.i,self.m,self.p,'proof_owner',''),{'ready':True})
            self.assertEqual(client.call_args.args[2:],('ACL','DELUSER','proof_owner'))
            with self.assertRaises(Refused):acl.remove(self.i,self.m,self.p,'default','')
            self.assertEqual(client.call_count,1)
        with patch.object(acl,'valkey',return_value='ERR save failed'):
            with self.assertRaisesRegex(Refused,'database.server_command_failed'):acl.save(self.i,self.m,self.p,'','')


if __name__=='__main__':unittest.main()
