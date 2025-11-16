<?php

namespace App\Http\Controllers;

use App\Services\UserActivityLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function logUserActivity($user, ?string $tourId, string $action): void
    {
        app(UserActivityLogger::class)->log($user, $tourId, $action);
    }
}
