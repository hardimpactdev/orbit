<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'name',
        'github_url',
    ];

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * Find a project by its GitHub URL.
     */
    public static function findByGithubUrl(string $url): ?self
    {
        return static::where('github_url', $url)->first();
    }

    /**
     * Get or create a project by GitHub URL.
     */
    public static function findOrCreateByGithubUrl(string $url, string $name): self
    {
        return static::firstOrCreate(
            ['github_url' => $url],
            ['name' => $name]
        );
    }
}
