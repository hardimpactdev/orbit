<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\TemplateAnalyzer\Analyzers\LaravelTemplateAnalyzer;
use App\Services\TemplateAnalyzer\EnvParser;
use App\Services\TemplateAnalyzer\TemplateAnalyzerService;
use Illuminate\Support\ServiceProvider;

class TemplateAnalyzerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register EnvParser as singleton
        $this->app->singleton(EnvParser::class);

        // Register the main service with all analyzers configured
        $this->app->singleton(TemplateAnalyzerService::class, function ($app): \App\Services\TemplateAnalyzer\TemplateAnalyzerService {
            $service = new TemplateAnalyzerService;

            // Register all available analyzers
            $service->registerAnalyzer($app->make(LaravelTemplateAnalyzer::class));

            // Future analyzers can be added here:
            // $service->registerAnalyzer($app->make(NodeTemplateAnalyzer::class));
            // $service->registerAnalyzer($app->make(RailsTemplateAnalyzer::class));

            return $service;
        });
    }
}
