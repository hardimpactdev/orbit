<?php

declare(strict_types=1);

use App\Actions\Instances\InterruptLegacyInstanceDeploymentsAction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(InterruptLegacyInstanceDeploymentsAction::class)->execute();
    }

    public function down(): void
    {
        // A retired execution cannot safely resume under the previous executor.
    }
};
