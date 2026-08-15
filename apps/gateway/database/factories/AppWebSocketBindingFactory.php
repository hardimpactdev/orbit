<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AppWebSocketBinding;
use App\Models\Instance;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppWebSocketBinding>
 */
class AppWebSocketBindingFactory extends Factory
{
    protected $model = AppWebSocketBinding::class;

    public function definition(): array
    {
        $slug = Str::slug(fake()->unique()->domainWord());

        return [
            'instance_id' => Instance::factory(),
            'enabled' => true,
            'reverb_app_id' => $slug,
            'reverb_app_key' => Str::random(32),
            'reverb_app_secret' => Str::random(48),
            'allowed_origins' => ["https://{$slug}.example.com"],
            'public_hosts' => ["ws.{$slug}.example.com"],
        ];
    }
}
