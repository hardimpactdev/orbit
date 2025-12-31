<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\LaunchpadService;

class DashboardController extends Controller
{
    public function __construct(
        protected LaunchpadService $launchpad,
    ) {}

    public function index()
    {
        $servers = Server::all();
        $defaultServer = Server::getDefault();

        return view('dashboard', compact('servers', 'defaultServer'));
    }
}
