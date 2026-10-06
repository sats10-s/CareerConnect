<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/super-admin/companies/{company}/approve|reject|suspend|reactivate.
 * Reject and suspend need a reason the company owner will see; approve and
 * reactivate take an optional note.
 */
class ReviewCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'note'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key)) ?: null]);
            }
        }
    }

    public function rules(): array
    {
        $needsReason = in_array($this->route()?->getActionMethod(), ['reject', 'suspend'], true);

        return [
            'reason' => [$needsReason ? 'required' : 'nullable', 'string', 'min:5', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Give a reason - the company will see it.'];
    }
}
