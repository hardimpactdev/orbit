<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    #[\Override]
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'app' => [
                'name' => config('app.name'),
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale'),
            ],
            'location' => [
                'current' => $request->url(),
                'previous' => $request->headers->get('referer'),
            ],
        ]);
    }
}
