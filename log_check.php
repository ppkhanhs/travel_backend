<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$count = App\Models\UserActivityLog::count();
$latest = App\Models\UserActivityLog::orderByDesc('created_at')->first();
echo "count=$count\n";
if ($latest) {
    echo json_encode($latest->toArray());
}
