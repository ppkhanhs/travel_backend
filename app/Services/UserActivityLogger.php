<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\Log;

class UserActivityLogger
{
    /**
     * @param  User|string|null  $user
     */
    public function log(User|string|null $user, ?string $tourId, string $action): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        if (!$userId || !$tourId) {
            return;
        }

        try {
            UserActivityLog::create([
                'user_id' => $userId,
                'tour_id' => $tourId,
                'action' => $action,
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to log user activity', [
                'user_id' => $userId,
                'tour_id' => $tourId,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

