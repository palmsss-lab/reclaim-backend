<?php

namespace App\Http\Requests\Reports;


use Illuminate\Validation\Validator as ValidationValidator;

class CategoryDateReportRequest extends ReportIndexRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'category_id' => ['sometimes', 'integer', 'exists:category,category_id'],
            'received_from' => ['sometimes', 'date_format:Y-m-d'],
            'received_to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
    }

    public function withValidator(ValidationValidator $validator): void
    {
        $validator->after(function (ValidationValidator $validator): void {
            if ($validator->errors()->has('received_from') || $validator->errors()->has('received_to')) {
                return;
            }

            $from = $this->input('received_from');
            $to = $this->input('received_to');

            if (is_string($from) && is_string($to) && $to < $from) {
                $validator->errors()->add('received_to', 'The received to date must be on or after the received from date.');
            }
        });
    }
}
