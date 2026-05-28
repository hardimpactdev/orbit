# Phase 4 pre-sweep inventory — 2026-05-28

Source artifact for ORBIT-CLI-04A. Raw output of the three rg sweeps required by Phase 4 *before* any retarget edits land. The post-retarget audit (committed alongside the Phase 4 PR at `docs/superpowers/notes/phase4-e2e-gateway-host-orbit-invocations.md`) is the diff between this baseline and the final tree.

## Sweep 1 — `orbit <gateway-artisan-command>` call sites in apps / bin / tests / docs

```
apps/gateway/tests/E2E/VpnCommandTest.php:53:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/VpnCommandTest.php:109:        'cd '.escapeshellarg($topology->checkout('operator')).' && orbit tinker --execute='.escapeshellarg($operatorScript),
apps/gateway/tests/E2E/VpnCommandTest.php:145:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
apps/gateway/tests/E2E/ScheduleRemoveTest.php:67:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/Ephemeral/ToolsDoctorFixTest.php:93:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/Ephemeral/AppPruneTest.php:39:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/Ephemeral/WorkspacesDoctorTest.php:80:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
apps/gateway/tests/E2E/DatabaseDetachTest.php:61:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/DatabaseDetachTest.php:93:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
apps/gateway/tests/E2E/ToolListTest.php:130:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/ToolListTest.php:165:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/DatabaseAttachTest.php:57:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/DatabaseAttachTest.php:89:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
apps/gateway/tests/E2E/ScheduleRunTest.php:63:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/ScheduleRunTest.php:76:                'cd %s && orbit schedule:run %s --app=%s --json',
apps/gateway/tests/E2E/ToolCredentialsTest.php:102:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/ToolCredentialsTest.php:135:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/ProxyCommandTest.php:94:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg('$node = \App\Models\Node::query()->where("name", "app-dev-1")->firstOrFail(); $node->update(["status" => "active"]); echo "prepared";'),
apps/gateway/tests/E2E/DatabaseListTest.php:44:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/DatabaseListTest.php:69:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
apps/gateway/tests/E2E/WorkspaceListTest.php:65:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/WorkspaceListTest.php:148:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg(implode("\n", [
apps/gateway/tests/E2E/ActivityListTest.php:47:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/AppRootTest.php:37:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/AppRootTest.php:102:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo json_encode([
apps/gateway/tests/E2E/WorkspaceShowTest.php:58:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/AgentIdeMessageTest.php:82:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
apps/gateway/tests/E2E/DatabaseShowTest.php:44:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/DatabaseShowTest.php:70:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
apps/gateway/tests/E2E/WorkspaceRemoveTest.php:74:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/WorkspaceRemoveTest.php:121:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo json_encode([
apps/gateway/tests/E2E/IngressProductionTopologyTest.php:20:        'cd '.$checkout.' && orbit tinker --execute='.escapeshellarg(<<<PHP
apps/gateway/tests/E2E/GrantAuthorizationTest.php:412:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/ActivityShowTest.php:37:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/WorkspaceStepRemoveTest.php:74:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/AppRegisterTest.php:37:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/AppRegisterTest.php:85:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo json_encode([
apps/gateway/tests/E2E/ToolRemoveTest.php:34:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo \\App\\Models\\NodeTool::query()->where('name', 'redis')->count();"),
apps/gateway/tests/E2E/ToolRemoveTest.php:94:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/ToolShowTest.php:161:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/WorkspaceHistoryTest.php:66:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/DatabaseQueryTest.php:54:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/DatabaseQueryTest.php:80:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
apps/gateway/tests/E2E/AppRemoveTest.php:37:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/AppRemoveTest.php:94:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo json_encode([
apps/gateway/tests/E2E/DeployCommandTest.php:34:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
apps/gateway/tests/E2E/ProcessListTest.php:62:    'command' => 'orbit queue:work',
apps/gateway/tests/E2E/ProcessListTest.php:89:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/ProcessListTest.php:161:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("\\App\\Models\\Process::query()->delete(); echo 'cleared';"),
apps/gateway/tests/E2E/WorkspaceStepAddTest.php:52:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/FirewallCommandTest.php:77:            "cd {$checkout} && orbit tinker --execute=".escapeshellarg('$node = \App\Models\Node::query()->where("name", "app-dev-1")->firstOrFail(); \App\Models\FirewallRule::updateOrCreate(["node_id" => $node->id, "name" => "orbit-public-ssh-deny-v4"], ["direction" => "incoming", "action" => "deny", "source" => "any", "destination" => null, "port" => "22", "protocol" => "tcp", "reason" => "Protected public SSH deny rule.", "source_hash" => hash("sha256", "e2e-protected-public-ssh-deny-v4"), "address_family" => "v4", "interface" => "public", "owner" => "node-security", "protected" => true]); echo "protected";'),
apps/gateway/tests/E2E/FirewallCommandTest.php:127:            "cd {$checkout} && orbit tinker --execute=".escapeshellarg('\App\Models\FirewallRule::query()->where("name", "orbit-public-ssh-deny-v4")->delete(); echo "cleaned";').' >/dev/null 2>&1 || true',
apps/gateway/tests/E2E/FirewallCommandTest.php:138:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg('$node = \App\Models\Node::query()->where("name", "app-dev-1")->firstOrFail(); $node->update(["platform" => "ubuntu", "status" => "active"]); echo "prepared";'),
apps/gateway/tests/E2E/ToolReconfigureTest.php:56:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/ScheduleLogsTest.php:75:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/NodeRemoveTest.php:37:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/AppProdIngressTopologyTest.php:78:        'cd /home/orbit/orbit && orbit tinker --execute='.escapeshellarg("eval(base64_decode('{$encodedPhp}'));"),
apps/gateway/tests/E2E/Support/Pest.php:200:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($php),
apps/gateway/tests/E2E/Support/Pest.php:486:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/ToolInstallTest.php:34:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo \\App\\Models\\NodeTool::query()->where('name', 'redis')->value('expected_state');"),
apps/gateway/tests/E2E/DatabaseUpdateTest.php:45:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/DatabaseUpdateTest.php:71:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
apps/gateway/tests/E2E/DatabaseAddTest.php:50:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
apps/gateway/tests/E2E/AppShowTest.php:46:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/ProcessCommandTest.php:129:            "cd {$checkout} && orbit tinker --execute=".escapeshellarg("echo \\App\\Models\\Process::query()->where('name', '{$process}')->whereHas('app', fn (\$query) => \$query->where('name', '{$app}'))->exists() ? 'present' : 'absent';"),
apps/gateway/tests/E2E/ProcessCommandTest.php:175:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
apps/gateway/tests/E2E/ProcessCommandTest.php:204:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script).' >/dev/null 2>&1 || true',
apps/gateway/tests/E2E/DatabaseDescribeTest.php:53:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/DatabaseDescribeTest.php:79:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
apps/gateway/tests/E2E/ScheduleAddTest.php:51:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/ScheduleAddTest.php:62:                escapeshellarg('orbit schedule:run'),
apps/gateway/tests/E2E/AppNewTest.php:37:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/AppNewTest.php:84:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo json_encode([
apps/gateway/tests/E2E/RegistryPromptInputModeTest.php:111:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/ScheduleSchedulerTickTest.php:88:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/ScheduleSchedulerTickTest.php:119:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/PreparedTopologyContractTest.php:400:        'cd /home/orbit/orbit && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/PreparedTopologyContractTest.php:411:    return 'cd '.escapeshellarg($orbitPath).' && orbit tinker --execute='.escapeshellarg("eval(base64_decode('{$encodedPhp}'));");
apps/gateway/tests/E2E/AppListTest.php:63:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/ToolUpdateTest.php:35:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo \\App\\Models\\NodeTool::query()->where('name', 'redis')->value('expected_version');"),
apps/gateway/tests/E2E/ToolUpdateTest.php:95:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/WorkspaceLogTest.php:109:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/WorkspaceSetupTest.php:56:            "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/WorkspaceSetupTest.php:115:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo json_encode([
apps/gateway/tests/E2E/WorkspaceSetupTest.php:172:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg(workspaceSetupOpencodeResolverScript($workspacePath)),
apps/gateway/tests/E2E/WorkspaceSetupTest.php:183:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo json_encode([
apps/gateway/tests/E2E/WorkspaceSetupTest.php:285:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/ScheduleListTest.php:63:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/DatabaseTablesTest.php:84:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/DatabaseTablesTest.php:110:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
apps/gateway/tests/E2E/ProfileTest.php:49:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/NodeUpdateTest.php:38:                'cd %s && orbit tinker --execute=%s',
apps/gateway/tests/E2E/NodeDefaultTest.php:50:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/NodeDefaultTest.php:99:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/WorkspaceNewTest.php:56:            "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/WorkspaceNewTest.php:117:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo json_encode([
apps/gateway/tests/E2E/NodeShowGrantTest.php:37:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/WorkspaceStepListTest.php:74:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/PhpRuntimeCommandsTest.php:71:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/PhpRuntimeCommandsTest.php:102:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
apps/gateway/tests/E2E/NodeRevokeTest.php:37:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/DatabaseSchemaTest.php:53:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/DatabaseSchemaTest.php:78:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
apps/gateway/tests/E2E/ProcessCrashEventIngestTest.php:92:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
apps/gateway/tests/E2E/ProcessCrashEventIngestTest.php:116:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
apps/gateway/tests/E2E/ProcessCrashEventIngestTest.php:151:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
apps/gateway/tests/E2E/ProcessCrashEventIngestTest.php:173:        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script).' >/dev/null 2>&1 || true',
apps/gateway/tests/E2E/DatabaseRemoveTest.php:44:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/E2E/DatabaseRemoveTest.php:69:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
apps/gateway/tests/E2E/AppAgentIdeTest.php:37:        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
apps/gateway/tests/E2E/AppAgentIdeTest.php:118:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg("echo json_encode([
apps/gateway/tests/E2E/ScheduleShowTest.php:63:            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
apps/gateway/tests/Feature/E2ESupport/E2EOperatorIdentityTest.php:32:                && ! str_contains($command, 'orbit tinker')
apps/gateway/tests/Feature/E2ESupport/E2EPestHelpersTest.php:572:            ->and($commands[0])->toContain("cd '/home/operator/orbit-current' && orbit tinker --execute=")
apps/gateway/tests/Feature/E2ESupport/E2EPestHelpersTest.php:621:            ->and($dockerCommands[0])->not->toContain('orbit tinker --execute')
apps/gateway/tests/Feature/E2ESupport/E2EPestHelpersTest.php:625:            ->and($dockerCommands[1])->toContain('orbit tinker --execute')
apps/gateway/tests/Feature/E2ESupport/E2EPestHelpersTest.php:677:            ->and($dockerCommands[0])->not->toContain('orbit tinker --execute')
apps/gateway/tests/Feature/E2ESupport/E2EPestHelpersTest.php:681:            ->and($dockerCommands[1])->toContain('orbit tinker --execute')
apps/gateway/tests/Feature/E2ESupport/E2EGatewayApiTest.php:240:        && str_contains($command, 'orbit tinker --execute='));
apps/gateway/tests/Feature/E2ESupport/E2EGatewayApiTest.php:251:        ->toContain('orbit tinker --execute=')
apps/gateway/tests/Feature/E2ESupport/E2EGatewayApiTest.php:320:        && str_contains($command, 'orbit tinker --execute='));
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:374:    $operatorMigrate = strpos($setup, "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-operator' sh -lc 'cd /home/orbit/orbit && orbit migrate --force'");
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:375:    $gatewayMigrate = strpos($setup, "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-gateway' sh -lc 'cd /home/orbit/orbit && orbit migrate --force'");
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:390:        ->toContain('cd /home/orbit/orbit && orbit migrate --force')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:502:    $operatorSync = strpos($setup, "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-operator' sh -lc 'cd /home/orbit/orbit && orbit migrate --force'");
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:538:        ->toContain('orbit tinker --execute=')
apps/gateway/app/E2E/Support/E2EGatewayApi.php:48:            'cd /home/orbit/orbit && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/app/E2E/Support/E2EGatewayApi.php:191:            "cd {$orbitPathArgument} && mkdir -p apps/gateway/storage/framework/cache/data apps/gateway/storage/framework/sessions apps/gateway/storage/framework/testing apps/gateway/storage/framework/views apps/gateway/storage/logs && ([ -f apps/gateway/.env ] || cp apps/gateway/.env.example apps/gateway/.env) && grep -Ev '^(ORBIT_IS_GATEWAY|ORBIT_E2E_TRUST_WIREGUARD_HEADER|VIEW_COMPILED_PATH|ORBIT_E2E_TOPOLOGY_PROVIDER)=' apps/gateway/.env > apps/gateway/.env.tmp && mv apps/gateway/.env.tmp apps/gateway/.env && printf '\\nORBIT_IS_GATEWAY=true\\nORBIT_E2E_TRUST_WIREGUARD_HEADER=true\\nVIEW_COMPILED_PATH=%s\\n' {$viewCompiledPath} >> apps/gateway/.env && {$dockerTopologyProviderEnv} && ".self::appKeyCommand().' && orbit tinker --execute='.escapeshellarg("app(\\App\\Services\\Ca\\OrbitCaService::class)->issueLeaf({$certKeyValue}, {$certSansValue}); echo 'issued';"),
apps/gateway/app/E2E/Support/E2EGatewayApi.php:270:        $certificateCommand = "mkdir -p apps/gateway/storage/framework/cache/data apps/gateway/storage/framework/sessions apps/gateway/storage/framework/testing apps/gateway/storage/framework/views apps/gateway/storage/logs && ([ -f apps/gateway/.env ] || cp apps/gateway/.env.example apps/gateway/.env) && grep -Ev '^(ORBIT_IS_GATEWAY|ORBIT_E2E_TRUST_WIREGUARD_HEADER|VIEW_COMPILED_PATH|ORBIT_E2E_TOPOLOGY_PROVIDER)=' apps/gateway/.env > apps/gateway/.env.tmp && mv apps/gateway/.env.tmp apps/gateway/.env && printf '\\nORBIT_IS_GATEWAY=true\\nORBIT_E2E_TRUST_WIREGUARD_HEADER=true\\nVIEW_COMPILED_PATH=%s\\n' {$viewCompiledPath} >> apps/gateway/.env && {$dockerTopologyProviderEnv} && ".self::appKeyCommand().' && orbit tinker --execute='.escapeshellarg("app(\\App\\Services\\Ca\\OrbitCaService::class)->issueLeaf({$certKeyValue}, {$certSansValue}); echo 'issued';");
apps/gateway/app/E2E/Support/E2EGatewayApi.php:326:                'sudo docker exec --detach --env %s --workdir %s %s orbit tinker --execute=%s',
apps/gateway/app/E2E/Support/E2EGatewayApi.php:390:            'cd /home/orbit/orbit && orbit tinker --execute='.escapeshellarg($php),
apps/gateway/tests/Feature/E2ESupport/DockerTopologyProviderTest.php:122:        ->toContain('orbit tinker --execute=')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyProviderTest.php:1273:        ->toContain('orbit tinker --execute=')
apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:659:        E2ECommand::ssh($instance, $user, new SshKeyPair('/dev/null', '/dev/null'), "cd {$path} && orbit migrate --force", timeoutSeconds: 120);
apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:748:                'orbit tinker --execute='.escapeshellarg(E2EPreparedTopologyRegistry::appdevDatabaseAndRedisPhp()),
apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:911:            'orbit tinker --execute='.escapeshellarg($this->clientGatewaySettingsPhp($mode, $networkPlan)),
apps/gateway/app/E2E/Support/DockerTopologyProvider.php:708:            $commands[] = 'orbit tinker --execute='.escapeshellarg(E2EPreparedTopologyRegistry::appdevDatabaseAndRedisPhp());
apps/gateway/app/E2E/Support/DockerTopologyProvider.php:932:            'cd /home/orbit/orbit && orbit tinker --execute='.escapeshellarg($php),
apps/docs/content/domains/9_schedule/5_schedule-run/technical/1_schedule-run.md:16:orbit schedule:run [name] [--app=<app>] [--node=<node>] [--json]
apps/docs/content/domains/9_schedule/5_schedule-run/schedule-run.md:12:orbit schedule:run [name] [--app=<app>] [--node=<node>] [--json]
apps/docs/content/domains/9_schedule/5_schedule-run/schedule-run.md:18:orbit schedule:run laravel-scheduler --app=docs
apps/docs/content/domains/9_schedule/5_schedule-run/schedule-run.md:19:orbit schedule:run backups --node=app-1
apps/docs/content/domains/9_schedule/5_schedule-run/technical/1_schedule-run.md:16:orbit schedule:run [name] [--app=<app>] [--node=<node>] [--json]
apps/docs/content/domains/9_schedule/5_schedule-run/schedule-run.md:12:orbit schedule:run [name] [--app=<app>] [--node=<node>] [--json]
apps/docs/content/domains/9_schedule/5_schedule-run/schedule-run.md:18:orbit schedule:run laravel-scheduler --app=docs
apps/docs/content/domains/9_schedule/5_schedule-run/schedule-run.md:19:orbit schedule:run backups --node=app-1
```

