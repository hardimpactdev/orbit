"""One bounded restart, called only inside the real Gateway release interlock."""
import importlib.util,json,pathlib,time

def load_module(name,path):
 s=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m

def restart(L,receipt):
 assert receipt.get('gateway_release_lock')=='GatewayReleaseLock::run' and receipt.get('gateway_active_release_count')==0 and receipt.get('gateway_auto_status_checked') is True,'gateway_interlock_missing'
 assert receipt.get('announcement'),'announcement_missing'
 R=load_module('restart_checks','/usr/local/libexec/guarded_registry_restart.py')
 assert '/swapfile' in pathlib.Path('/proc/swaps').read_text(),'swap_not_active'
 target=L.git('rev-parse','HEAD');before=L.serve_runtime()
 cfg=R.configs(L);paused=R.pauses(L)
 identities={str(p):[p.stat().st_dev,p.stat().st_ino] for p in [L.APP/'.swamp',L.APP/'vaults']}
 marker=L.STATE/'immediate-restart.json'
 prior=json.loads(marker.read_text())if marker.exists()else{}
 assert prior.get('source_sha')!=target,'restart_already_attempted_requires_diagnosis'
 deferred=R.drain_before_restart(L)
 if deferred:return deferred
 state={'state':'restarting','source_sha':target,'announced':receipt,'runtime_before':before,'started_at':L.now(),'swap_active':True}
 L.atomic(marker,state)
 try:
  L.command(['systemctl','restart','--no-block',L.SERVE],timeout=15)
  deadline=time.monotonic()+600
  while True:
   try:
    after=L.serve_runtime();assert after['invocation']!=before['invocation'];R.health();break
   except Exception:
    if time.monotonic()>=deadline:raise RuntimeError('restart_health_deadline')
    time.sleep(1)
  assert cfg==R.configs(L) and paused==R.pauses(L),'config_or_pause_changed'
  assert identities=={str(p):[p.stat().st_dev,p.stat().st_ino]for p in [L.APP/'.swamp',L.APP/'vaults']},'state_identity_changed'
  assert all(after[x]==before[x]for x in ['remote_only','hot_reload','scheduled_runs']),'execution_policy_changed'
  assert L.git('rev-parse','HEAD')==target;L.clean_source()
  audit=L.audit_behavior_versions()
  event=L.record_load_event({'load_mode':'restart','loaded_sha':target,'swamp_tree_sha':L.git('rev-parse','HEAD:swamp'),'version_checked_at':L.now(),'behavior_versions':audit})
  state.update(runtime_after=after,behavior_versions=audit,load_event=event,completed_at=L.now(),state_preserved=True)
  assert audit.get('checked',0)>0 and audit['checked']==audit.get('matched') and not audit['errors'] and not audit['mismatches'],'postrestart_served_disk_false'
  state['state']='healthy';L.record_restart_requirement(audit,target)
  L.atomic(L.STATE/'last-proof.json',{'loaded_sha':target,'swamp_tree_sha':L.git('rev-parse','HEAD:swamp'),'load_mode':'restart','version_checked_at':L.now(),'behavior_versions':audit,'runtime_after':after,'health':'ok','load_event':event})
  return state
 except Exception as e:
  state.update(state='failed_no_second_restart',error=str(e)if isinstance(e,(RuntimeError,AssertionError))else type(e).__name__);raise
 finally:L.atomic(marker,state)
