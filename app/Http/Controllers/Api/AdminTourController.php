<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class AdminTourController extends Controller
{
    // Danh sách tour pending
    public function pending()
    {
        $tours = DB::table('tours')
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($tours);
    }

    // Duyệt tour
    public function approve($id)
    {
        $tour = DB::table('tours')->where('id', $id)->first();
        if (!$tour) return response()->json(['message' => 'Không tìm thấy tour'], 404);

        DB::table('tours')->where('id', $id)->update(['status' => 'approved', 'updated_at' => now()]);
        return response()->json(['message' => 'Duyệt tour thành công']);
    }

    // Từ chối tour
    public function reject($id)
    {
        $tour = DB::table('tours')->where('id', $id)->first();
        if (!$tour) return response()->json(['message' => 'Không tìm thấy tour'], 404);

        DB::table('tours')->where('id', $id)->update(['status' => 'rejected', 'updated_at' => now()]);
        return response()->json(['message' => 'Từ chối tour thành công']);
    }

    // Chi tiết tour
    public function show($id)
    {
        $tour = DB::table('tours')->where('id', $id)->first();
        if (!$tour) return response()->json(['message' => 'Không tìm thấy tour'], 404);

        $categories = DB::table('tour_categories')
            ->join('categories', 'tour_categories.category_id', '=', 'categories.id')
            ->where('tour_categories.tour_id', $id)
            ->pluck('categories.name');

        $schedules = DB::table('tour_schedules')->where('tour_id', $id)->get();

        return response()->json([
            'tour' => $tour,
            'categories' => $categories,
            'schedules' => $schedules
        ]);
    }
}
