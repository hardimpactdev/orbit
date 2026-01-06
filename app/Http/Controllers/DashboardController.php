<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\LaunchpadService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        protected LaunchpadService $launchpad,
    ) {}

    public function index(): Response
    {
        $servers = Server::all();
        $defaultServer = Server::getDefault();

        return Inertia::render('Dashboard', [
            'servers' => $servers,
            'defaultServer' => $defaultServer,
        ]);
    }
}
