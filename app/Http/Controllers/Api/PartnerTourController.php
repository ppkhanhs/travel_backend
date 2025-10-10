<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PartnerTourController extends Controller
{
    // Lấy danh sách các tour của đối tác
    public function index()
    {
        $partnerId = Auth::id(); // Lấy ID partner đang đăng nhập

        $tours = DB::table('tours')
            ->where('partner_id', $partnerId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($tours);
    }

    // Xem chi tiết một tour
    public function show($id)
    {
        $partnerId = Auth::id();

        $tour = DB::table('tours')
            ->where('id', $id)
            ->where('partner_id', $partnerId)
            ->first();

        if (!$tour) {
            return response()->json(['message' => 'Tour không tồn tại hoặc không thuộc quyền của bạn'], 404);
        }

        return response()->json($tour);
    }

    // Thêm tour mới
    public function store(Request $request)
    {
        $partnerId = Auth::id();

        $request->validate([
            'title' => 'required|string',
            'description' => 'required|string',
            'destination' => 'required|string',
            'price' => 'required|numeric',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'quota' => 'required|integer|min:1'
        ]);

        $id = DB::table('tours')->insertGetId([
            'partner_id' => $partnerId,
            'title' => $request->title,
            'description' => $request->description,
            'destination' => $request->destination,
            'price' => $request->price,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'quota' => $request->quota,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Tạo tour thành công', 'id' => $id], 201);
    }

    // Cập nhật tour
    public function update(Request $request, $id)
    {
        $partnerId = Auth::id();

        $tour = DB::table('tours')
            ->where('id', $id)
            ->where('partner_id', $partnerId)
            ->first();

        if (!$tour) {
            return response()->json(['message' => 'Tour không tồn tại hoặc không thuộc quyền của bạn'], 404);
        }

        $data = $request->only(['title', 'description', 'destination', 'price', 'start_date', 'end_date', 'quota']);
        $data['updated_at'] = now();

        DB::table('tours')->where('id', $id)->update($data);

        return response()->json(['message' => 'Cập nhật tour thành công']);
    }

    // Xoá tour
    public function destroy($id)
    {
        $partnerId = Auth::id();

        $tour = DB::table('tours')
            ->where('id', $id)
            ->where('partner_id', $partnerId)
            ->first();

        if (!$tour) {
            return response()->json(['message' => 'Tour không tồn tại hoặc không thuộc quyền của bạn'], 404);
        }

        DB::table('tours')->where('id', $id)->delete();

        return response()->json(['message' => 'Xoá tour thành công']);
    }
}
