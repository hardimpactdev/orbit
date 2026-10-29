<?php

declare(strict_types=1);

use App\Domain\Tools\ToolInventoryReceipt;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;

it('reads a nonempty Homebrew version list during managed Tool verification', function (ToolManagerName $manager): void {
    $inventory = new ToolInventoryReceipt()->parse($manager, ['packages' => [
        ['name' => 'jq', 'versions' => ['1.8.2'], 'unverifiable' => false],
    ]]);

    expect($inventory->versionFor('jq'))->toBe('1.8.2');
})->with([ToolManagerName::Brew, ToolManagerName::BrewCask]);

it('refuses a Homebrew version list containing a nonstring observation', function (ToolManagerName $manager): void {
    expect(fn () => new ToolInventoryReceipt()->parse($manager, ['packages' => [
        ['name' => 'jq', 'versions' => ['1.8.2', 123], 'unverifiable' => false],
    ]]))->toThrow(ToolManagerException::class, 'invalid Homebrew observation');
})->with([ToolManagerName::Brew, ToolManagerName::BrewCask]);
