<?php

namespace App\Http\Requests\User;

use Illuminate\Validation\Rule;

class MyMissingReportIndexRequest extends MyReportIndexRequest
{
    public function rules(): array
    {
        return parent::rules() + ['status' => ['sometimes', Rule::in(['OPEN', 'RESOLVED', 'WITHDRAWN'])]];
    }
}
