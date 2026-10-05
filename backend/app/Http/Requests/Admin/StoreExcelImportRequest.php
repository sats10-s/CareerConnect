<?php

namespace App\Http\Requests\Admin;

use App\Enums\ExcelImportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/excel/import. `type` selects which of the 8 column
 * maps App\Services\ExcelParsingService::columnMapFor() applies;
 * `file` is validated the same content-based way as every other
 * private-file upload in this codebase (StoreInternshipDocumentRequest/
 * UploadResumeRequest precedent) - `mimes:xlsx,xls` inspects the
 * file's actual detected type, never the client-supplied filename or
 * Content-Type header, and the 10MB cap mirrors
 * App\Services\ExcelImportService::MAX_FILE_SIZE_KB exactly (module
 * spec section 33's own "file size limits" security requirement -
 * enforced here at the HTTP boundary AND defensively re-checked by the
 * service, same belt-and-braces pattern as every other upload path in
 * this codebase).
 */
class StoreExcelImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(ExcelImportType::syncCenterValues())],
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'The file must be a valid .xlsx or .xls Excel workbook.',
            'file.max' => 'The file must be 10MB or smaller.',
        ];
    }

    public function importType(): ExcelImportType
    {
        return ExcelImportType::from($this->validated('type'));
    }
}
