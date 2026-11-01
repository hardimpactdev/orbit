"""Installed CLI SDK integration check; run after Composer dependencies are installed."""
import json
import os
import subprocess
import unittest

import swamp_proof as proof


class InstalledSdkTest(unittest.TestCase):
    def test_operator_helper_uses_installed_sdk_body_origin_and_response_contract(self):
        code = r'''
require "apps/cli/vendor/autoload.php";
require "tools/swamp-proof-request.php";
$input = json_decode(stream_get_contents(STDIN));
$client = new Saloon\Http\Faking\MockClient([Saloon\Http\Faking\MockResponse::make(['data'=>['operation'=>['id'=>73]]], 201)]);
$connector = new Orbit\Sdk\GatewayConnector('https://proof-gateway.orbit', '/tmp/proof-ca.pem');
$connector->withMockClient($client);
$response = swampProofRequest($connector, 'POST', '/api/v1/database-connections/proof/users', $input);
$request = $client->getLastPendingRequest();
if ($request->getUrl() !== 'https://proof-gateway.orbit/api/v1/database-connections/proof/users'
 || $request->body()->all()['password'] !== $input->password
 || $request->config()->get('verify') !== '/tmp/proof-ca.pem') { exit(2); }
echo $response->body();
'''
        secret = os.urandom(32).hex()
        reply = subprocess.run(['php', '-r', code], input=json.dumps({'password': secret}), text=True, capture_output=True, cwd=proof.ROOT, check=False)
        self.assertEqual(reply.returncode, 0, reply.stderr)
        self.assertEqual(json.loads(reply.stdout), {'data': {'operation': {'id': 73}}})
        self.assertNotIn(secret, reply.stdout + reply.stderr)


if __name__ == '__main__':
    unittest.main()
