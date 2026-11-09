<?php

declare(strict_types=1);

use App\Domain\Proof\ProofSelection;
use App\Domain\Proof\ProofVerifyIntent;
use App\Http\Requests\Proof\StartProofVerifyRequest;
use Illuminate\Support\Facades\Validator;

it('validates nested proof command names without admitting paths or empty parts', function (string $command, bool $valid): void {
    expect(Validator::make(['command' => $command], (new StartProofVerifyRequest)->rules())->passes())->toBe($valid);
})->with([
    'ordinary command' => ['firewall:allow', true],
    'nested command' => ['node:role:add', true],
    'more nested parts' => ['family:part:sub-command:action', true],
    'missing separator' => ['doctor', false],
    'empty middle part' => ['node::add', false],
    'empty final part' => ['node:role:', false],
    'upper case nested part' => ['node:Role:add', false],
    'nested path' => ['node:role:../add', false],
    'trailing whitespace' => ['node:role:add ', false],
]);

it('uses the CI cases-file lookup for nested command proof', function (): void {
    expect(ProofVerifyIntent::casesFile('node:role:add'))->toBe('swamp/proof/node/role-add.cases.yaml')
        ->and(ProofVerifyIntent::casesFile('node:role:add'))->toBe(ProofSelection::fileOf('node:role:add'));
});
