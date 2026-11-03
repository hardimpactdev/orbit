<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\AdmitSwampGatewayReleaseAction;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;

// Admits the target commit through the production admission action, with a disposable GitHub App whose API this
// fixture serves; the request then follows the bound Operation to its native terminal.
chdir(dirname(__DIR__, 3).'/apps/gateway');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'proof', 'proof', 'hardimpactdev', 'organization', 'https://github.com/apps/proof', $pem));
$record = app(AdmitSwampGatewayReleaseAction::class)->execute($argv[1]);
echo json_encode(['bindings' => ['operation' => $record->phases['run']['operation_id'], 'record' => $record->id]], JSON_THROW_ON_ERROR)."\n";
