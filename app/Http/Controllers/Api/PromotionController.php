<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PromotionController extends Controller
{
    public function active(Request $request): JsonResponse
    {
        $limit = $request->integer('limit');

        $query = Promotion::active()->orderBy('valid_from');
        if ($limit) {
            $query->limit($limit);
        }

        return response()->json($query->get());
    }
}