## Sweep 2 — `orbit:internal:*`, `apps/gateway/artisan`, and existing `bin/orbit-gateway-artisan` references

```
bin/_orbit-gateway-paths.sh:24:    if [ -f "${root}/apps/gateway/artisan" ]; then
bin/_orbit-gateway-paths.sh:38:    printf '%s\n' "${root}/apps/gateway/artisan"
docker/orbit-runtime/entrypoint.sh:20:    printf '%s\n' "${source_path}/apps/gateway/artisan"
docker/orbit-runtime/entrypoint.sh:37:    while [ ! -f "${source_path}/apps/gateway/artisan" ]; do
apps/docs/content/porting/testing-infrastructure.md:79:  and uses `bin/orbit-gateway-artisan` or direct `php apps/gateway/artisan`
bin/install-orbit:522:        if [ -e "$TARGET_DIR" ] && [ ! -f "$TARGET_DIR/apps/gateway/artisan" ]; then
bin/install-orbit:877:        php /opt/orbit/apps/gateway/artisan migrate --force --no-interaction
bin/quality-check.sh:67:bin/orbit-gateway-artisan config:clear --ansi >/dev/null 2>&1 || true
bin/orbit:147:    exec php "${ORBIT_REPO}/apps/gateway/artisan" "$@"
apps/docs/content/tech-stack.md:89:(migrate, tinker, scheduler, queue, `orbit:internal:*` bake/build/install
apps/docs/content/tech-stack.md:90:commands) uses `bin/orbit-gateway-artisan` or direct
apps/docs/content/tech-stack.md:91:`php apps/gateway/artisan` from a controlled gateway shell; the public
apps/docs/content/concepts.md:42:- **Orbit launcher** — host `orbit` executable that always enters `apps/cli/orbit` and passes `ORBIT_HOST_CWD` when local path context is needed. Gateway maintenance uses `bin/orbit-gateway-artisan` or direct `php apps/gateway/artisan` from a controlled gateway shell. See [Node Concepts](domains/1_node/node-concepts.md).
apps/gateway/tests/E2E/RegistryPromptInputModeTest.php:156:    return sprintf('cd /tmp && ORBIT_HOST_CWD=/tmp ORBIT_IS_GATEWAY=1 php %s %s', escapeshellarg("{$checkout}/apps/gateway/artisan"), $commandArguments);
apps/gateway/tests/E2E/PreparedTopologyContractTest.php:237:        'cd '.escapeshellarg($orbitPath).' && test -f apps/gateway/artisan && orbit --version',
apps/docs/content/domains/authorization-matrix.md:135:Internal `orbit:internal:*` commands are not public grant surfaces. They are
apps/docs/content/testing/e2e/README.md:27:`composer test:e2e` runs `bin/orbit-gateway-artisan e2e:test`, which selects prepared-topology
apps/gateway/tests/E2E/ToolLifecycleHostInitTest.php:124:            'cd %s && php apps/gateway/artisan tinker --execute=%s',
apps/gateway/tests/E2E/ToolLifecycleHostInitTest.php:177:        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/ToolLifecycleHostInitTest.php:187:            'cd %s && php apps/gateway/artisan %s',
apps/gateway/tests/E2E/ToolLifecycleHostInitTest.php:228:timeout 8s php apps/gateway/artisan tool:logs supervisor --node=app-dev-1 --lines=1 --follow > /tmp/orbit-tool-follow.log 2>&1 || true
apps/gateway/tests/E2E/ToolLifecycleHostInitTest.php:248:timeout 8s php apps/gateway/artisan tool:logs supervisor --node=app-dev-1 --lines=1 --follow > /tmp/orbit-tool-follow-forwarded.log 2>&1 || true
apps/docs/content/testing/e2e/environment.md:59:`composer test:e2e` runs `bin/orbit-gateway-artisan e2e:test`, which reads `ORBIT_E2E_LANES`
apps/gateway/tests/E2E/FirewallDoctorAdoptTest.php:37:                'cd %s && php apps/gateway/artisan tinker --execute=%s',
apps/gateway/tests/E2E/FirewallDoctorAdoptTest.php:47:                'cd %s && php apps/gateway/artisan doctor --node=app-dev-1 --family=firewall_rule --adopt --json',
apps/gateway/tests/E2E/FirewallDoctorAdoptTest.php:62:                'cd %s && php apps/gateway/artisan tinker --execute=%s',
apps/gateway/tests/E2E/NodeListAgentTopologyTest.php:32:                'cd %s && php apps/gateway/artisan node:list --role=agent --json',
apps/gateway/tests/E2E/NodeUpdatesDoctorTest.php:103:        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/NodeUpdatesDoctorTest.php:134:            'cd %s && php apps/gateway/artisan doctor --node=app-dev-1 --family=node --key=node.updates --json',
apps/gateway/tests/Unit/Console/Commands/VpnCommandSupportTest.php:19:    expect(str_starts_with($script, 'php apps/gateway/artisan vpn-client:list'))->toBeTrue();
apps/gateway/tests/Unit/Console/Commands/VpnCommandSupportTest.php:32:    expect($script)->toBe("php apps/gateway/artisan vpn-client:new 'laptop' --json");
apps/gateway/tests/E2E/PreparedTopologyContractTest.php:237:        'cd '.escapeshellarg($orbitPath).' && test -f apps/gateway/artisan && orbit --version',
apps/gateway/tests/E2E/FirewallDoctorAdoptTest.php:37:                'cd %s && php apps/gateway/artisan tinker --execute=%s',
apps/gateway/tests/E2E/FirewallDoctorAdoptTest.php:47:                'cd %s && php apps/gateway/artisan doctor --node=app-dev-1 --family=firewall_rule --adopt --json',
apps/gateway/tests/E2E/FirewallDoctorAdoptTest.php:62:                'cd %s && php apps/gateway/artisan tinker --execute=%s',
apps/gateway/tests/E2E/NodeUpdatesDoctorTest.php:103:        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/NodeUpdatesDoctorTest.php:134:            'cd %s && php apps/gateway/artisan doctor --node=app-dev-1 --family=node --key=node.updates --json',
apps/gateway/tests/E2E/ToolLifecycleHostInitTest.php:124:            'cd %s && php apps/gateway/artisan tinker --execute=%s',
apps/gateway/tests/E2E/ToolLifecycleHostInitTest.php:177:        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
apps/gateway/tests/E2E/ToolLifecycleHostInitTest.php:187:            'cd %s && php apps/gateway/artisan %s',
apps/gateway/tests/E2E/ToolLifecycleHostInitTest.php:228:timeout 8s php apps/gateway/artisan tool:logs supervisor --node=app-dev-1 --lines=1 --follow > /tmp/orbit-tool-follow.log 2>&1 || true
apps/gateway/tests/E2E/ToolLifecycleHostInitTest.php:248:timeout 8s php apps/gateway/artisan tool:logs supervisor --node=app-dev-1 --lines=1 --follow > /tmp/orbit-tool-follow-forwarded.log 2>&1 || true
apps/gateway/tests/E2E/NodeListAgentTopologyTest.php:32:                'cd %s && php apps/gateway/artisan node:list --role=agent --json',
apps/gateway/tests/E2E/RegistryPromptInputModeTest.php:156:    return sprintf('cd /tmp && ORBIT_HOST_CWD=/tmp ORBIT_IS_GATEWAY=1 php %s %s', escapeshellarg("{$checkout}/apps/gateway/artisan"), $commandArguments);
apps/gateway/tests/Feature/E2ESupport/DockerRuntimeImageContractTest.php:69:    file_put_contents("{$source}/apps/gateway/artisan", "<?php\n");
apps/gateway/tests/Feature/E2ESupport/DockerRuntimeImageContractTest.php:98:            ->toContain("argv={$source}/apps/gateway/artisan about --ansi")
apps/gateway/tests/Feature/E2ESupport/DockerRuntimeImageContractTest.php:171:    file_put_contents("{$gateway}/artisan", file_get_contents(repo_path('apps/gateway/artisan')));
apps/gateway/tests/Feature/E2ESupport/DockerRuntimeImageContractTest.php:398:            '! test -f /home/operator/orbit/apps/gateway/artisan',
apps/gateway/tests/Feature/E2ESupport/DockerRuntimeImageContractTest.php:400:            '! test -f /home/orbit/orbit/apps/gateway/artisan',
apps/gateway/app/Console/Commands/NodeNewCommand.php:2336:            'orbit orbit:internal:bootstrap-gateway-local %s %s --identity-json=- --public-host=%s --tld=%s --metadata-json',
apps/gateway/app/Console/Commands/NodeNewCommand.php:2880:        $detectCommand = 'orbit orbit:internal:detect-platform --update-local-node';
apps/gateway/tests/Feature/E2ESupport/DockerRuntimeImageContractTest.php:69:    file_put_contents("{$source}/apps/gateway/artisan", "<?php\n");
apps/gateway/tests/Feature/E2ESupport/DockerRuntimeImageContractTest.php:98:            ->toContain("argv={$source}/apps/gateway/artisan about --ansi")
apps/gateway/tests/Feature/E2ESupport/DockerRuntimeImageContractTest.php:171:    file_put_contents("{$gateway}/artisan", file_get_contents(repo_path('apps/gateway/artisan')));
apps/gateway/tests/Feature/E2ESupport/DockerRuntimeImageContractTest.php:398:            '! test -f /home/operator/orbit/apps/gateway/artisan',
apps/gateway/tests/Feature/E2ESupport/DockerRuntimeImageContractTest.php:400:            '! test -f /home/orbit/orbit/apps/gateway/artisan',
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:432:        ->toContain("grep -Eq '^APP_KEY=base64:.+' apps/gateway/.env || php apps/gateway/artisan key:generate --force --no-interaction --ansi")
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:464:        ->toContain('/home/orbit/orbit-current/apps/gateway/artisan')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:518:        ->toContain('php apps/gateway/artisan key:generate --force --no-interaction --ansi')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:519:        ->toContain('php apps/gateway/artisan migrate --force --ansi')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:520:        ->toContain('php apps/gateway/artisan tinker --execute')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:567:        ->toContain('php apps/gateway/artisan key:generate')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:568:        ->toContain('php apps/gateway/artisan migrate --force')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:569:        ->toContain('php apps/gateway/artisan tinker --execute')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:580:        ->toContain('php apps/gateway/artisan key:generate')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:581:        ->toContain('php apps/gateway/artisan migrate --force')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:582:        ->toContain('php apps/gateway/artisan tinker --execute')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:624:        fn (string $command): bool => str_contains($command, 'orbit:internal:pin-node-host-keys --json'),
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:630:        ->toContain('orbit:internal:pin-node-host-keys --json')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:631:        ->toContain('/home/orbit/orbit-current/apps/gateway/artisan')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:632:        ->not->toContain('orbit orbit:internal:pin-node-host-keys --json')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:633:        ->not->toContain('php artisan orbit:internal:pin-node-host-keys --json')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:851:    expect(implode("\n", $gatewayCommands))->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:853:    expect(implode("\n", $devCommands))->not->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:855:    expect(implode("\n", $prodCommands))->not->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:857:    expect(implode("\n", $agentCommands))->not->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:432:        ->toContain("grep -Eq '^APP_KEY=base64:.+' apps/gateway/.env || php apps/gateway/artisan key:generate --force --no-interaction --ansi")
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:464:        ->toContain('/home/orbit/orbit-current/apps/gateway/artisan')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:518:        ->toContain('php apps/gateway/artisan key:generate --force --no-interaction --ansi')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:519:        ->toContain('php apps/gateway/artisan migrate --force --ansi')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:520:        ->toContain('php apps/gateway/artisan tinker --execute')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:567:        ->toContain('php apps/gateway/artisan key:generate')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:568:        ->toContain('php apps/gateway/artisan migrate --force')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:569:        ->toContain('php apps/gateway/artisan tinker --execute')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:580:        ->toContain('php apps/gateway/artisan key:generate')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:581:        ->toContain('php apps/gateway/artisan migrate --force')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:582:        ->toContain('php apps/gateway/artisan tinker --execute')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:624:        fn (string $command): bool => str_contains($command, 'orbit:internal:pin-node-host-keys --json'),
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:630:        ->toContain('orbit:internal:pin-node-host-keys --json')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:631:        ->toContain('/home/orbit/orbit-current/apps/gateway/artisan')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:632:        ->not->toContain('orbit orbit:internal:pin-node-host-keys --json')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:633:        ->not->toContain('php artisan orbit:internal:pin-node-host-keys --json')
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:851:    expect(implode("\n", $gatewayCommands))->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:853:    expect(implode("\n", $devCommands))->not->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:855:    expect(implode("\n", $prodCommands))->not->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php:857:    expect(implode("\n", $agentCommands))->not->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
apps/gateway/tests/Feature/E2ESupport/E2EOperatorIdentityTest.php:31:            m::on(fn (string $command): bool => str_contains($command, 'php apps/gateway/artisan tinker --execute=')
apps/gateway/tests/Feature/E2ESupport/E2ENodeProbeTest.php:23:        ->with('test -d /home/orbit/orbit && test -f /home/orbit/orbit/apps/gateway/artisan')
apps/gateway/app/Console/Commands/VpnCommandSupport.php:122:        $parts = ['php apps/gateway/artisan', $command];
apps/gateway/tests/Feature/E2ESupport/E2EPestHelpersTest.php:59:        ->toContain("php '/home/orbit/orbit-current/apps/gateway/artisan' \"\$@\"")
apps/gateway/tests/Feature/E2ESupport/E2EPestHelpersTest.php:61:        ->toContain("exec php '/home/orbit/orbit-current/apps/gateway/artisan'")
apps/gateway/tests/Feature/E2ESupport/E2EGatewayApiTest.php:327:        ->toContain('php\ */apps/gateway/artisan\ serve\ --host=');
apps/gateway/tests/Feature/E2ESupport/E2EGatewayApiTest.php:338:        'php /home/orbit/orbit/apps/gateway/artisan serve --host=0.0.0.0 --port=80 --tries=1 --no-reload --quiet',
apps/gateway/tests/Feature/E2ESupport/E2EOperatorIdentityTest.php:31:            m::on(fn (string $command): bool => str_contains($command, 'php apps/gateway/artisan tinker --execute=')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:377:    $gatewayBootstrap = strpos($setup, 'cd /home/orbit/orbit && orbit orbit:internal:bootstrap-gateway-local gateway 10.6.0.2');
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:391:        ->toContain('cd /home/orbit/orbit && orbit orbit:internal:bootstrap-gateway-local gateway 10.6.0.2 --skip-runtime-install --skip-wireguard-install')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:419:    $bootstrap = strpos($setup, 'orbit:internal:bootstrap-gateway-local gateway 10.6.0.2');
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:602:        ->first(fn (string $command): bool => str_contains($command, 'orbit:internal:bootstrap-gateway-local gateway 10.6.0.2'));
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:617:        ->toContain('orbit:internal:bake-app-node app-dev-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:618:        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:619:        ->toContain('orbit:internal:bake-app-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:620:        ->toContain('orbit:internal:bake-agent-node agent-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:828:        && str_contains($process->command, 'orbit:internal:bake-app-node app-dev-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:904:        && str_contains($process->command, 'orbit:internal:bootstrap-gateway-local gateway 10.6.0.2')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:912:        && str_contains($process->command, 'orbit:internal:bake-ingress-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:920:        && str_contains($process->command, 'orbit:internal:bake-app-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:965:        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:968:        ->toContain('orbit:internal:bake-app-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:970:        ->not->toContain('orbit:internal:bake-ingress-node edge-1')
apps/gateway/tests/Feature/E2ESupport/E2ENodeProbeTest.php:23:        ->with('test -d /home/orbit/orbit && test -f /home/orbit/orbit/apps/gateway/artisan')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php:307:            ->and($commandOutput)->toContain('php apps/gateway/artisan tinker --execute=')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php:552:        ->and($commandOutput)->toContain('orbit:internal:bootstrap-gateway-local gateway')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php:559:        ->and($commandOutput)->toContain('php apps/gateway/artisan orbit:internal:bootstrap-gateway-local gateway')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php:560:        ->and($commandOutput)->toContain('php apps/gateway/artisan tinker --execute=')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php:570:        ->and($commandOutput)->not->toContain('orbit:internal:bake-app-node');
apps/gateway/tests/Feature/E2ESupport/E2EPestHelpersTest.php:59:        ->toContain("php '/home/orbit/orbit-current/apps/gateway/artisan' \"\$@\"")
apps/gateway/tests/Feature/E2ESupport/E2EPestHelpersTest.php:61:        ->toContain("exec php '/home/orbit/orbit-current/apps/gateway/artisan'")
apps/gateway/tests/Feature/E2ESupport/IncusTopologyProviderTest.php:12:        ->and($checkoutSource)->toContain("self::artisanCommand('orbit:internal:pin-node-host-keys --json'");
apps/gateway/tests/Feature/E2ESupport/E2EGatewayApiTest.php:327:        ->toContain('php\ */apps/gateway/artisan\ serve\ --host=');
apps/gateway/tests/Feature/E2ESupport/E2EGatewayApiTest.php:338:        'php /home/orbit/orbit/apps/gateway/artisan serve --host=0.0.0.0 --port=80 --tries=1 --no-reload --quiet',
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:126:    $e2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; bin/orbit-gateway-artisan e2e:test @additional_args';
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:127:    $dockerE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=docker bin/orbit-gateway-artisan e2e:test @additional_args';
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:128:    $dockerCanaryE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=docker bin/orbit-gateway-artisan e2e:test --canary @additional_args';
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:129:    $incusE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=incus bin/orbit-gateway-artisan e2e:test @additional_args';
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:147:        'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E=1 bin/orbit-gateway-artisan test --testsuite=E2E --group=e2e-provision --fail-on-empty-test-suite @additional_args',
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:248:    expect($composer['scripts']['e2e:preflight'])->toBe("{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:preflight @additional_args")
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:251:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:prepare-docker-runtime @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:254:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:prepare-docker-topology @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:257:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:prepare-docker-hosts @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:260:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:ensure-artifacts @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:263:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:prepare-base-image @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:266:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:prepare-topology @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:269:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:prepare-warm-topology @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:270:        ])->and($composer['scripts']['e2e:reap-incus'])->toBe("{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:reap-incus @additional_args")
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:271:        ->and($composer['scripts']['e2e:reap-docker'])->toBe("{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:reap-docker @additional_args");
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:493:        'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=docker bin/orbit-gateway-artisan test --testsuite=E2E --group=e2e-topology-contract-operator_gateway_app-dev_app-prod --fail-on-empty-test-suite @additional_args',
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:377:    $gatewayBootstrap = strpos($setup, 'cd /home/orbit/orbit && orbit orbit:internal:bootstrap-gateway-local gateway 10.6.0.2');
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:391:        ->toContain('cd /home/orbit/orbit && orbit orbit:internal:bootstrap-gateway-local gateway 10.6.0.2 --skip-runtime-install --skip-wireguard-install')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:419:    $bootstrap = strpos($setup, 'orbit:internal:bootstrap-gateway-local gateway 10.6.0.2');
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:602:        ->first(fn (string $command): bool => str_contains($command, 'orbit:internal:bootstrap-gateway-local gateway 10.6.0.2'));
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:617:        ->toContain('orbit:internal:bake-app-node app-dev-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:618:        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:619:        ->toContain('orbit:internal:bake-app-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:620:        ->toContain('orbit:internal:bake-agent-node agent-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:828:        && str_contains($process->command, 'orbit:internal:bake-app-node app-dev-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:904:        && str_contains($process->command, 'orbit:internal:bootstrap-gateway-local gateway 10.6.0.2')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:912:        && str_contains($process->command, 'orbit:internal:bake-ingress-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:920:        && str_contains($process->command, 'orbit:internal:bake-app-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:965:        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:968:        ->toContain('orbit:internal:bake-app-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php:970:        ->not->toContain('orbit:internal:bake-ingress-node edge-1')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:624:        ->toContain('php apps/gateway/artisan orbit:internal:bootstrap-gateway-local')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:625:        ->toContain('php apps/gateway/artisan orbit:internal:bake-app-node app-dev-1 --role=app-dev')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:626:        ->toContain('php apps/gateway/artisan tinker --execute=')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:629:        ->toContain('orbit:internal:bake-app-node app-dev-1 --role=app-dev')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:631:        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:633:        ->toContain('orbit:internal:bake-app-node app-prod-1 --role=app-prod')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:634:        ->toContain('orbit:internal:bake-agent-node agent-1')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:658:        ->and($source)->toContain('orbit:internal:bake-agent-node agent-1')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php:307:            ->and($commandOutput)->toContain('php apps/gateway/artisan tinker --execute=')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php:552:        ->and($commandOutput)->toContain('orbit:internal:bootstrap-gateway-local gateway')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php:559:        ->and($commandOutput)->toContain('php apps/gateway/artisan orbit:internal:bootstrap-gateway-local gateway')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php:560:        ->and($commandOutput)->toContain('php apps/gateway/artisan tinker --execute=')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php:570:        ->and($commandOutput)->not->toContain('orbit:internal:bake-app-node');
apps/gateway/tests/Feature/E2ESupport/DockerTopologyProviderTest.php:813:        ->toContain('orbit:internal:bake-app-node app-dev-1 --role=app-dev')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyProviderTest.php:817:        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyProviderTest.php:818:        ->toContain('orbit:internal:bake-app-node app-prod-1 --role=app-prod')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyProviderTest.php:12:        ->and($checkoutSource)->toContain("self::artisanCommand('orbit:internal:pin-node-host-keys --json'");
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:126:    $e2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; bin/orbit-gateway-artisan e2e:test @additional_args';
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:127:    $dockerE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=docker bin/orbit-gateway-artisan e2e:test @additional_args';
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:128:    $dockerCanaryE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=docker bin/orbit-gateway-artisan e2e:test --canary @additional_args';
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:129:    $incusE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=incus bin/orbit-gateway-artisan e2e:test @additional_args';
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:147:        'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E=1 bin/orbit-gateway-artisan test --testsuite=E2E --group=e2e-provision --fail-on-empty-test-suite @additional_args',
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:248:    expect($composer['scripts']['e2e:preflight'])->toBe("{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:preflight @additional_args")
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:251:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:prepare-docker-runtime @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:254:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:prepare-docker-topology @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:257:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:prepare-docker-hosts @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:260:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:ensure-artifacts @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:263:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:prepare-base-image @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:266:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:prepare-topology @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:269:            "{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:prepare-warm-topology @additional_args",
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:270:        ])->and($composer['scripts']['e2e:reap-incus'])->toBe("{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:reap-incus @additional_args")
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:271:        ->and($composer['scripts']['e2e:reap-docker'])->toBe("{$e2eEnvPrefix} bin/orbit-gateway-artisan e2e:reap-docker @additional_args");
apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php:493:        'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=docker bin/orbit-gateway-artisan test --testsuite=E2E --group=e2e-topology-contract-operator_gateway_app-dev_app-prod --fail-on-empty-test-suite @additional_args',
apps/gateway/build/phpstan/resultCache.php:55391:            0 => '\'orbit:internal:bake-agent-node
apps/gateway/build/phpstan/resultCache.php:55503:            0 => '\'orbit:internal:bake-app-node
apps/gateway/build/phpstan/resultCache.php:55618:            0 => '\'orbit:internal:bake-ingress-node
apps/gateway/build/phpstan/resultCache.php:55749:            0 => '\'orbit:internal:bootstrap-gateway-local
apps/gateway/build/phpstan/resultCache.php:55879:            0 => '\'orbit:internal:build-runtime-images
apps/gateway/build/phpstan/resultCache.php:56090:            0 => '\'orbit:internal:detect-platform
apps/gateway/build/phpstan/resultCache.php:56164:            0 => '\'orbit:internal:install-orbit-dns\'',
apps/gateway/build/phpstan/resultCache.php:56256:            0 => '\'orbit:internal:pin-node-host-keys
apps/gateway/build/phpstan/resultCache.php:56885:            0 => '\'orbit:internal:node-register
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:624:        ->toContain('php apps/gateway/artisan orbit:internal:bootstrap-gateway-local')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:625:        ->toContain('php apps/gateway/artisan orbit:internal:bake-app-node app-dev-1 --role=app-dev')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:626:        ->toContain('php apps/gateway/artisan tinker --execute=')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:629:        ->toContain('orbit:internal:bake-app-node app-dev-1 --role=app-dev')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:631:        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:633:        ->toContain('orbit:internal:bake-app-node app-prod-1 --role=app-prod')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:634:        ->toContain('orbit:internal:bake-agent-node agent-1')
apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php:658:        ->and($source)->toContain('orbit:internal:bake-agent-node agent-1')
apps/gateway/tests/Unit/Services/OrbitUpdaterTest.php:27:        && $process->command[4] === 'apps/gateway/artisan'
apps/gateway/tests/Unit/Services/OrbitUpdaterTest.php:64:        'docker exec orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Feature/E2ESupport/DockerTopologyProviderTest.php:813:        ->toContain('orbit:internal:bake-app-node app-dev-1 --role=app-dev')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyProviderTest.php:817:        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
apps/gateway/tests/Feature/E2ESupport/DockerTopologyProviderTest.php:818:        ->toContain('orbit:internal:bake-app-node app-prod-1 --role=app-prod')
apps/gateway/app/Console/Commands/Internal/BakeAgentNodeCommand.php:17:#[Signature('orbit:internal:bake-agent-node
apps/gateway/app/Console/Commands/Internal/PinNodeHostKeysCommand.php:16:#[Signature('orbit:internal:pin-node-host-keys
apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php:167:            ->toContain('orbit:internal:build-runtime-images')
apps/gateway/app/Console/Commands/Internal/BakeIngressNodeCommand.php:18:#[Signature('orbit:internal:bake-ingress-node
apps/gateway/app/Console/Commands/Internal/DetectPlatformCommand.php:14:#[Signature('orbit:internal:detect-platform
apps/gateway/app/Console/Commands/Internal/BakeAppNodeCommand.php:18:#[Signature('orbit:internal:bake-app-node
apps/gateway/app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php:30:#[Signature('orbit:internal:bootstrap-gateway-local
apps/gateway/app/Console/Commands/Internal/InstallOrbitDnsCommand.php:12:#[Signature('orbit:internal:install-orbit-dns')]
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:44:        'docker exec -i orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:69:            && str_contains($command, 'orbit-runtime php apps/gateway/artisan migrate --force');
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:73:        'docker exec orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:77:        'docker exec -i orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:81:        'docker exec --interactive orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:85:        'docker exec -t orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:89:        'docker exec -it orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:93:        'docker exec -i --user orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:97:        'docker exec -i --workdir /opt/orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:101:        'docker exec -e KEY=val orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:105:        'docker exec --user=orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:109:        'docker exec -u orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:113:        'docker exec --workdir=/opt/orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:117:        'docker exec -w /opt/orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:121:        'docker exec --env=KEY=val orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:125:        'docker exec -eKEY=val orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:129:        'docker exec --env-file /tmp/runtime.env orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:133:        'docker exec --privileged orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:137:        'docker exec --detach-keys ctrl-p,ctrl-q orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:141:        'docker exec --detach orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:145:        '  docker   exec   -i   orbit-runtime   php   apps/gateway/artisan   migrate   --force  ',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:159:        'docker exec -i --workdir /opt/orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:205:        'docker exec -e CALLER_KEY=caller -e ORBIT_REQUEST_ID=caller orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:284:            && str_contains($command, 'orbit-runtime php apps/gateway/artisan orbit:cleanup')
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:300:        'php apps/gateway/artisan migrate --force && php apps/gateway/artisan orbit:cleanup',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:312:            && str_contains($command, 'php apps/gateway/artisan migrate --force && php apps/gateway/artisan orbit:cleanup');
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:330:            ->and($exception->script)->toBe('docker exec -i orbit-runtime php apps/gateway/artisan migrate --force')
apps/gateway/app/Console/Commands/Internal/BuildRuntimeImagesCommand.php:29:#[Signature('orbit:internal:build-runtime-images
apps/gateway/tests/Feature/Commands/Vpn/VpnClientCommandTest.php:234:    expect($command)->toContain('orbit-runtime php apps/gateway/artisan vpn-client:list --json');
apps/gateway/tests/Feature/Commands/Vpn/VpnClientCommandTest.php:292:    expect($command)->toContain('php apps/gateway/artisan vpn-client:new');
apps/gateway/tests/Feature/InstallOrbitLauncherTest.php:14:            ->not->toContain('ln -sf "$TARGET_DIR/apps/gateway/artisan" "$LINK_PATH"')
apps/gateway/tests/Feature/InstallOrbitLauncherTest.php:33:        expect($capture['target'])->toBe($capture['repo'].'/apps/gateway/artisan')
apps/gateway/tests/Feature/InstallOrbitLauncherTest.php:102:            ->toContain("dirname(__DIR__, 2).'/apps/gateway/artisan'")
apps/gateway/tests/Feature/InstallOrbitLauncherTest.php:173:    orbitLauncherWriteExecutable("{$repo}/apps/gateway/artisan", orbitLauncherCaptureScript());
apps/gateway/tests/Feature/Commands/NodeNewCommandTest.php:164:                if (str_contains($process->command, 'orbit:internal:bootstrap-gateway-local')) {
apps/gateway/tests/Feature/Commands/NodeNewCommandTest.php:182:                if (str_contains($process->command, 'orbit:internal:detect-platform')) {
apps/gateway/tests/Feature/Commands/NodeNewCommandTest.php:450:            && str_contains($process->command, 'orbit orbit:internal:bootstrap-gateway-local'));
apps/gateway/tests/Feature/Commands/NodeNewCommandTest.php:451:        Process::assertRan(fn ($process): bool => str_contains($process->command, 'orbit:internal:bootstrap-gateway-local')
apps/gateway/tests/Feature/Commands/NodeNewCommandTest.php:457:            && str_contains($process->command, 'orbit orbit:internal:detect-platform --update-local-node'));
apps/gateway/tests/Feature/Commands/NodeNewCommandTest.php:164:                if (str_contains($process->command, 'orbit:internal:bootstrap-gateway-local')) {
apps/gateway/tests/Feature/Commands/NodeNewCommandTest.php:182:                if (str_contains($process->command, 'orbit:internal:detect-platform')) {
apps/gateway/tests/Feature/Commands/NodeNewCommandTest.php:450:            && str_contains($process->command, 'orbit orbit:internal:bootstrap-gateway-local'));
apps/gateway/tests/Feature/Commands/NodeNewCommandTest.php:451:        Process::assertRan(fn ($process): bool => str_contains($process->command, 'orbit:internal:bootstrap-gateway-local')
apps/gateway/tests/Feature/Commands/NodeNewCommandTest.php:457:            && str_contains($process->command, 'orbit orbit:internal:detect-platform --update-local-node'));
apps/gateway/tests/Feature/Commands/NodeRegisterCommandTest.php:11:    $this->artisan('orbit:internal:node-register', [
apps/gateway/tests/Feature/Commands/NodeRegisterCommandTest.php:11:    $this->artisan('orbit:internal:node-register', [
apps/gateway/tests/Feature/Commands/Nodes/AgentNodeNewCommandTest.php:150:        if (str_contains($command, 'orbit:internal:bootstrap-gateway-local')) {
apps/gateway/tests/Feature/Commands/Nodes/AgentNodeNewCommandTest.php:157:        if (str_contains($command, 'orbit:internal:detect-platform')) {
apps/gateway/app/Console/Commands/NodeRegisterCommand.php:13:#[Signature('orbit:internal:node-register
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:15:describe('orbit:internal:bake-app-node', function (): void {
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:40:        $this->artisan('orbit:internal:bake-app-node', [
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:72:        $this->artisan('orbit:internal:bake-app-node', [
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:103:        $this->artisan('orbit:internal:bake-app-node', $args)->assertSuccessful();
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:104:        $this->artisan('orbit:internal:bake-app-node', $args)->assertSuccessful();
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:131:        $this->artisan('orbit:internal:bake-app-node', [
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:159:        expect(fn () => $this->artisan('orbit:internal:bake-app-node', [
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:15:describe('orbit:internal:bake-app-node', function (): void {
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:40:        $this->artisan('orbit:internal:bake-app-node', [
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:72:        $this->artisan('orbit:internal:bake-app-node', [
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:103:        $this->artisan('orbit:internal:bake-app-node', $args)->assertSuccessful();
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:104:        $this->artisan('orbit:internal:bake-app-node', $args)->assertSuccessful();
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:131:        $this->artisan('orbit:internal:bake-app-node', [
apps/gateway/tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php:159:        expect(fn () => $this->artisan('orbit:internal:bake-app-node', [
apps/gateway/tests/Feature/Commands/Internal/PinNodeHostKeysCommandTest.php:15:describe('orbit:internal:pin-node-host-keys', function (): void {
apps/gateway/tests/Feature/Commands/Internal/PinNodeHostKeysCommandTest.php:84:        $this->artisan('orbit:internal:pin-node-host-keys', ['--json' => true])
apps/gateway/tests/Feature/Commands/Internal/PinNodeHostKeysCommandTest.php:106:        $this->artisan('orbit:internal:pin-node-host-keys', ['--json' => true])
apps/gateway/tests/Feature/Commands/Internal/BakeIngressNodeCommandTest.php:15:describe('orbit:internal:bake-ingress-node', function (): void {
apps/gateway/tests/Feature/Commands/Internal/BakeIngressNodeCommandTest.php:40:        $this->artisan('orbit:internal:bake-ingress-node', [
apps/docs/content/domains/3_tool/dns-bootstrap-contract.md:10:(`orbit:internal:bootstrap-gateway-local`).
apps/gateway/tests/Feature/Commands/Internal/PinNodeHostKeysCommandTest.php:15:describe('orbit:internal:pin-node-host-keys', function (): void {
apps/gateway/tests/Feature/Commands/Internal/PinNodeHostKeysCommandTest.php:84:        $this->artisan('orbit:internal:pin-node-host-keys', ['--json' => true])
apps/gateway/tests/Feature/Commands/Internal/PinNodeHostKeysCommandTest.php:106:        $this->artisan('orbit:internal:pin-node-host-keys', ['--json' => true])
apps/gateway/tests/Feature/Commands/Internal/BakeIngressNodeCommandTest.php:15:describe('orbit:internal:bake-ingress-node', function (): void {
apps/gateway/tests/Feature/Commands/Internal/BakeIngressNodeCommandTest.php:40:        $this->artisan('orbit:internal:bake-ingress-node', [
apps/gateway/tests/Feature/Commands/Internal/DetectPlatformCommandTest.php:12:describe('orbit:internal:detect-platform', function (): void {
apps/gateway/tests/Feature/Commands/Internal/DetectPlatformCommandTest.php:19:        $exitCode = Artisan::call('orbit:internal:detect-platform');
apps/gateway/tests/Feature/Commands/Internal/DetectPlatformCommandTest.php:46:        $exitCode = Artisan::call('orbit:internal:detect-platform', [
apps/gateway/tests/Feature/Commands/Internal/BuildRuntimeImagesCommandTest.php:20:    $this->artisan('orbit:internal:build-runtime-images')
apps/gateway/tests/Feature/Commands/Internal/BuildRuntimeImagesCommandTest.php:48:    $this->artisan('orbit:internal:build-runtime-images')
apps/gateway/tests/Feature/Commands/Internal/BuildRuntimeImagesCommandTest.php:65:    $this->artisan('orbit:internal:build-runtime-images', ['--force' => true])
apps/gateway/tests/Feature/Commands/Internal/BuildRuntimeImagesCommandTest.php:88:    $this->artisan('orbit:internal:build-runtime-images')
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:22:describe('orbit:internal:bootstrap-gateway-local', function (): void {
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:146:        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:183:        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:205:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:220:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:232:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:247:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:254:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:264:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:274:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:283:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:293:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:300:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:320:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:359:        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:414:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:460:        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/DetectPlatformCommandTest.php:12:describe('orbit:internal:detect-platform', function (): void {
apps/gateway/tests/Feature/Commands/Internal/DetectPlatformCommandTest.php:19:        $exitCode = Artisan::call('orbit:internal:detect-platform');
apps/gateway/tests/Feature/Commands/Internal/DetectPlatformCommandTest.php:46:        $exitCode = Artisan::call('orbit:internal:detect-platform', [
apps/gateway/tests/Feature/Commands/Internal/BuildRuntimeImagesCommandTest.php:20:    $this->artisan('orbit:internal:build-runtime-images')
apps/gateway/tests/Feature/Commands/Internal/BuildRuntimeImagesCommandTest.php:48:    $this->artisan('orbit:internal:build-runtime-images')
apps/gateway/tests/Feature/Commands/Internal/BuildRuntimeImagesCommandTest.php:65:    $this->artisan('orbit:internal:build-runtime-images', ['--force' => true])
apps/gateway/tests/Feature/Commands/Internal/BuildRuntimeImagesCommandTest.php:88:    $this->artisan('orbit:internal:build-runtime-images')
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:22:describe('orbit:internal:bootstrap-gateway-local', function (): void {
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:146:        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:183:        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:205:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:220:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:232:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:247:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:254:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:264:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:274:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:283:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:293:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:300:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:320:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:359:        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:414:        Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php:460:        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
apps/gateway/tests/Feature/Commands/Internal/BakeAgentNodeCommandTest.php:15:describe('orbit:internal:bake-agent-node', function (): void {
apps/gateway/tests/Feature/Commands/Internal/BakeAgentNodeCommandTest.php:40:        $this->artisan('orbit:internal:bake-agent-node', [
apps/gateway/tests/Feature/Commands/Nodes/AgentNodeProvisioningTest.php:148:        if (str_contains($command, 'orbit:internal:detect-platform')) {
apps/gateway/tests/Feature/Architecture/McpConfigurationTest.php:14:            'apps/gateway/artisan',
apps/gateway/tests/Feature/Architecture/McpConfigurationTest.php:23:        ->toContain('args = ["apps/gateway/artisan", "boost:mcp"]')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:6:    expect(repo_path('bin/orbit-gateway-artisan'))->toBeFile()
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:24:        ->and($composer['scripts']['test'][1])->toContain('bin/orbit-gateway-artisan config:clear')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:29:        ->and($composer['scripts']['test:slow'][1])->toContain('bin/orbit-gateway-artisan config:clear')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:31:        ->and($composer['scripts']['test:e2e'][1])->toContain('bin/orbit-gateway-artisan e2e:test')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:32:        ->and($composer['scripts']['test:e2e:docker'][1])->toContain('bin/orbit-gateway-artisan e2e:test')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:33:        ->and($composer['scripts']['test:e2e:docker:canary'][1])->toContain('bin/orbit-gateway-artisan e2e:test --canary')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:34:        ->and($composer['scripts']['test:e2e:incus'][1])->toContain('bin/orbit-gateway-artisan e2e:test')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:35:        ->and($composer['scripts']['test:e2e:provision'][1])->toContain('bin/orbit-gateway-artisan test')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:36:        ->and($composer['scripts']['e2e:preflight'])->toContain('bin/orbit-gateway-artisan e2e:preflight')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:37:        ->and($composer['scripts']['e2e:prepare-docker-topology'][1])->toContain('bin/orbit-gateway-artisan e2e:prepare-docker-topology')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:57:        ->toContain('${ORBIT_REPO}/apps/gateway/artisan')
apps/gateway/tests/Feature/Architecture/GatewayAppRelocationTest.php:93:    $gatewayArtisanPath = "{$repoRoot}/apps/gateway/artisan";
apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php:123:            ->toContain('[ ! -f "$TARGET_DIR/apps/gateway/artisan" ]')
apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php:379:            ->toContain('php /opt/orbit/apps/gateway/artisan migrate --force --no-interaction')
apps/gateway/tests/Feature/Runtime/OrbitRuntimeEntrypointTest.php:16:    file_put_contents("{$source}/apps/gateway/artisan", "<?php\n");
apps/gateway/tests/Feature/Runtime/OrbitRuntimeEntrypointTest.php:122:        ->toContain('${source_path}/apps/gateway/artisan')
apps/gateway/tests/Feature/Runtime/OrbitRuntimeEntrypointTest.php:135:    file_put_contents("{$source}/apps/gateway/artisan", "<?php\n");
apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php:25:            ->toContain("'orbit orbit:internal:bootstrap-gateway-local %s %s --identity-json=- --public-host=%s --tld=%s --metadata-json'")
apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php:26:            ->toContain("'orbit orbit:internal:detect-platform --update-local-node'")
apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php:27:            ->not->toContain('php artisan orbit:internal:bootstrap-gateway-local')
apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php:28:            ->not->toContain('php artisan orbit:internal:detect-platform')
apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php:29:            ->not->toContain("'cd %s && php artisan orbit:internal:bootstrap-gateway-local")
apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php:30:            ->not->toContain("'cd %s && php artisan orbit:internal:detect-platform");
apps/gateway/tests/Feature/CommandListVisibilityTest.php:17:        ->and($output)->not->toContain('orbit:internal:node-register')
apps/gateway/tests/Feature/Commands/Internal/BakeAgentNodeCommandTest.php:15:describe('orbit:internal:bake-agent-node', function (): void {
apps/gateway/tests/Feature/Commands/Internal/BakeAgentNodeCommandTest.php:40:        $this->artisan('orbit:internal:bake-agent-node', [
apps/gateway/tests/Feature/Tools/DnsToolTest.php:10:    expect($script)->toContain('orbit:internal:install-orbit-dns');
apps/gateway/tests/Feature/Commands/Nodes/NodeNewHostedRolesTest.php:176:        if (str_contains($command, 'orbit:internal:bootstrap-gateway-local')) {
apps/gateway/tests/Feature/Commands/Nodes/NodeNewHostedRolesTest.php:183:        if (str_contains($command, 'orbit:internal:detect-platform')) {
apps/gateway/tests/Feature/Commands/Vpn/VpnClientCommandTest.php:234:    expect($command)->toContain('orbit-runtime php apps/gateway/artisan vpn-client:list --json');
apps/gateway/tests/Feature/Commands/Vpn/VpnClientCommandTest.php:292:    expect($command)->toContain('php apps/gateway/artisan vpn-client:new');
apps/gateway/tests/Feature/Commands/Nodes/AgentNodeNewCommandTest.php:150:        if (str_contains($command, 'orbit:internal:bootstrap-gateway-local')) {
apps/gateway/tests/Feature/Commands/Nodes/AgentNodeNewCommandTest.php:157:        if (str_contains($command, 'orbit:internal:detect-platform')) {
apps/gateway/tests/Feature/Commands/Nodes/AgentNodeProvisioningTest.php:148:        if (str_contains($command, 'orbit:internal:detect-platform')) {
apps/gateway/tests/Feature/Commands/Schedule/ScheduleRunCommandTest.php:122:        'execution_value' => 'php apps/gateway/artisan orbit:cleanup',
apps/gateway/tests/Feature/Commands/Schedule/ScheduleRunCommandTest.php:127:        'php apps/gateway/artisan orbit:cleanup' => Process::result(output: "clean\n"),
apps/gateway/tests/Feature/Commands/Schedule/ScheduleRunCommandTest.php:142:    Process::assertRan('php apps/gateway/artisan orbit:cleanup');
apps/gateway/app/Services/OrbitUpdater.php:33:            ->run(['docker', 'exec', 'orbit-runtime', 'php', 'apps/gateway/artisan', 'migrate', '--force']);
apps/gateway/app/Services/OrbitUpdater.php:82:        return $this->runRemote($node, 'docker exec orbit-runtime php apps/gateway/artisan migrate --force', 60);
apps/gateway/app/Services/OrbitUpdater.php:90:            'running_migrations' => 'docker exec orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/app/Services/OrbitUpdater.php:106:        return 'git pull --ff-only && docker exec orbit-runtime composer --working-dir=apps/gateway install --no-interaction && docker exec orbit-runtime php apps/gateway/artisan migrate --force';
apps/gateway/tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php:46:    file_put_contents("{$source}/apps/gateway/artisan", "<?php\n");
apps/gateway/tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php:88:            ->toContain("argv={$source}/apps/gateway/artisan orbit-scheduler --sleep-seconds=7")
apps/gateway/tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php:154:        'execution_value' => 'php apps/gateway/artisan orbit:cleanup',
apps/gateway/tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php:160:        'php apps/gateway/artisan orbit:cleanup' => Process::result(output: "local\n"),
apps/gateway/tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php:175:    Process::assertRan('php apps/gateway/artisan orbit:cleanup');
apps/gateway/tests/Feature/Commands/Nodes/NodeNewHostedRolesTest.php:176:        if (str_contains($command, 'orbit:internal:bootstrap-gateway-local')) {
apps/gateway/tests/Feature/Commands/Nodes/NodeNewHostedRolesTest.php:183:        if (str_contains($command, 'orbit:internal:detect-platform')) {
apps/gateway/app/Services/RemoteShell/RemoteOrbitRuntimeExecutor.php:22:    private const string ARTISAN = 'php apps/gateway/artisan';
apps/gateway/tests/Feature/Commands/Schedule/ScheduleRunCommandTest.php:122:        'execution_value' => 'php apps/gateway/artisan orbit:cleanup',
apps/gateway/tests/Feature/Commands/Schedule/ScheduleRunCommandTest.php:127:        'php apps/gateway/artisan orbit:cleanup' => Process::result(output: "clean\n"),
apps/gateway/tests/Feature/Commands/Schedule/ScheduleRunCommandTest.php:142:    Process::assertRan('php apps/gateway/artisan orbit:cleanup');
apps/gateway/tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php:46:    file_put_contents("{$source}/apps/gateway/artisan", "<?php\n");
apps/gateway/tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php:88:            ->toContain("argv={$source}/apps/gateway/artisan orbit-scheduler --sleep-seconds=7")
apps/gateway/tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php:154:        'execution_value' => 'php apps/gateway/artisan orbit:cleanup',
apps/gateway/tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php:160:        'php apps/gateway/artisan orbit:cleanup' => Process::result(output: "local\n"),
apps/gateway/tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php:175:    Process::assertRan('php apps/gateway/artisan orbit:cleanup');
apps/gateway/tests/Feature/InstallOrbitLauncherTest.php:14:            ->not->toContain('ln -sf "$TARGET_DIR/apps/gateway/artisan" "$LINK_PATH"')
apps/gateway/tests/Feature/InstallOrbitLauncherTest.php:33:        expect($capture['target'])->toBe($capture['repo'].'/apps/gateway/artisan')
apps/gateway/tests/Feature/InstallOrbitLauncherTest.php:102:            ->toContain("dirname(__DIR__, 2).'/apps/gateway/artisan'")
apps/gateway/tests/Feature/InstallOrbitLauncherTest.php:173:    orbitLauncherWriteExecutable("{$repo}/apps/gateway/artisan", orbitLauncherCaptureScript());
apps/gateway/tests/Feature/Architecture/McpConfigurationTest.php:14:            'apps/gateway/artisan',
apps/gateway/tests/Feature/Architecture/McpConfigurationTest.php:23:        ->toContain('args = ["apps/gateway/artisan", "boost:mcp"]')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:6:    expect(repo_path('bin/orbit-gateway-artisan'))->toBeFile()
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:24:        ->and($composer['scripts']['test'][1])->toContain('bin/orbit-gateway-artisan config:clear')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:29:        ->and($composer['scripts']['test:slow'][1])->toContain('bin/orbit-gateway-artisan config:clear')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:31:        ->and($composer['scripts']['test:e2e'][1])->toContain('bin/orbit-gateway-artisan e2e:test')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:32:        ->and($composer['scripts']['test:e2e:docker'][1])->toContain('bin/orbit-gateway-artisan e2e:test')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:33:        ->and($composer['scripts']['test:e2e:docker:canary'][1])->toContain('bin/orbit-gateway-artisan e2e:test --canary')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:34:        ->and($composer['scripts']['test:e2e:incus'][1])->toContain('bin/orbit-gateway-artisan e2e:test')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:35:        ->and($composer['scripts']['test:e2e:provision'][1])->toContain('bin/orbit-gateway-artisan test')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:36:        ->and($composer['scripts']['e2e:preflight'])->toContain('bin/orbit-gateway-artisan e2e:preflight')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:37:        ->and($composer['scripts']['e2e:prepare-docker-topology'][1])->toContain('bin/orbit-gateway-artisan e2e:prepare-docker-topology')
apps/gateway/tests/Feature/Architecture/RootGatewayForwardingShimTest.php:57:        ->toContain('${ORBIT_REPO}/apps/gateway/artisan')
apps/gateway/tests/Feature/Architecture/GatewayAppRelocationTest.php:93:    $gatewayArtisanPath = "{$repoRoot}/apps/gateway/artisan";
apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php:123:            ->toContain('[ ! -f "$TARGET_DIR/apps/gateway/artisan" ]')
apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php:379:            ->toContain('php /opt/orbit/apps/gateway/artisan migrate --force --no-interaction')
apps/gateway/tests/Feature/Runtime/OrbitRuntimeEntrypointTest.php:16:    file_put_contents("{$source}/apps/gateway/artisan", "<?php\n");
apps/gateway/tests/Feature/Runtime/OrbitRuntimeEntrypointTest.php:122:        ->toContain('${source_path}/apps/gateway/artisan')
apps/gateway/tests/Feature/Runtime/OrbitRuntimeEntrypointTest.php:135:    file_put_contents("{$source}/apps/gateway/artisan", "<?php\n");
apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php:25:            ->toContain("'orbit orbit:internal:bootstrap-gateway-local %s %s --identity-json=- --public-host=%s --tld=%s --metadata-json'")
apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php:26:            ->toContain("'orbit orbit:internal:detect-platform --update-local-node'")
apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php:27:            ->not->toContain('php artisan orbit:internal:bootstrap-gateway-local')
apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php:28:            ->not->toContain('php artisan orbit:internal:detect-platform')
apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php:29:            ->not->toContain("'cd %s && php artisan orbit:internal:bootstrap-gateway-local")
apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php:30:            ->not->toContain("'cd %s && php artisan orbit:internal:detect-platform");
apps/gateway/tests/Feature/CommandListVisibilityTest.php:17:        ->and($output)->not->toContain('orbit:internal:node-register')
apps/gateway/tests/Feature/Tools/DnsToolTest.php:10:    expect($script)->toContain('orbit:internal:install-orbit-dns');
apps/gateway/tests/Unit/Console/Commands/VpnCommandSupportTest.php:19:    expect(str_starts_with($script, 'php apps/gateway/artisan vpn-client:list'))->toBeTrue();
apps/gateway/tests/Unit/Console/Commands/VpnCommandSupportTest.php:32:    expect($script)->toBe("php apps/gateway/artisan vpn-client:new 'laptop' --json");
apps/gateway/app/E2E/Support/E2EOperatorIdentity.php:22:            'cd '.escapeshellarg("/home/{$operatorUser}/orbit").' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
apps/gateway/app/E2E/Support/E2EGatewayApi.php:1683:        */tmp/orbit-*-http-router.php*|*/tmp/orbit-*-tls.php*|*orbit\ serve\ --host=*--port=80*|*php\ */apps/gateway/artisan\ serve\ --host=*--port=80*|*php\ -S\ *:80\ */apps/gateway/vendor/laravel/framework/src/Illuminate/Foundation/Console/../resources/server.php*)
apps/gateway/app/E2E/Support/E2ENodeProbe.php:11:        $install = $instance->exec('test -d /home/orbit/orbit && test -f /home/orbit/orbit/apps/gateway/artisan');
apps/gateway/app/E2E/Support/IncusTopologyProvider.php:716:            'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bootstrap-gateway-local gateway %s --public-host=%s --skip-runtime-install',
apps/gateway/app/E2E/Support/IncusTopologyProvider.php:726:                'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake-app-node app-dev-1 --role=app-dev --host=%s --wireguard-address=%s --tld=test --gateway-endpoint=%s --user=orbit --user=orbit',
apps/gateway/app/E2E/Support/IncusTopologyProvider.php:736:                'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake-ingress-node edge-1 --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
apps/gateway/app/E2E/Support/IncusTopologyProvider.php:746:                    'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake-ingress-node app-prod-1 --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
apps/gateway/app/E2E/Support/IncusTopologyProvider.php:760:                'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake-app-node app-prod-1 --role=app-prod --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit%s',
apps/gateway/app/E2E/Support/IncusTopologyProvider.php:770:                'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake-agent-node agent-1 --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit --tld=agent',
apps/gateway/app/E2E/Support/IncusTopologyProvider.php:799:            'cd /home/orbit/orbit && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
apps/gateway/app/E2E/Support/IncusTopologyProvider.php:837:            'cd /home/'.$config->operatorUser.'/orbit && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
apps/gateway/app/E2E/Support/IncusTopologyProvider.php:863:            'cd /home/orbit/orbit && php apps/gateway/artisan tinker --execute='.escapeshellarg(E2EPreparedTopologyRegistry::appdevDatabaseAndRedisPhp()),
apps/gateway/app/E2E/Support/IncusTopologyBuilder.php:687:            'cd /home/orbit/orbit && php apps/gateway/artisan tinker --execute='.escapeshellarg('require '.var_export($scriptPath, true).';'),
apps/gateway/app/E2E/Support/IncusTopologyBuilder.php:758:            'cd '.escapeshellarg('/home/'.$this->host->config->operatorUser.'/orbit').' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
apps/gateway/app/E2E/Support/IncusTopologyBuilder.php:909:                'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bootstrap-gateway-local gateway %s --public-host=%s --skip-runtime-install',
apps/gateway/app/E2E/Support/IncusTopologyBuilder.php:965:            'cd '.escapeshellarg('/home/'.$this->host->config->operatorUser.'/orbit').' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
apps/gateway/app/E2E/Support/IncusTopologyBuilder.php:974:            'cd /home/orbit/orbit && php apps/gateway/artisan tinker --execute='.escapeshellarg('echo app(\App\Services\Ca\OrbitCaService::class)->rootCert();'),
apps/gateway/app/E2E/Support/IncusTopologyBuilder.php:1004:            'cd '.escapeshellarg('/home/'.$this->host->config->operatorUser.'/orbit').' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:694:            'cd /home/orbit/orbit && orbit orbit:internal:bootstrap-gateway-local gateway %s --skip-runtime-install --skip-wireguard-install',
apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:742:                    'orbit orbit:internal:bake-app-node app-dev-1 --role=app-dev --host=%s%s --wireguard-address=%s --tld=test --gateway-endpoint=%s --user=orbit',
apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:757:                'cd /home/orbit/orbit && orbit orbit:internal:bake-ingress-node edge-1 --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:774:                    'orbit orbit:internal:bake-ingress-node app-prod-1 --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:790:                'orbit orbit:internal:bake-app-node app-prod-1 --role=app-prod --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit%s',
apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:805:                'cd /home/orbit/orbit && orbit orbit:internal:bake-agent-node agent-1 --host=%s%s --wireguard-address=%s --tld=agent --gateway-endpoint=%s --user=orbit',
apps/gateway/app/E2E/Support/DockerTopologyProvider.php:702:                'orbit orbit:internal:bake-app-node app-dev-1 --role=app-dev --host=%s%s --wireguard-address=%s --tld=test --gateway-endpoint=%s --user=orbit',
apps/gateway/app/E2E/Support/DockerTopologyProvider.php:713:                'orbit orbit:internal:bake-ingress-node edge-1 --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
apps/gateway/app/E2E/Support/DockerTopologyProvider.php:726:                    'orbit orbit:internal:bake-ingress-node app-prod-1 --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
apps/gateway/app/E2E/Support/DockerTopologyProvider.php:742:                'orbit orbit:internal:bake-app-node app-prod-1 --role=app-prod --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit%s',
apps/gateway/app/E2E/Support/DockerTopologyProvider.php:753:                'orbit orbit:internal:bake-agent-node agent-1 --host=%s%s --wireguard-address=%s --tld=agent --gateway-endpoint=%s --user=orbit',
apps/gateway/tests/Unit/Services/OrbitUpdaterTest.php:27:        && $process->command[4] === 'apps/gateway/artisan'
apps/gateway/tests/Unit/Services/OrbitUpdaterTest.php:64:        'docker exec orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/app/E2E/Support/E2ECurrentCheckout.php:12:    private const string GatewayArtisanRelativePath = 'apps/gateway/artisan';
apps/gateway/app/E2E/Support/E2ECurrentCheckout.php:561:            self::artisanCommand('orbit:internal:pin-node-host-keys --json', $dockerTopology, $remotePath, $dockerRuntimeContainer),
apps/gateway/app/E2E/Support/E2ECurrentCheckout.php:568:            return 'php apps/gateway/artisan '.$arguments;
apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php:167:            ->toContain('orbit:internal:build-runtime-images')
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:44:        'docker exec -i orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:69:            && str_contains($command, 'orbit-runtime php apps/gateway/artisan migrate --force');
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:73:        'docker exec orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:77:        'docker exec -i orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:81:        'docker exec --interactive orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:85:        'docker exec -t orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:89:        'docker exec -it orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:93:        'docker exec -i --user orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:97:        'docker exec -i --workdir /opt/orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:101:        'docker exec -e KEY=val orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:105:        'docker exec --user=orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:109:        'docker exec -u orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:113:        'docker exec --workdir=/opt/orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:117:        'docker exec -w /opt/orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:121:        'docker exec --env=KEY=val orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:125:        'docker exec -eKEY=val orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:129:        'docker exec --env-file /tmp/runtime.env orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:133:        'docker exec --privileged orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:137:        'docker exec --detach-keys ctrl-p,ctrl-q orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:141:        'docker exec --detach orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:145:        '  docker   exec   -i   orbit-runtime   php   apps/gateway/artisan   migrate   --force  ',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:159:        'docker exec -i --workdir /opt/orbit orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:205:        'docker exec -e CALLER_KEY=caller -e ORBIT_REQUEST_ID=caller orbit-runtime php apps/gateway/artisan migrate --force',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:284:            && str_contains($command, 'orbit-runtime php apps/gateway/artisan orbit:cleanup')
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:300:        'php apps/gateway/artisan migrate --force && php apps/gateway/artisan orbit:cleanup',
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:312:            && str_contains($command, 'php apps/gateway/artisan migrate --force && php apps/gateway/artisan orbit:cleanup');
apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php:330:            ->and($exception->script)->toBe('docker exec -i orbit-runtime php apps/gateway/artisan migrate --force')
apps/gateway/build/phpstan/cache/PHPStan/78/05/7805aa5a48f93eca863f9283d7c3dea07ce571656fd7a58e87ef554bcefa593a.php:36:            'code' => '\'orbit:internal:install-orbit-dns\'',
apps/gateway/build/phpstan/cache/PHPStan/68/ff/68ff31d9cd71f88227ce614185e75288950d115bf942c81da8630737be2ca69b.php:51:            'code' => '\'orbit:internal:build-runtime-images
apps/gateway/app/Tools/CaddyTool.php:58:                    'orbit-caddy: local Docker image %s is missing; run "bin/orbit-gateway-artisan orbit:internal:build-runtime-images" or "docker pull %s" before reconciling the orbit-caddy container.',
apps/gateway/app/Tools/DnsTool.php:30:        return "cd '{$orbitPath}' && php apps/gateway/artisan orbit:internal:install-orbit-dns";
apps/docs/content/domains/1_node/README.md:64:`bin/orbit-gateway-artisan` or direct `php apps/gateway/artisan` from a
apps/docs/content/domains/1_node/node-concepts.md:49:  (migrate, tinker, scheduler, queue, `orbit:internal:*` bake/build/install
apps/docs/content/domains/1_node/node-concepts.md:50:  commands) uses `bin/orbit-gateway-artisan` or direct
apps/docs/content/domains/1_node/node-concepts.md:51:  `php apps/gateway/artisan` from a controlled gateway shell; the public
apps/docs/content/domains/1_node/node-concepts.md:279:  bypass. Gateway maintenance uses `bin/orbit-gateway-artisan` or direct
apps/docs/content/domains/1_node/node-concepts.md:280:  `php apps/gateway/artisan` from a controlled gateway shell.
apps/gateway/build/phpstan/cache/PHPStan/d7/da/d7da7c7144741e955b9a1a9b07584670a8fdaaee195d5c6840f39c0686239011.php:36:            'code' => '\'orbit:internal:bake-agent-node
apps/docs/content/architecture.md:170:`bin/orbit-gateway-artisan` or direct `php apps/gateway/artisan` from a
apps/docs/content/execution-lanes.md:266:| `apps/gateway/app/Http/Controllers/Api/UpdateAllController.php:272,405` (`running_migrations`) | `RemoteOrbitRuntimeExecutor` | Resolves `RemoteShell` and starts the `docker exec orbit-runtime php apps/gateway/artisan migrate --force` stage for gateway migrations. |
apps/docs/content/execution-lanes.md:301:| `apps/gateway/app/Services/OrbitUpdater.php:80,82,111` (`runRemoteMigrations`) | `RemoteOrbitRuntimeExecutor` | Runs `docker exec orbit-runtime php apps/gateway/artisan migrate --force` for gateway migrations. |
apps/gateway/build/phpstan/cache/PHPStan/18/2f/182f95ccc6c83cbe749a3541a4b01ec031a7166f5c4d823b3c214cd58855b4c6.php:36:            'code' => '\'orbit:internal:node-register
apps/gateway/build/phpstan/cache/PHPStan/c9/07/c90700cd693d4f964b31f551f19e17f6317ee474d11be77030e54a6ccc2ca82a.php:36:            'code' => '\'orbit:internal:bootstrap-gateway-local
apps/gateway/build/phpstan/cache/PHPStan/ec/5f/ec5f74a0a15468cf4ea14055283248ae88bf5f2f502a4966cd9c3985bb44b17d.php:36:            'code' => '\'orbit:internal:detect-platform
apps/gateway/build/phpstan/cache/PHPStan/2a/79/2a7962cfcacce7ff6867ddd50583247b979e3dbd6fc4d7f0f2742f6c028ade26.php:36:            'code' => '\'orbit:internal:bake-app-node
apps/gateway/build/phpstan/cache/PHPStan/09/6e/096ef7f5d48ed8bc7bbe3a39f297881a9bf057654e5375e8aad4313d988e9458.php:36:            'code' => '\'orbit:internal:bake-ingress-node
apps/gateway/build/phpstan/cache/PHPStan/54/1c/541c8dddb499fc025d25392bf90498769fcef250b02a3df858a67a33521e3650.php:59:          'code' => '\'apps/gateway/artisan\'',
apps/gateway/build/phpstan/cache/PHPStan/96/ea/96ea75bf022cb747f5151d73154a91f69505e0998b7e83d9161269da7d3402e6.php:36:            'code' => '\'orbit:internal:pin-node-host-keys
apps/gateway/build/phpstan/cache/PHPStan/99/6b/996b3a5585650c4d835275cbaa759db1eaba81e537c1bde082102e3240fab9e7.php:134:          'code' => '\'php apps/gateway/artisan\'',
```

