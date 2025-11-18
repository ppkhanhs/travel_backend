<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE user_activity_logs DROP CONSTRAINT IF EXISTS user_activity_logs_action_check");

        DB::statement("ALTER TABLE user_activity_logs ADD CONSTRAINT user_activity_logs_action_check CHECK ((action = ANY (ARRAY['view'::text, 'search'::text, 'click'::text, 'wishlist'::text, 'book'::text, 'wishlist_add'::text, 'cart_add'::text, 'booking_created'::text, 'booking_cancelled'::text, 'review_submitted'::text, 'tour_view'::text])))") ;
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE user_activity_logs DROP CONSTRAINT IF EXISTS user_activity_logs_action_check");

        DB::statement("ALTER TABLE user_activity_logs ADD CONSTRAINT user_activity_logs_action_check CHECK ((action = ANY (ARRAY['view'::text, 'search'::text, 'click'::text, 'wishlist'::text, 'book'::text])))");
    }
};
