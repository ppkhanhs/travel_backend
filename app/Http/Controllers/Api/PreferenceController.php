<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PreferenceController extends Controller
{
    public function options(): JsonResponse
    {
        // Danh sách gợi ý sẵn (slug, label)
        $options = [
            ['value' => 'bien', 'label' => 'Biển / Nghỉ dưỡng biển'],
            ['value' => 'nui', 'label' => 'Núi / Camping / Trekking'],
            ['value' => 'city_tour', 'label' => 'City tour / Khám phá đô thị'],
            ['value' => 'am_thuc', 'label' => 'Ẩm thực / Food tour'],
            ['value' => 'van_hoa_lich_su', 'label' => 'Văn hóa / Lịch sử'],
            ['value' => 'gia_dinh', 'label' => 'Gia đình / Trẻ em'],
            ['value' => 'cap_doi', 'label' => 'Cặp đôi / Lãng mạn'],
            ['value' => 'team_building', 'label' => 'Team building / Công ty'],
            ['value' => 'mua_sam', 'label' => 'Mua sắm'],
            ['value' => 'sinh_thai', 'label' => 'Sinh thái / Thiên nhiên'],
        ];

        return response()->json(['options' => $options]);
    }
}
