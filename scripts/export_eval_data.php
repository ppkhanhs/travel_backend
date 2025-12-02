<?php

/**
 * Export evaluation data for recommendation metrics.
 *
 * Usage:
 *   php scripts/export_eval_data.php
 * Outputs:
 *   recs.json         -> top 10 recommendations per user (tour_id, score)
 *   groundtruth.csv   -> user_id,tour_id from confirmed/completed bookings
 */

use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Export recommendations
$recsSql = <<<SQL
WITH rec AS (
    SELECT
        ur.user_id,
        (r->>'tour_id') AS tour_id,
        (r->>'score')::float AS score,
        ROW_NUMBER() OVER (
            PARTITION BY ur.user_id
            ORDER BY (r->>'score')::float DESC
        ) AS rn
    FROM user_recommendations ur
    CROSS JOIN LATERAL jsonb_array_elements(ur.recommendations::jsonb) AS r
)
SELECT json_build_object(
    'user_id', user_id,
    'items', json_agg(
        json_build_object(
            'tour_id', tour_id,
            'score', score
        )
        ORDER BY score DESC
    )
) AS payload
FROM rec
WHERE rn <= 10
GROUP BY user_id
SQL;

$rows = DB::select($recsSql);
$payload = array_map(static function ($row) {
    $val = $row->payload ?? null;
    if (is_string($val)) {
        $decoded = json_decode($val, true);
        return $decoded ?? $val;
    }
    return $val;
}, $rows);

file_put_contents(__DIR__ . '/../recs.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Exported recs.json (" . count($payload) . " users)\n";

// Export ground truth from confirmed/completed bookings
$gtSql = <<<SQL
SELECT DISTINCT ua.user_id, ua.tour_id
FROM user_activity_logs ua
WHERE ua.action IN ('book','booking_created','wishlist','tour_view')
  AND ua.tour_id IS NOT NULL
UNION
SELECT DISTINCT b.user_id, ts.tour_id
FROM bookings b
JOIN tour_schedules ts ON ts.id = b.tour_schedule_id
WHERE b.status IN ('pending','confirmed','completed')
SQL;

$gtRows = DB::select($gtSql);
$gtPath = __DIR__ . '/../groundtruth.csv';
$fp = fopen($gtPath, 'w');
fputcsv($fp, ['user_id', 'tour_id']);
foreach ($gtRows as $r) {
    fputcsv($fp, [$r->user_id, $r->tour_id]);
}
fclose($fp);
echo "Exported groundtruth.csv (" . count($gtRows) . " rows)\n";
