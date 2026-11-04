<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\RunAutomaticGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Models\GatewayRelease;

// Runs one production automatic tick, with a disposable GitHub App whose API this fixture serves: it resolves the
// target as the newest green commit and admits the run; the request then follows the bound Operation.
chdir(dirname(__DIR__, 3).'/apps/gateway');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'proof', 'proof', 'hardimpactdev', 'organization', 'https://github.com/apps/proof', $pem));
// Automatic releases on and not paused, as an operator leaves them after a first release by hand.
app(GatewayReleaseAutomation::class)->enable();
app(GatewayReleaseAutomation::class)->resume();
$tick = app(RunAutomaticGatewayReleaseAction::class)->execute();
if ($tick['result'] !== 'started' || $tick['sha'] !== $argv[1]) {
    fwrite(STDERR, json_encode($tick, JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
$record = GatewayRelease::query()->findOrFail($tick['record']);
echo json_encode(['bindings' => ['operation' => $record->phases['run']['operation_id'], 'record' => $record->id]], JSON_THROW_ON_ERROR)."\n";
