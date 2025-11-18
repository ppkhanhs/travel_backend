<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$actions = ['view','search','click','wishlist','book','wishlist_add','cart_add','booking_created','booking_cancelled','review_submitted','tour_view'];
$sql = "CHECK ((action = ANY (ARRAY['".implode("'::text, '", $actions)."'::text])))";
echo $sql;
