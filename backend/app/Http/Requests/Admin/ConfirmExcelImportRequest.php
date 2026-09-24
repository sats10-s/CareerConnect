<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/admin/excel/imports/{batch}/confirm. `row_ids` is
 * optional and, when omitted, means "confirm every VALID/WARNING row
 * on this batch" (module spec section 10's ordinary "[Confirm Import]"
 * flow) - when present, it lets an admin confirm only a hand-picked
 * subset of rows from the preview screen (e.g. after fixing the
 * source file for the ERROR rows and re-uploading separately, the
 * admin may still want to proceed with just the rows that were
 * already clean), a reasoned addition the module spec's own
 * "[Cancel] / [Confirm Import]" two-button description doesn't rule
 * out and the preview screen's row-level detail naturally invites.
 */
class ConfirmExcelImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'row_ids' => ['sometimes', 'nullable', 'array'],
            'row_ids.*' => ['integer'],
        ];
    }
}
