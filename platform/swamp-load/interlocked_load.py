"""Fixed foreground load boundary; caller announces before acquiring this lock."""
import json,subprocess
from recover_registry import gateway_script

def run(announcement):
 receipt={'announcement':announcement}
 php=gateway_script(receipt,False)
 old="$command=new App\\Infrastructure\\Ssh\\RemoteCommand(['sudo','-n','/usr/bin/python3','/usr/local/libexec/guarded_registry_restart.py'],input:json_encode($receipt),maxOutputBytes:262144,timeout:720);"
 new="$command=new App\\Infrastructure\\Ssh\\RemoteCommand(['sudo','-n','/usr/bin/python3','-B','/usr/local/libexec/orbit-swamp-main-load.py','--gateway-interlocked'],input:json_encode($receipt),maxOutputBytes:262144,timeout:960);"
 assert old in php;php=php.replace(old,new).replace('commandTimeout:720','commandTimeout:960')
 php=php.replace('echo $result->stdout;','echo $result->stdout; if(!$result->succeeded())echo json_encode(["host_error"=>substr($result->stderr,-1500)]);')
 r=subprocess.run(['ssh','-T','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=10','gateway','php'],input=php,capture_output=True,text=True,timeout=990)
 if r.returncode:raise RuntimeError('gateway_interlocked_load_refused_or_failed:'+r.stderr[-500:]+r.stdout[-1500:])
 return json.loads(r.stdout)
