<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class ReviewMissingReportRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manageItems', \App\Models\FoundItem::class) ?? false; }
    public function rules(): array { return ['reviewed_at' => ['prohibited'], 'reviewed_by' => ['prohibited'], 'status' => ['prohibited']]; }
}