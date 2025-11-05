<?php

namespace App\Services;

use App\Models\RecentTourView;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecentViewService
{
    private int $maxEntries;

    public function __construct(int $maxEntries = 20)
    {
        $this->maxEntries = max(1, $maxEntries);
    }

    public function recordView(?User $user, Tour $tour): void
    {
        if (!$user) {
            return;
        }

        DB::transaction(function () use ($user, $tour) {
            /** @var RecentTourView|null $view */
            $view = RecentTourView::query()
                ->where('user_id', $user->id)
                ->where('tour_id', $tour->id)
                ->lockForUpdate()
                ->first();

            if ($view) {
                $view->view_count = ($view->view_count ?? 0) + 1;
                $view->viewed_at = now();
                $view->save();
            } else {
                RecentTourView::query()->create([
                    'user_id' => $user->id,
                    'tour_id' => $tour->id,
                    'viewed_at' => now(),
                    'view_count' => 1,
                ]);
            }

            $this->trimExcess($user->id);
        });
    }

    public function getRecentViews(User $user, int $limit = 10): Collection
    {
        $limit = max(1, min($limit, $this->maxEntries));

        return RecentTourView::query()
            ->where('user_id', $user->id)
            ->whereHas('tour', fn ($q) => $q->where('status', 'approved'))
            ->with([
                'tour' => function ($query) {
                    $query->with([
                        'partner.user',
                        'categories',
                        'schedules' => function ($q) {
                            $q->orderBy('start_date');
                        },
                        'packages' => function ($q) {
                            $q->where('is_active', true)->orderBy('adult_price');
                        },
                        'cancellationPolicies' => function ($q) {
                            $q->orderByDesc('days_before');
                        },
                    ]);
                },
            ])
            ->orderByDesc('viewed_at')
            ->limit($limit)
            ->get();
    }

    private function trimExcess(string $userId): void
    {
        $excessIds = RecentTourView::query()
            ->where('user_id', $userId)
            ->orderByDesc('viewed_at')
            ->orderByDesc('id')
            ->skip($this->maxEntries)
            ->pluck('id');

        if ($excessIds->isNotEmpty()) {
            RecentTourView::query()->whereIn('id', $excessIds)->delete();
        }
    }
}