## Sweep 3 — `bin/orbit` / `/usr/local/bin/orbit` references inside E2E support and docker helpers

```
docker/orbit-runtime/Dockerfile:30:COPY docker/orbit-runtime/entrypoint.sh /usr/local/bin/orbit-runtime-entrypoint
docker/orbit-runtime/Dockerfile:32:RUN chmod 755 /usr/local/bin/orbit-runtime-entrypoint \
docker/orbit-runtime/Dockerfile:33:    && ln -s /usr/local/bin/orbit-runtime-entrypoint /usr/local/bin/orbit
docker/orbit-runtime/Dockerfile:38:ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/orbit-runtime-entrypoint"]
docker/e2e/topology/Dockerfile:58:        > /usr/local/bin/orbit-e2e-container \
docker/e2e/topology/Dockerfile:59:    && chmod 755 /usr/local/bin/orbit-e2e-container
docker/e2e/topology/Dockerfile:63:CMD ["/usr/local/bin/orbit-e2e-container"]
apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:117:                        escapeshellarg('CMD ["/usr/local/bin/orbit-e2e-container"]'),
apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:521:            sprintf('chmod 0755 %s', escapeshellarg("{$sourcePath}/bin/orbit")),
apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:522:            sprintf('ln -sfn %s %s', escapeshellarg("{$sourcePath}/bin/orbit"), escapeshellarg('/usr/local/bin/orbit')),
apps/gateway/app/E2E/Support/DockerTopologyProvider.php:575:            source_path="$(sed -n "s/^checkout='\(.*\)'$/\1/p" /usr/local/bin/orbit 2>/dev/null | head -n 1 || true)"
apps/gateway/app/E2E/Support/E2ECurrentCheckout.php:122:                '    exec "${checkout}/bin/orbit" "$@"',
apps/gateway/app/E2E/Support/E2ECurrentCheckout.php:231:            $instance->copyFileToInstance($tmpScript, '/usr/local/bin/orbit');
```
