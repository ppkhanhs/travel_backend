<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->make(App\Services\UserActivityLogger::class)->log('00000000-0000-0000-0000-000000000000','00000000-0000-0000-0000-000000000000','diagnostic');
echo "done";
