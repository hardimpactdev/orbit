<?php

use App\Services\Updater\GitHubReleasesStrategy;

return [

    /*
    |--------------------------------------------------------------------------
    | Self-updater Strategy
    |--------------------------------------------------------------------------
    |
    | The strategy used to check for and download updates. We use a custom
    | GitHubReleasesStrategy that fetches releases directly from GitHub API
    | rather than Packagist, and downloads PHARs from release assets.
    |
    */

    'strategy' => GitHubReleasesStrategy::class,

];
