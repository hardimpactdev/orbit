<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use Orbit\Extensions\Conn\Data\CreateConnProfileData;
use Orbit\Extensions\Conn\Models\ConnProfile;

final readonly class CreateConnProfileAction
{
    public function execute(CreateConnProfileData $data): ConnProfile
    {
        return ConnProfile::query()->create([
            'name' => $data->name,
            'settings' => ['workspaces' => []],
            'settings_version' => 1,
        ]);
    }
}
