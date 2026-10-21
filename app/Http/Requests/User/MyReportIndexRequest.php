<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class MyReportIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]; }
}