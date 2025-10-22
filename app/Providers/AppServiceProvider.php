<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        if (app()->environment('production')) {
            $flagPath = storage_path('framework/cache/sanctum_fix');

            if (! file_exists($flagPath)) {
                try {
                    \Artisan::call('config:clear');
                    \Artisan::call('cache:clear');
                    \Artisan::call('route:clear');
                    \Artisan::call('config:cache');

                    if (! is_dir(dirname($flagPath))) {
                        mkdir(dirname($flagPath), 0755, true);
                    }

                    file_put_contents($flagPath, now()->toIso8601String());
                } catch (\Throwable $e) {
                    \Log::error('Failed to refresh caches for Sanctum fix', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
