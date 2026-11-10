<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\ReadyContainerDefault;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (ReadyContainerDefault::query()->with('node.roles')->where('auto_deploy', false)->get() as $instance) {
            if (AutomaticDeployPolicyWriter::Swamp->developmentEligibilityRefusal($instance, requireEnabled: false) === null) {
                $instance->update(['auto_deploy' => true]);
            }
        }
    }

    public function down(): void
    {
        // Expand-only: a rollback must not turn off an operator's deployment setting.
    }
};
