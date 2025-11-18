<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$result = Illuminate\Support\Facades\DB::select("SELECT pg_get_constraintdef(oid) as definition FROM pg_constraint WHERE conname = 'user_activity_logs_action_check'");
print_r($result);
