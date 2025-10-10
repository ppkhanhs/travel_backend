<?php
// app/Http/Controllers/Api/PartnerTourController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Tour;
use App\Models\Booking;

class PartnerTourController extends Controller
{
    // Tạo Tour (status = pending)
    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'partner') {
            return response()->json(['message' => 'Bạn không có quyền truy cập'], 403);
        }

        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'destination' => 'required|string|max:255',
            'duration'    => 'required|integer|min:1',
            'base_price'  => 'required|numeric|min:0',
            'policy'      => 'nullable|string',
            'tags'        => 'nullable|array',
            'tags.*'      => 'string',
            'media'       => 'nullable|array',
            'itinerary'   => 'nullable|array',
        ]);

        $partner = DB::table('partners')->where('user_id', $user->id)->first();
        if (!$partner || $partner->status !== 'approved') {
            return response()->json(['message' => 'Tài khoản đối tác chưa được duyệt'], 403);
        }

        $payload = array_merge($data, [
            'partner_id' => $partner->id,
            'status'     => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tourId = DB::table('tours')->insertGetId($payload, 'id');

        $tour = DB::table('tours')->where('id', $tourId)->first();
        return response()->json(['message' => 'Tạo tour thành công (chờ duyệt)', 'data' => $tour], 201);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'partner') {
            return response()->json(['message' => 'Bạn không có quyền'], 403);
        }

        // Lấy partner_id theo user
        $partner = DB::table('partners')->where('user_id', $user->id)->first();
        if (!$partner) {
            return response()->json(['message' => 'Không tìm thấy thông tin đối tác'], 404);
        }

        // Lấy danh sách tour của partner
        $tours = DB::table('tours')
            ->select('id', 'title', 'destination', 'duration', 'base_price', 'status', 'created_at', 'updated_at')
            ->where('partner_id', $partner->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($tours);
    }

    // Cập nhật Tour (đưa về pending để duyệt lại)
    public function update($id, Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'partner') {
            return response()->json(['message' => 'Bạn không có quyền truy cập'], 403);
        }

        $tour = DB::table('tours')->where('id', $id)->first();
        if (!$tour) return response()->json(['message' => 'Không tìm thấy tour'], 404);

        // Chỉ cho phép chủ sở hữu (partner) chỉnh sửa
        $partner = DB::table('partners')->where('user_id', $user->id)->first();
        if (!$partner || $tour->partner_id !== $partner->id) {
            return response()->json(['message' => 'Bạn không sở hữu tour này'], 403);
        }

        $data = $request->validate([
            'title'       => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'destination' => 'sometimes|required|string|max:255',
            'duration'    => 'sometimes|required|integer|min:1',
            'base_price'  => 'sometimes|required|numeric|min:0',
            'policy'      => 'sometimes|nullable|string',
            'tags'        => 'sometimes|array',
            'tags.*'      => 'string',
            'media'       => 'sometimes|array',
            'itinerary'   => 'sometimes|array',
        ]);

        $payload = $data;
        // Khi partner sửa, đưa về "pending" để admin duyệt lại
        $payload['status']     = 'pending';
        $payload['updated_at'] = now();

        DB::table('tours')->where('id', $id)->update($payload);

        $updated = DB::table('tours')->where('id', $id)->first();
        return response()->json(['message' => 'Cập nhật tour thành công (chờ duyệt lại)', 'data' => $updated]);
    }

    // Xóa Tour (chỉ khi không có booking)
    public function destroy($id, Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'partner') {
            return response()->json(['message' => 'Bạn không có quyền truy cập'], 403);
        }

        $tour = DB::table('tours')->where('id', $id)->first();
        if (!$tour) return response()->json(['message' => 'Không tìm thấy tour'], 404);

        $partner = DB::table('partners')->where('user_id', $user->id)->first();
        if (!$partner || $tour->partner_id !== $partner->id) {
            return response()->json(['message' => 'Bạn không sở hữu tour này'], 403);
        }

        // Không cho xóa nếu đã có booking nào gắn với tour qua tour_schedules
        $hasBooking = DB::table('bookings')
            ->join('tour_schedules', 'bookings.tour_schedule_id', '=', 'tour_schedules.id')
            ->where('tour_schedules.tour_id', $id)
            ->exists();

        if ($hasBooking) {
            return response()->json(['message' => 'Tour đã có đơn đặt, không thể xóa'], 409);
        }

        // Xóa liên quan (nếu có) rồi xóa tour
        DB::transaction(function () use ($id) {
            DB::table('tour_categories')->where('tour_id', $id)->delete();
            DB::table('tour_schedules')->where('tour_id', $id)->delete();
            DB::table('user_activity_logs')->where('tour_id', $id)->delete();
            DB::table('wishlists')->where('tour_id', $id)->delete();
            DB::table('tours')->where('id', $id)->delete();
        });

        return response()->json(['message' => 'Xóa tour thành công']);
    }
}
