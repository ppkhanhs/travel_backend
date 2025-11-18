<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::table('user_activity_logs')->insert([
    'id' => (string) Illuminate\Support\Str::uuid(),
    'user_id' => '99999999-9999-9999-9999-999999999999',
    'tour_id' => '99999999-9999-9999-9999-999999999999',
    'action' => 'test_insert',
    'created_at' => now(),
]);
echo 'inserted';
