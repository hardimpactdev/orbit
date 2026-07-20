<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AppAnalyticsBinding;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppAnalyticsBinding>
 */
class AppAnalyticsBindingFactory extends Factory
{
    protected $model = AppAnalyticsBinding::class;

    public function definition(): array
    {
        $slug = Str::slug(fake()->unique()->domainWord());

        return [
            'app_id' => Project::factory(),
            'enabled' => true,
            'public_hosts' => ["analytics.{$slug}.example.com"],
        ];
    }
}
