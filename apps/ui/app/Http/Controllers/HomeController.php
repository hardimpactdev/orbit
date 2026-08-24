<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Response;
use Inertia\ResponseFactory;
use NckRtl\Waymaker\Get;

class HomeController extends Controller
{
    #[Get(uri: '/')]
    public function show(): ResponseFactory|Response
    {
        return inertia('Home');
    }
}
