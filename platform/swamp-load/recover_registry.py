#!/usr/bin/python3
"""Foreground announced recovery through the existing Gateway release interlock.

Call only AFTER announcing in the user thread. No auto-resume/pause manipulation.
--check-only checks the complete interlock/route without sending any host action.
"""
import argparse
import base64
import datetime
import json
import pathlib
import subprocess

CODE = pathlib.Path(__file__).parent

PHP = r'''<?php
require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$app=require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$receipt=json_decode(base64_decode('RECEIPT'),true,512,JSON_THROW_ON_ERROR);
try {
 app(App\Infrastructure\GatewayReleases\GatewayReleaseLock::class)->run(function()use($receipt){
  // This is the action behind gateway-release-auto-status, not cached gateway-status.
  $status=app(App\Actions\GatewayReleases\ShowGatewayReleaseAutomationAction::class)->execute()->toArray();
  if(!is_string($status['current_sha']??null)||strlen($status['current_sha'])!==40)throw new RuntimeException('gateway_auto_status_unknown');
  $active=App\Models\GatewayRelease::query()->whereIn('outcome',['queued','running'])->count();
  if($active!==0)throw new RuntimeException('gateway_release_active');
  if(CHECKONLY){echo json_encode(['state'=>'gateway_interlock_ready_no_action','active_releases'=>$active,'auto_enabled'=>$status['enabled'],'auto_paused'=>$status['paused'],'current_sha'=>$status['current_sha']]);return;}
  $receipt['gateway_release_lock']='GatewayReleaseLock::run';
  $receipt['gateway_auto_status_checked']=true;
  $receipt['gateway_active_release_count']=$active;
  $receipt['gateway_current_sha']=$status['current_sha'];
  $node=App\Models\Node::query()->findOrFail(209);
  $connection=new App\Infrastructure\Ssh\SshConnection(host:$node->wireguard_ip,user:$node->user,port:22,identityFile:app(App\Infrastructure\Ssh\SshKeyProvider::class)->privateKeyPath(),knownHostsFile:app(App\Infrastructure\Ssh\KnownHostsStore::class)->path(),commandTimeout:720);
  $command=new App\Infrastructure\Ssh\RemoteCommand(['sudo','-n','/usr/bin/python3','/usr/local/libexec/guarded_registry_restart.py'],input:json_encode($receipt),maxOutputBytes:262144,timeout:720);
  // Retain GatewayReleaseLock until the foreground SSH command has completed.
  $result=app(App\Infrastructure\Ssh\SshExecutor::class)->execute($connection,$command);
  echo $result->stdout;
  if(!$result->succeeded())throw new RuntimeException('registry_recovery_host_failed');
 });
}catch(Throwable $e){fwrite(STDERR,json_encode(['state'=>'refused_or_failed','error_class'=>get_class($e),'reason'=>$e instanceof RuntimeException?$e->getMessage():'gateway_interlock_refused'])."\n");exit(1);}
'''


def gateway_script(receipt, check_only=False):
    return PHP.replace('RECEIPT', base64.b64encode(json.dumps(receipt).encode()).decode()).replace('CHECKONLY', 'true' if check_only else 'false')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source')
    parser.add_argument('--batch-key')
    parser.add_argument('--runtime-invocation')
    parser.add_argument('--announced-at')
    parser.add_argument('--announcement')
    parser.add_argument('--check-only', action='store_true')
    args = parser.parse_args()
    receipt = {'source_sha': args.source, 'batch_key': args.batch_key,
               'runtime_invocation': args.runtime_invocation, 'announced_at': args.announced_at,
               'announcement': args.announcement}
    if not args.check_only:
        if not all(receipt.values()):
            parser.error('A fresh user-visible announcement and its exact source/batch/invocation are required.')
        announced = datetime.datetime.fromisoformat(args.announced_at)
        if not 0 <= (datetime.datetime.now(datetime.timezone.utc) - announced).total_seconds() <= 300:
            parser.error('Announce this restart now; the announcement is expired or future-dated.')
    # First read the actual public contract through the existing operator route.
    status = subprocess.run(['/usr/local/bin/orbit', 'gateway:release:auto:status', '--json'],
                            capture_output=True, text=True, timeout=45)
    if status.returncode:
        raise RuntimeError('gateway_release_auto_status_unavailable')
    value = json.loads(status.stdout)
    assert isinstance(value.get('paused'), bool) and value.get('current_sha'), 'gateway_auto_status_invalid'
    result = subprocess.run(['ssh', '-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
                             '-o', 'ConnectTimeout=10', 'gateway', 'php'], input=gateway_script(receipt, args.check_only),
                            capture_output=True, text=True, timeout=750)
    print(result.stdout, end='')
    if result.returncode:
        print(result.stderr[-1500:])
        raise SystemExit(result.returncode)


if __name__ == '__main__':
    main()
