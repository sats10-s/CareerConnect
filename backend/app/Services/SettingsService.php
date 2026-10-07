<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Institution settings. DEFINITIONS is the single source of truth for which
 * keys exist, their type, default and validation; anything else is ignored.
 */
class SettingsService
{
    public const DEFINITIONS = [
        'institution_name' => ['type' => 'string', 'default' => 'CareerConnect Institute', 'rules' => ['required', 'string', 'max:120'], 'label' => 'Institution name'],
        'support_email' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'email', 'max:255'], 'label' => 'Support email'],
        'academic_year' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'regex:/^\d{4}-\d{2,4}$/'], 'label' => 'Current academic year'],
        'allow_student_registration' => ['type' => 'bool', 'default' => false, 'rules' => ['required', 'boolean'], 'label' => 'Student self-registration'],
        'allow_company_registration' => ['type' => 'bool', 'default' => true, 'rules' => ['required', 'boolean'], 'label' => 'Company self-registration'],
        'company_postings_require_review' => ['type' => 'bool', 'default' => true, 'rules' => ['required', 'boolean'], 'label' => 'Company postings need Placement Admin review'],
        'weekly_hod_summary' => ['type' => 'bool', 'default' => false, 'rules' => ['required', 'boolean'], 'label' => 'Email HODs a weekly placement summary'],
        'attendance_threshold' => ['type' => 'int', 'default' => 75, 'rules' => ['required', 'integer', 'between:1,100'], 'label' => 'Attendance alert threshold (%)'],
    ];

    /** @return array<string, mixed> every declared setting with its current (or default) value */
    public function all(): array
    {
        $stored = Schema::hasTable('app_settings') ? AppSetting::query()->pluck('value', 'key') : collect();

        $values = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $values[$key] = $stored->has($key) ? $this->cast($stored->get($key), $definition['type']) : $definition['default'];
        }

        return $values;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $changes  already validated
     * @return array<string, array{from: mixed, to: mixed}> what actually changed
     */
    public function update(array $changes, ?User $actor): array
    {
        $current = $this->all();
        $diff = [];

        foreach ($changes as $key => $value) {
            if (! array_key_exists($key, self::DEFINITIONS)) {
                continue;
            }
            $value = $this->cast($value === null ? null : (string) (is_bool($value) ? (int) $value : $value), self::DEFINITIONS[$key]['type']);
            if ($value === $current[$key]) {
                continue;
            }
            AppSetting::updateOrCreate(['key' => $key], [
                'value' => $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : (string) $value),
                'updated_by' => $actor?->id,
            ]);
            $diff[$key] = ['from' => $current[$key], 'to' => $value];
        }

        return $diff;
    }

    private function cast(?string $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($type) {
            'bool' => in_array(strtolower($raw), ['1', 'true', 'yes'], true),
            'int' => (int) $raw,
            default => $raw,
        };
    }
}
