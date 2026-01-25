<?php

use LaravelZero\Framework\Application;

// Ensure HOME is set (needed when running from nohup/background)
if (! isset($_SERVER['HOME']) && ($home = getenv('HOME'))) {
    $_SERVER['HOME'] = $home;
}
if (! isset($_SERVER['HOME'])) {
    $_SERVER['HOME'] = '/home/orbit';
}

return Application::configure(basePath: dirname(__DIR__))->create();
