<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RecentViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecentTourController extends Controller
{
    public function __construct(private RecentViewService $recentViews)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $request->integer('limit', 10);

        if (!$user) {
            abort(401);
        }

        $views = $this->recentViews->getRecentViews($user, $limit);

        $data = $views->map(function ($view) {
            $tour = $view->tour;
            if (!$tour) {
                return null;
            }

            return [
                'tour' => $tour->toArray(),
                'viewed_at' => optional($view->viewed_at)->toIso8601String(),
                'view_count' => $view->view_count,
            ];
        })->filter()->values();

        return response()->json([
            'data' => $data,
        ]);
    }
}
