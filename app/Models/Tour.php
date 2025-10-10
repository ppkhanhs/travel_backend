<?php
// app/Models/Tour.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tour extends Model
{
    protected $table = 'tours';

    protected $fillable = [
        'partner_id','title','description','destination','duration',
        'base_price','policy','tags','media','itinerary','status'
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'tags'       => 'array',   // Postgres array
        'media'      => 'array',   // jsonb
        'itinerary'  => 'array',   // jsonb
    ];

    public $timestamps = false; // bảng của bạn dùng trigger default now(); nếu muốn dùng Laravel, bật lên và map created_at/updated_at
}
