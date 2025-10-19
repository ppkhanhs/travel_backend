<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PassengerResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'full_name' => $this->full_name,
            'gender' => $this->gender,
            'date_of_birth' => optional($this->date_of_birth)->toDateString(),
            'document_number' => $this->document_number,
        ];
    }
}

