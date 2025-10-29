<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecommendationResource;
use App\Models\Tour;
use App\Services\RecommendationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function index(Request $request, RecommendationService $service): AnonymousResourceCollection
    {
        $limit = (int) $request->integer('limit', 10);
        $limit = max(1, min(50, $limit));

        $user = $request->user();
        $recommendations = $service->getRecommendations($user, $limit);

        $generatedAt = optional($user->recommendation)->generated_at;

        return RecommendationResource::collection($recommendations)
            ->additional([
                'meta' => [
                    'generated_at' => optional($generatedAt)->toIso8601String(),
                    'count' => $recommendations->count(),
                ],
            ]);
    }

    public function similar(Tour $tour, Request $request, RecommendationService $service): AnonymousResourceCollection
    {
        $limit = (int) $request->integer('limit', 8);
        $limit = max(1, min(50, $limit));

        $recommendations = $service->similarTours($tour, $limit);

        return RecommendationResource::collection($recommendations)
            ->additional([
                'meta' => [
                    'base_tour_id' => (string) $tour->id,
                    'count' => $recommendations->count(),
                ],
            ]);
    }
}

