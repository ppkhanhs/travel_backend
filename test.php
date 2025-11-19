<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$booking = App\Models\Booking::with(['promotions'])->first();
var_dump($booking->total_price);
var_dump($booking->promotions->pluck('pivot.discount_amount'));
