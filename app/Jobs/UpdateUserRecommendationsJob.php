<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\RecommendationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateUserRecommendationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $userId,
        public int $limit = 40
    ) {
    }

    public function handle(RecommendationService $service): void
    {
        $user = User::query()->find($this->userId);
        if (!$user) {
            return;
        }

        $service->generateForUser($user, $this->limit);
    }
}
