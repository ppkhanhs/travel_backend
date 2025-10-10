<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PartnerTourController extends Controller
{
    // Trả về danh sách tour thuộc đối tác đã được duyệt
    public function index()
    {
        $partner = $this->getAuthenticatedPartner();

        if (!$partner) {
            return response()->json(['message' => 'Tài khoản đối tác của bạn chưa được phê duyệt hoặc không tồn tại.'], 403);
        }

        $tours = DB::table('tours')
            ->where('partner_id', $partner->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($tours);
    }

    // Xem chi tiết một tour thuộc đối tác
    public function show($id)
    {
        $partner = $this->getAuthenticatedPartner();

        if (!$partner) {
            return response()->json(['message' => 'Tài khoản đối tác của bạn chưa được phê duyệt hoặc không tồn tại.'], 403);
        }

        $tour = DB::table('tours')
            ->where('id', $id)
            ->where('partner_id', $partner->id)
            ->first();

        if (!$tour) {
            return response()->json(['message' => 'Không tìm thấy tour thuộc quyền của bạn.'], 404);
        }

        return response()->json($tour);
    }

    // Tạo mới tour kèm lịch khởi hành đầu tiên
    public function store(Request $request)
    {
        $partner = $this->getAuthenticatedPartner();

        if (!$partner) {
            return response()->json(['message' => 'Tài khoản đối tác của bạn chưa được phê duyệt hoặc không tồn tại.'], 403);
        }

        $request->validate([
            'title' => 'required|string',
            'description' => 'nullable|string',
            'destination' => 'nullable|string',
            'duration' => 'nullable|integer|min:1',
            'base_price' => 'required|numeric|min:0',
            'policy' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'media' => 'nullable|array',
            'itinerary' => 'nullable|array',
            'schedule.start_date' => 'required|date',
            'schedule.end_date' => 'required|date|after_or_equal:schedule.start_date',
            'schedule.seats_total' => 'required|integer|min:1',
            'schedule.seats_available' => 'required|integer|min:0|lte:schedule.seats_total',
            'schedule.season_price' => 'nullable|numeric|min:0',
        ]);

        $now = now();
        $tourId = Str::uuid()->toString();

        DB::table('tours')->insert([
            'id' => $tourId,
            'partner_id' => $partner->id,
            'title' => $request->title,
            'description' => $request->description,
            'destination' => $request->destination,
            'duration' => $request->duration,
            'base_price' => $request->base_price,
            'policy' => $request->policy,
            'tags' => $this->toPostgresArray($request->tags),
            'media' => $this->encodeJson($request->media),
            'itinerary' => $this->encodeJson($request->itinerary),
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $schedule = $request->input('schedule');

        // Lưu lịch khởi hành mặc định gắn với tour mới
        DB::table('tour_schedules')->insert([
            'id' => Str::uuid()->toString(),
            'tour_id' => $tourId,
            'start_date' => $schedule['start_date'],
            'end_date' => $schedule['end_date'],
            'seats_total' => $schedule['seats_total'],
            'seats_available' => $schedule['seats_available'],
            'season_price' => $schedule['season_price'] ?? null,
        ]);

        return response()->json(['message' => 'Tạo tour thành công.', 'id' => $tourId], 201);
    }

    // Cập nhật tour và có thể đồng bộ lại lịch khởi hành
    public function update(Request $request, $id)
    {
        $partner = $this->getAuthenticatedPartner();

        if (!$partner) {
            return response()->json(['message' => 'Tài khoản đối tác của bạn chưa được phê duyệt hoặc không tồn tại.'], 403);
        }

        $tour = DB::table('tours')
            ->where('id', $id)
            ->where('partner_id', $partner->id)
            ->first();

        if (!$tour) {
            return response()->json(['message' => 'Không tìm thấy tour thuộc quyền của bạn.'], 404);
        }

        $request->validate([
            'title' => 'sometimes|required|string',
            'description' => 'sometimes|nullable|string',
            'destination' => 'sometimes|nullable|string',
            'duration' => 'sometimes|nullable|integer|min:1',
            'base_price' => 'sometimes|required|numeric|min:0',
            'policy' => 'sometimes|nullable|string',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'string',
            'media' => 'sometimes|nullable|array',
            'itinerary' => 'sometimes|nullable|array',
            'status' => 'sometimes|required|in:pending,approved,rejected',
            'schedule.id' => 'sometimes|nullable|uuid|exists:tour_schedules,id',
            'schedule.start_date' => 'sometimes|required_with:schedule|date',
            'schedule.end_date' => 'sometimes|required_with:schedule|date|after_or_equal:schedule.start_date',
            'schedule.seats_total' => 'sometimes|required_with:schedule|integer|min:1',
            'schedule.seats_available' => 'sometimes|required_with:schedule|integer|min:0|lte:schedule.seats_total',
            'schedule.season_price' => 'sometimes|nullable|numeric|min:0',
        ]);

        $tourData = $request->only([
            'title',
            'description',
            'destination',
            'duration',
            'base_price',
            'policy',
            'status',
        ]);

        if ($request->has('tags')) {
            $tourData['tags'] = $this->toPostgresArray($request->tags ?? []);
        }

        if ($request->has('media')) {
            $tourData['media'] = $this->encodeJson($request->media);
        }

        if ($request->has('itinerary')) {
            $tourData['itinerary'] = $this->encodeJson($request->itinerary);
        }

        if (!empty($tourData)) {
            $tourData['updated_at'] = now();

            DB::table('tours')
                ->where('id', $id)
                ->update($tourData);
        }

        if ($request->has('schedule')) {
            $schedule = $request->input('schedule');
            $scheduleData = [
                'start_date' => $schedule['start_date'] ?? null,
                'end_date' => $schedule['end_date'] ?? null,
                'seats_total' => $schedule['seats_total'] ?? null,
                'seats_available' => $schedule['seats_available'] ?? null,
                'season_price' => $schedule['season_price'] ?? null,
            ];

            $scheduleData = array_filter(
                $scheduleData,
                static fn ($value) => !is_null($value)
            );

            if (!empty($schedule['id'])) {
                DB::table('tour_schedules')
                    ->where('id', $schedule['id'])
                    ->where('tour_id', $id)
                    ->update($scheduleData);
            } elseif (!empty($scheduleData)) {
                // Chưa có lịch => tạo mới
                DB::table('tour_schedules')->insert(array_merge([
                    'id' => Str::uuid()->toString(),
                    'tour_id' => $id,
                ], $scheduleData));
            }
        }

        return response()->json(['message' => 'Cập nhật tour thành công.']);
    }

    // Xóa tour và toàn bộ lịch khởi hành liên quan
    public function destroy($id)
    {
        $partner = $this->getAuthenticatedPartner();

        if (!$partner) {
            return response()->json(['message' => 'Tài khoản đối tác của bạn chưa được phê duyệt hoặc không tồn tại.'], 403);
        }

        $tour = DB::table('tours')
            ->where('id', $id)
            ->where('partner_id', $partner->id)
            ->first();

        if (!$tour) {
            return response()->json(['message' => 'Không tìm thấy tour thuộc quyền của bạn.'], 404);
        }

        DB::table('tour_schedules')->where('tour_id', $id)->delete();
        DB::table('tours')->where('id', $id)->delete();

        return response()->json(['message' => 'Xóa tour thành công.']);
    }

    // Lấy bản ghi đối tác tương ứng user hiện tại và đảm bảo đã được duyệt
    private function getAuthenticatedPartner(): ?object
    {
        $userId = Auth::id();

        if (!$userId) {
            return null;
        }

        $partner = DB::table('partners')
            ->where('user_id', $userId)
            ->first();

        if (!$partner || $partner->status !== 'approved') {
            return null;
        }

        return $partner;
    }

    // Chuyển mảng PHP sang định dạng text[] của Postgres
    private function toPostgresArray(?array $items): ?string
    {
        if (is_null($items)) {
            return null;
        }

        if (empty($items)) {
            return '{}';
        }

        $escaped = array_map(function ($item) {
            $value = (string) $item;
            $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
            return '"' . $value . '"';
        }, $items);

        return '{' . implode(',', $escaped) . '}';
    }

    // Mã hóa JSON cho các cột jsonb
    private function encodeJson($value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
