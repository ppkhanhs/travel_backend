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
    public function index(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $query = DB::table('tours')->where('partner_id', $partner->id);

        $status = $request->get('status');
        if ($status && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $term = '%' . $this->escapeLike($search) . '%';
            $query->where(function ($subQuery) use ($term) {
                $subQuery->whereRaw('title ILIKE ?', [$term])
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
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $tour = DB::table('tours')
            ->where('id', $id)
            ->where('partner_id', $partner->id)
            ->first();

        if (!$tour) {
            return response()->json(['message' => 'Tour not found or you do not have permission to view it.'], 404);
        }

        $packages = DB::table('tour_packages')
            ->where('tour_id', $tour->id)
            ->orderBy('adult_price')
            ->get();

        $schedules = DB::table('tour_schedules')
            ->where('tour_id', $tour->id)
            ->orderBy('start_date')
            ->get();

        return response()->json([
            'tour' => $tour,
            'packages' => $packages,
            'schedules' => $schedules,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $validated = $request->validate($this->storeRules());
        $now = now();
        $tourId = (string) Str::uuid();

        DB::transaction(function () use ($partner, $validated, $tourId, $now) {
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

            $this->syncPackages($tourId, $validated['packages']);
        });

        return response()->json([
            'message' => 'Tour created successfully. Await admin review.',
            'id' => $tourId,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $tour = DB::table('tours')
            ->where('id', $id)
            ->where('partner_id', $partner->id)
            ->first();

        if (!$tour) {
            return response()->json(['message' => 'Tour not found or you do not have permission to update it.'], 404);
        }

        $validated = $request->validate($this->updateRules());

        DB::transaction(function () use ($validated, $id) {
            $tourData = $this->extractTourData($validated);
            if (!empty($tourData)) {
                $tourData['updated_at'] = now();

                DB::table('tours')
                    ->where('id', $id)
                    ->update($tourData);
            }

            if (isset($validated['schedule'])) {
                $this->syncSchedule($id, $validated['schedule']);
            }

            if (isset($validated['packages'])) {
                $this->syncPackages($id, $validated['packages']);
            }
        });

        return response()->json(['message' => 'Tour updated successfully.']);
    }

    public function destroy(string $id): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $tour = DB::table('tours')
            ->where('id', $id)
            ->where('partner_id', $partner->id)
            ->first();

        if (!$tour) {
            return response()->json(['message' => 'Tour not found or you do not have permission to delete it.'], 404);
        }

        DB::transaction(function () use ($id) {
            DB::table('tour_packages')->where('tour_id', $id)->delete();
            DB::table('tour_schedules')->where('tour_id', $id)->delete();
            DB::table('tours')->where('id', $id)->delete();
        });

        return response()->json(['message' => 'Tour deleted successfully.']);
    }

    private function storeRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'destination' => 'nullable|string|max:255',
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
            'packages' => 'required|array|min:1|max:5',
            'packages.*.name' => 'required|string|max:255',
            'packages.*.description' => 'nullable|string',
            'packages.*.adult_price' => 'required|numeric|min:0',
            'packages.*.child_price' => 'nullable|numeric|min:0',
            'packages.*.is_active' => 'nullable|boolean',
        ];
    }

    private function updateRules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'destination' => 'sometimes|nullable|string|max:255',
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
            'packages' => 'sometimes|array|min:1|max:5',
            'packages.*.id' => 'sometimes|uuid',
            'packages.*.name' => 'required_with:packages|string|max:255',
            'packages.*.description' => 'nullable|string',
            'packages.*.adult_price' => 'required_with:packages|numeric|min:0',
            'packages.*.child_price' => 'nullable|numeric|min:0',
            'packages.*.is_active' => 'nullable|boolean',
        ];
    }

    private function extractTourData(array $validated): array
    {
        $fields = ['title', 'description', 'destination', 'duration', 'base_price', 'policy'];
        $data = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $validated)) {
                $data[$field] = $validated[$field];
            }
        }

        if (array_key_exists('tags', $validated)) {
            $data['tags'] = $this->toPostgresArray($validated['tags']);
        }

        if (array_key_exists('media', $validated)) {
            $data['media'] = $this->encodeJson($validated['media']);
        }

        if (array_key_exists('itinerary', $validated)) {
            $data['itinerary'] = $this->encodeJson($validated['itinerary']);
        }

        return $data;
    }

    private function syncSchedule(string $tourId, array $schedule): void
    {
        $payload = array_filter([
            'start_date' => $schedule['start_date'] ?? null,
            'end_date' => $schedule['end_date'] ?? null,
            'seats_total' => $schedule['seats_total'] ?? null,
            'seats_available' => $schedule['seats_available'] ?? null,
            'season_price' => $schedule['season_price'] ?? null,
        ], static fn ($value) => !is_null($value));

        if (empty($payload)) {
            return;
        }

        if (!empty($schedule['id'])) {
            DB::table('tour_schedules')
                ->where('id', $schedule['id'])
                ->where('tour_id', $tourId)
                ->update($payload);

            return;
        }

        DB::table('tour_schedules')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tour_id' => $tourId,
        ], $payload));
    }

    private function syncPackages(string $tourId, array $packages): void
    {
        $now = now();
        $keepIds = [];

        foreach ($packages as $package) {
            $childPrice = $package['child_price'] ?? round($package['adult_price'] * 0.75, 2);
            $isActive = array_key_exists('is_active', $package) ? (bool) $package['is_active'] : true;

            if (!empty($package['id'])) {
                DB::table('tour_packages')
                    ->where('id', $package['id'])
                    ->where('tour_id', $tourId)
                    ->update([
                        'name' => $package['name'],
                        'description' => $package['description'] ?? null,
                        'adult_price' => $package['adult_price'],
                        'child_price' => $childPrice,
                        'is_active' => $isActive,
                        'updated_at' => $now,
                    ]);

                $keepIds[] = $package['id'];
                continue;
            }

            $newId = (string) Str::uuid();
            DB::table('tour_packages')->insert([
                'id' => $newId,
                'tour_id' => $tourId,
                'name' => $package['name'],
                'description' => $package['description'] ?? null,
                'adult_price' => $package['adult_price'],
                'child_price' => $childPrice,
                'is_active' => $isActive,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $keepIds[] = $newId;
        }

        if (!empty($keepIds)) {
            DB::table('tour_packages')
                ->where('tour_id', $tourId)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }
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

        $escaped = array_map(static function ($item) {
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
