<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PartnerTourController extends Controller
{
    private ?bool $scheduleHasTimestamps = null;

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

        $collection = $tours->getCollection()->map(function ($tour) {
            return $this->transformTourRecord($tour);
        });
        $tours->setCollection($collection);

        return response()->json($tours);
    }

    public function show(string $id): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $payload = $this->buildTourPayload($id, $partner->id);

        if (!$payload) {
            return response()->json(['message' => 'Tour not found or you do not have permission to view it.'], 404);
        }

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner();
        if (!$partner) {
            return response()->json(['message' => 'Partner account is not approved or unavailable.'], 403);
        }

        $validated = $request->validate($this->storeRules());
        $schedules = $this->extractSchedules($validated, false);

        $packages = $validated['packages'];
        unset($validated['packages']);

        $now = now();
        $tourId = (string) Str::uuid();

        $payload = DB::transaction(function () use ($partner, $validated, $tourId, $now, $schedules, $packages) {
            $attributes = $this->makeTourAttributes($partner->id, $tourId, $validated, $now);
            DB::table('tours')->insert($attributes);

            $this->syncSchedules($tourId, $schedules);
            $this->syncPackages($tourId, $packages);

            return $this->buildTourPayload($tourId, $partner->id);
        });

        return response()->json(array_merge([
            'message' => 'Tour created successfully. Await admin review.',
            'id' => $tourId,
        ], $payload ?? []), 201);
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
        $schedules = $this->extractSchedules($validated, true);
        $packages = $validated['packages'] ?? null;
        unset($validated['packages']);

        DB::transaction(function () use ($validated, $id, $schedules, $packages) {
            $tourData = $this->extractTourData($validated);
            if (!empty($tourData)) {
                $tourData['updated_at'] = now();

                DB::table('tours')
                    ->where('id', $id)
                    ->update($tourData);
            }

            if ($schedules !== null) {
                $this->syncSchedules($id, $schedules);
            }

            if ($packages !== null) {
                $this->syncPackages($id, $packages);
            }
        });

        $payload = $this->buildTourPayload($id, $partner->id);

        return response()->json(array_merge([
            'message' => 'Tour updated successfully.',
        ], $payload ?? []));
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
            'type' => 'nullable|string|max:50',
            'child_age_limit' => 'nullable|integer|min:0',
            'requires_passport' => 'nullable|boolean',
            'requires_visa' => 'nullable|boolean',
            'status' => 'nullable|in:pending',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'media' => 'nullable|array',
            'itinerary' => 'nullable|array',
            'schedule' => 'sometimes|required|array',
            'schedule.start_date' => 'required_with:schedule|date',
            'schedule.end_date' => 'required_with:schedule|date|after_or_equal:schedule.start_date',
            'schedule.seats_total' => 'required_with:schedule|integer|min:1',
            'schedule.seats_available' => 'required_with:schedule|integer|min:0|lte:schedule.seats_total',
            'schedule.season_price' => 'nullable|numeric|min:0',
            'schedule.min_participants' => 'required_with:schedule|integer|min:1',
            'schedules' => 'sometimes|required|array|min:1|max:10',
            'schedules.*.id' => 'sometimes|uuid',
            'schedules.*.start_date' => 'required_with:schedules|date',
            'schedules.*.end_date' => 'required_with:schedules|date|after_or_equal:start_date',
            'schedules.*.seats_total' => 'required_with:schedules|integer|min:1',
            'schedules.*.seats_available' => 'nullable|integer|min:0',
            'schedules.*.season_price' => 'nullable|numeric|min:0',
            'schedules.*.min_participants' => 'required_with:schedules|integer|min:1',
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
            'type' => 'sometimes|nullable|string|max:50',
            'child_age_limit' => 'sometimes|nullable|integer|min:0',
            'requires_passport' => 'sometimes|boolean',
            'requires_visa' => 'sometimes|boolean',
            'status' => 'sometimes|in:pending,rejected',
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
            'schedule.min_participants' => 'sometimes|integer|min:1',
            'schedules' => 'sometimes|array|max:15',
            'schedules.*.id' => 'sometimes|uuid',
            'schedules.*.start_date' => 'required_with:schedules|date',
            'schedules.*.end_date' => 'required_with:schedules|date|after_or_equal:start_date',
            'schedules.*.seats_total' => 'required_with:schedules|integer|min:1',
            'schedules.*.seats_available' => 'nullable|integer|min:0',
            'schedules.*.season_price' => 'nullable|numeric|min:0',
            'schedules.*.min_participants' => 'required_with:schedules|integer|min:1',
            'packages' => 'sometimes|array|max:5',
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
        $fields = ['title', 'description', 'destination', 'duration', 'base_price', 'policy', 'type', 'child_age_limit', 'status'];
        $data = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $validated)) {
                $data[$field] = $validated[$field];
            }
        }

        if (array_key_exists('base_price', $data) && $data['base_price'] !== null) {
            $data['base_price'] = (float) $data['base_price'];
        }

        if (array_key_exists('child_age_limit', $validated)) {
            $data['child_age_limit'] = is_null($validated['child_age_limit'])
                ? null
                : (int) $validated['child_age_limit'];
        }

        if (array_key_exists('requires_passport', $validated)) {
            $data['requires_passport'] = (bool) $validated['requires_passport'];
        }

        if (array_key_exists('requires_visa', $validated)) {
            $data['requires_visa'] = (bool) $validated['requires_visa'];
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

    private function syncSchedules(string $tourId, ?array $schedules): void
    {
        if ($schedules === null) {
            return;
        }

        $now = now();
        $keepIds = [];
        $hasTimestamps = $this->scheduleHasTimestamps();

        foreach ($schedules as $schedule) {
            $payload = [];

            if (array_key_exists('start_date', $schedule)) {
                $payload['start_date'] = $schedule['start_date'];
            }

            if (array_key_exists('end_date', $schedule)) {
                $payload['end_date'] = $schedule['end_date'];
            }

            if (array_key_exists('seats_total', $schedule)) {
                $payload['seats_total'] = (int) $schedule['seats_total'];
            }

            if (array_key_exists('seats_available', $schedule)) {
                $payload['seats_available'] = (int) $schedule['seats_available'];
            }

            if (array_key_exists('season_price', $schedule)) {
                $payload['season_price'] = is_null($schedule['season_price'])
                    ? null
                    : (float) $schedule['season_price'];
            }

            if (array_key_exists('min_participants', $schedule)) {
                $payload['min_participants'] = is_null($schedule['min_participants'])
                    ? null
                    : (int) $schedule['min_participants'];
            }

            if ($hasTimestamps) {
                $payload['updated_at'] = $now;
            }

            if (!empty($schedule['id'])) {
                DB::table('tour_schedules')
                    ->where('id', $schedule['id'])
                    ->where('tour_id', $tourId)
                    ->update($payload);

                $keepIds[] = $schedule['id'];
                continue;
            }

            $newId = (string) Str::uuid();
            $insertPayload = array_merge([
                'id' => $newId,
                'tour_id' => $tourId,
            ], $payload);

            if ($hasTimestamps) {
                $insertPayload['created_at'] = $now;
            }

            DB::table('tour_schedules')->insert($insertPayload);
            $keepIds[] = $newId;
        }

        $query = DB::table('tour_schedules')->where('tour_id', $tourId);

        if (!empty($keepIds)) {
            $query->whereNotIn('id', $keepIds)->delete();
        } else {
            $query->delete();
        }
    }

    private function syncPackages(string $tourId, ?array $packages): void
    {
        if ($packages === null) {
            return;
        }

        if (empty($packages)) {
            DB::table('tour_packages')->where('tour_id', $tourId)->delete();
            return;
        }

        $now = now();
        $keepIds = [];

        foreach ($packages as $package) {
            $adultPrice = (float) $package['adult_price'];
            $childPrice = array_key_exists('child_price', $package) && $package['child_price'] !== null
                ? (float) $package['child_price']
                : round($adultPrice * 0.75, 2);
            $isActive = array_key_exists('is_active', $package) ? (bool) $package['is_active'] : true;

            if (!empty($package['id'])) {
                DB::table('tour_packages')
                    ->where('id', $package['id'])
                    ->where('tour_id', $tourId)
                    ->update([
                        'name' => $package['name'],
                        'description' => $package['description'] ?? null,
                        'adult_price' => $adultPrice,
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
                'adult_price' => $adultPrice,
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

    private function scheduleHasTimestamps(): bool
    {
        if ($this->scheduleHasTimestamps === null) {
            $this->scheduleHasTimestamps = Schema::hasColumn('tour_schedules', 'created_at')
                && Schema::hasColumn('tour_schedules', 'updated_at');
        }

        return $this->scheduleHasTimestamps;
    }

    private function makeTourAttributes(string $partnerId, string $tourId, array $data, $now): array
    {
        $type = $data['type'] ?? 'domestic';
        $status = $data['status'] ?? 'pending';

        return [
            'id' => $tourId,
            'partner_id' => $partnerId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'destination' => $data['destination'] ?? null,
            'type' => $type,
            'duration' => array_key_exists('duration', $data) && $data['duration'] !== null
                ? (int) $data['duration']
                : null,
            'base_price' => (float) $data['base_price'],
            'policy' => $data['policy'] ?? null,
            'tags' => $this->toPostgresArray($data['tags'] ?? null),
            'media' => $this->encodeJson($data['media'] ?? null),
            'itinerary' => $this->encodeJson($data['itinerary'] ?? null),
            'status' => $status,
            'child_age_limit' => array_key_exists('child_age_limit', $data)
                ? (is_null($data['child_age_limit']) ? null : (int) $data['child_age_limit'])
                : 12,
            'requires_passport' => array_key_exists('requires_passport', $data) ? (bool) $data['requires_passport'] : false,
            'requires_visa' => array_key_exists('requires_visa', $data) ? (bool) $data['requires_visa'] : false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function extractSchedules(array &$validated, bool $isUpdate): ?array
    {
        $hasSchedules = array_key_exists('schedules', $validated);
        $hasSchedule = array_key_exists('schedule', $validated);

        if (!$hasSchedules && !$hasSchedule) {
            return $isUpdate ? null : [];
        }

        $schedules = $hasSchedules
            ? ($validated['schedules'] ?? [])
            : [($validated['schedule'] ?? [])];

        unset($validated['schedules'], $validated['schedule']);

        if (!$isUpdate && empty($schedules)) {
            throw ValidationException::withMessages([
                'schedules' => ['Vui lòng cung cấp ít nhất một lịch khởi hành.'],
            ]);
        }

        return array_map(static function ($schedule) {
            return is_array($schedule) ? $schedule : (array) $schedule;
        }, array_values($schedules));
    }

    private function buildTourPayload(string $tourId, string $partnerId): ?array
    {
        $tour = DB::table('tours')
            ->where('id', $tourId)
            ->where('partner_id', $partnerId)
            ->first();

        if (!$tour) {
            return null;
        }

        $tourData = $this->transformTourRecord($tour);

        $packages = DB::table('tour_packages')
            ->where('tour_id', $tourId)
            ->orderBy('adult_price')
            ->get()
            ->map(fn ($package) => $this->transformPackageRecord($package))
            ->values()
            ->all();

        $schedules = DB::table('tour_schedules')
            ->where('tour_id', $tourId)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->map(fn ($schedule) => $this->transformScheduleRecord($schedule))
            ->values()
            ->all();

        $cancellationPolicies = DB::table('cancellation_policies')
            ->where('tour_id', $tourId)
            ->orderByDesc('days_before')
            ->get()
            ->map(fn ($policy) => $this->transformPolicyRecord($policy))
            ->values()
            ->all();

        $categories = DB::table('categories')
            ->join('tour_categories', 'categories.id', '=', 'tour_categories.category_id')
            ->select('categories.id', 'categories.name', 'categories.slug')
            ->where('tour_categories.tour_id', $tourId)
            ->orderBy('categories.name')
            ->get()
            ->map(fn ($category) => (array) $category)
            ->values()
            ->all();

        return [
            'tour' => $tourData,
            'packages' => $packages,
            'schedules' => $schedules,
            'cancellation_policies' => $cancellationPolicies,
            'categories' => $categories,
        ];
    }

    private function transformTourRecord($tour): array
    {
        $data = (array) $tour;

        $data['tags'] = $this->fromPostgresArray($data['tags'] ?? null);
        $data['media'] = $this->decodeJsonColumn($data['media'] ?? null);
        $data['itinerary'] = $this->decodeJsonColumn($data['itinerary'] ?? null);
        $data['requires_passport'] = $this->toBool($data['requires_passport'] ?? false);
        $data['requires_visa'] = $this->toBool($data['requires_visa'] ?? false);

        if (array_key_exists('child_age_limit', $data) && $data['child_age_limit'] !== null) {
            $data['child_age_limit'] = (int) $data['child_age_limit'];
        }

        if (array_key_exists('duration', $data) && $data['duration'] !== null) {
            $data['duration'] = (int) $data['duration'];
        }

        if (array_key_exists('base_price', $data) && $data['base_price'] !== null) {
            $data['base_price'] = (float) $data['base_price'];
        }

        return $data;
    }

    private function transformPackageRecord($package): array
    {
        $data = (array) $package;

        if (array_key_exists('adult_price', $data) && $data['adult_price'] !== null) {
            $data['adult_price'] = (float) $data['adult_price'];
        }

        if (array_key_exists('child_price', $data) && $data['child_price'] !== null) {
            $data['child_price'] = (float) $data['child_price'];
        }

        $data['is_active'] = $this->toBool($data['is_active'] ?? true);

        return $data;
    }

    private function transformScheduleRecord($schedule): array
    {
        $data = (array) $schedule;

        if (array_key_exists('seats_total', $data) && $data['seats_total'] !== null) {
            $data['seats_total'] = (int) $data['seats_total'];
        }

        if (array_key_exists('seats_available', $data) && $data['seats_available'] !== null) {
            $data['seats_available'] = (int) $data['seats_available'];
        }

        if (array_key_exists('season_price', $data)) {
            $data['season_price'] = is_null($data['season_price'])
                ? null
                : (float) $data['season_price'];
        }

        if (array_key_exists('min_participants', $data) && $data['min_participants'] !== null) {
            $data['min_participants'] = (int) $data['min_participants'];
        }

        return $data;
    }

    private function transformPolicyRecord($policy): array
    {
        $data = (array) $policy;

        if (array_key_exists('days_before', $data) && $data['days_before'] !== null) {
            $data['days_before'] = (int) $data['days_before'];
        }

        if (array_key_exists('refund_rate', $data) && $data['refund_rate'] !== null) {
            $data['refund_rate'] = (float) $data['refund_rate'];
        }

        return $data;
    }

    private function decodeJsonColumn($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 't', 'yes', 'y'], true);
        }

        return (bool) $value;
    }

    private function fromPostgresArray($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $trimmed = trim((string) $value, '{}');
        if ($trimmed === '') {
            return [];
        }

        $items = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $trimmed) ?: [];

        return array_values(array_filter(array_map(static function ($item) {
            $item = trim($item);
            $item = preg_replace('/^"(.*)"$/', '$1', $item);
            $item = str_replace(['\\"', '\\\\'], ['"', '\\'], $item);
            if ($item === 'NULL') {
                return null;
            }
            return $item;
        }, $items), static fn ($item) => !is_null($item)));
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
