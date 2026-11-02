"""Legacy reset parity: Grafana accepted the new login; Gateway promoted only that password."""
import json
import os
import subprocess

operation = json.loads(os.environ['PROOF_OPERATION'])
assert operation['status'] == 'succeeded' and operation['result'] == {'reset': True}
code = r'''require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $op=json_decode(getenv("PROOF_OPERATION"),true,flags:JSON_THROW_ON_ERROR); $s=app(App\Domain\Settings\SettingRepository::class); $scope=new App\Domain\Settings\SettingScope(App\Domain\Settings\SettingScopeType::Node,$op["target_id"]); $active=$s->get($scope,App\Infrastructure\Metrics\NativeMetricsCredentialManager::ActivePasswordKey); if (!is_string($active) || !hash_equals($op["input"]["pending_fingerprint"],hash("sha256",$active)) || $s->get($scope,App\Infrastructure\Metrics\NativeMetricsCredentialManager::PendingPasswordKey)!==null) exit(1);'''
subprocess.run(['runuser', '-u', 'orbit', '--', 'php', '-r', code], cwd='apps/gateway', env=os.environ, check=True)
print('parity: the verified pending password is active; pending state is cleared')
