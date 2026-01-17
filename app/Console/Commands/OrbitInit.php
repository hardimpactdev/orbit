<?php

namespace App\Console\Commands;

use App\Models\Environment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class OrbitInit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orbit:init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize local environment for web mode';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (Environment::where('is_local', true)->exists()) {
            $this->info('Local environment already exists. Skipping.');
            return Command::SUCCESS;
        }

        $configPath = rtrim(getenv('HOME'), '/') . '/.config/orbit/config.json';
        $tld = 'test';

        if (File::exists($configPath)) {
            $config = json_decode(File::get($configPath), true);
            if (isset($config['tld'])) {
                $tld = ltrim($config['tld'], '.');
            }
        }

        $user = get_current_user();
        if (!$user) {
            $user = exec('whoami');
        }

        Environment::create([
            'name' => 'Local',
            'host' => 'localhost',
            'user' => $user,
            'is_local' => true,
            'is_default' => true,
            'tld' => $tld,
            'status' => Environment::STATUS_ACTIVE,
        ]);

        $this->info("Local environment initialized with TLD: {$tld}");

        return Command::SUCCESS;
    }
}
