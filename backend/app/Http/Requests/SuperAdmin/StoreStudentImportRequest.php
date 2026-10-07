<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\ExcelImportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/super-admin/student-data/imports - same file rules as the Excel Sync Center. */
class StoreStudentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['students', 'academic_update', 'placement_admins'])],
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'semester_label' => ['sometimes', 'nullable', 'string', 'max:40'],
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
        return match ($this->validated('type')) {
            'students' => ExcelImportType::STUDENTS,
            'placement_admins' => ExcelImportType::PLACEMENT_ADMINS,
            default => ExcelImportType::ACADEMIC_UPDATE,
        };
    }
}
