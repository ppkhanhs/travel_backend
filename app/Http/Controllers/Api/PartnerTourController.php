<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PartnerTourController extends Controller
{
    // List tours that belong to the authenticated partner with optional filters
    public function index(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();

        if (!$partner) {
            return response()->json(['message' => 'Tài khoản đối tác của bạn chưa được phê duyệt hoặc không tồn tại.'], 403);
        }

        $query = DB::table('tours')
            ->where('partner_id', $partner->id);

        $status = $request->get('status');
        if ($status && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $term = '%' . $this->escapeLike($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('title ILIKE ?', [$term])
                    ->orWhereRaw('destination ILIKE ?', [$term]);
            });
        }

        $sort = $request->get('sort', 'created_desc');
        switch ($sort) {
            case 'created_asc':
                $query->orderBy('created_at', 'asc');
                break;
            case 'title_asc':
                $query->orderBy('title', 'asc');
                break;
            case 'title_desc':
                $query->orderBy('title', 'desc');
                break;
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $tours = $query->paginate($request->integer('per_page', 15));

        return response()->json($tours);
    }

    public function show(string $id): JsonResponse
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

    public function store(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();

        if (!$partner) {
            return response()->json(['message' => 'Tài khoản đối tác của bạn chưa được phê duyệt hoặc không tồn tại.'], 403);
        }

        $validated = $request->validate([
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
        $tourId = (string) Str::uuid();

        DB::table('tours')->insert([
            'id' => $tourId,
            'partner_id' => $partner->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'destination' => $validated['destination'] ?? null,
            'duration' => $validated['duration'] ?? null,
            'base_price' => $validated['base_price'],
            'policy' => $validated['policy'] ?? null,
            'tags' => $this->toPostgresArray($validated['tags'] ?? null),
            'media' => $this->encodeJson($validated['media'] ?? null),
            'itinerary' => $this->encodeJson($validated['itinerary'] ?? null),
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $schedule = $validated['schedule'];
        DB::table('tour_schedules')->insert([
            'id' => (string) Str::uuid(),
            'tour_id' => $tourId,
            'start_date' => $schedule['start_date'],
            'end_date' => $schedule['end_date'],
            'seats_total' => $schedule['seats_total'],
            'seats_available' => $schedule['seats_available'],
            'season_price' => $schedule['season_price'] ?? null,
        ]);

        return response()->json([
            'message' => 'Tạo tour thành công.',
            'id' => $tourId,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
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

        $validated = $request->validate([
            'title' => 'sometimes|required|string',
            'description' => 'sometimes|nullable|string',
            'destination' => 'sometimes|nullable|string',
            'duration' => 'sometimes|nullable|integer|min:1',
            'base_price' => 'sometimes|numeric|min:0',
            'policy' => 'sometimes|nullable|string',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'string',
            'media' => 'sometimes|nullable|array',
            'itinerary' => 'sometimes|nullable|array',
            'schedule.id' => 'sometimes|nullable|uuid',
            'schedule.start_date' => 'sometimes|date',
            'schedule.end_date' => 'sometimes|date|after_or_equal:schedule.start_date',
            'schedule.seats_total' => 'sometimes|integer|min:1',
            'schedule.seats_available' => 'sometimes|integer|min:0|lte:schedule.seats_total',
            'schedule.season_price' => 'sometimes|nullable|numeric|min:0',
        ]);

        $tourData = [];

        foreach (['title', 'description', 'destination', 'duration', 'base_price', 'policy'] as $field) {
            if (array_key_exists($field, $validated)) {
                $tourData[$field] = $validated[$field];
            }
        }

        if (array_key_exists('tags', $validated)) {
            $tourData['tags'] = $this->toPostgresArray($validated['tags']);
        }

        if (array_key_exists('media', $validated)) {
            $tourData['media'] = $this->encodeJson($validated['media']);
        }

        if (array_key_exists('itinerary', $validated)) {
            $tourData['itinerary'] = $this->encodeJson($validated['itinerary']);
        }

        if (!empty($tourData)) {
            $tourData['updated_at'] = now();

            DB::table('tours')
                ->where('id', $id)
                ->update($tourData);
        }

        if (isset($validated['schedule'])) {
            $schedule = $validated['schedule'];
            $scheduleData = array_filter([
                'start_date' => $schedule['start_date'] ?? null,
                'end_date' => $schedule['end_date'] ?? null,
                'seats_total' => $schedule['seats_total'] ?? null,
                'seats_available' => $schedule['seats_available'] ?? null,
                'season_price' => $schedule['season_price'] ?? null,
            ], static fn ($value) => !is_null($value));

            if (isset($schedule['id'])) {
                DB::table('tour_schedules')
                    ->where('id', $schedule['id'])
                    ->where('tour_id', $id)
                    ->update($scheduleData);
            } elseif (!empty($scheduleData)) {
                DB::table('tour_schedules')->insert(array_merge([
                    'id' => (string) Str::uuid(),
                    'tour_id' => $id,
                ], $scheduleData));
            }
        }

        return response()->json(['message' => 'Cập nhật tour thành công.']);
    }

    public function destroy(string $id): JsonResponse
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

    private function encodeJson($value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}