<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super Admin > Settings: read and update the declared institution settings (SettingsService::DEFINITIONS). */
class SettingController extends Controller
{
    use FormatsApiResponse;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        return $this->success(data: $this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (SettingsService::DEFINITIONS as $key => $definition) {
            $rules[$key] = array_merge(['sometimes'], $definition['rules']);
        }
        $data = $request->validate($rules, [
            'academic_year.regex' => 'Use the format 2026-27 or 2026-2027.',
        ], collect(SettingsService::DEFINITIONS)->map(fn ($d) => strtolower($d['label']))->all());

        $diff = $this->settings->update($data, $request->user());
        if ($diff) {
            $labels = collect(array_keys($diff))->map(fn ($k) => SettingsService::DEFINITIONS[$k]['label'])->implode(', ');
            $this->audit->record('settings.updated', "Changed settings: {$labels}", null, ['changes' => $diff]);
        }

        return $this->success(data: $this->payload(), message: $diff ? 'Settings saved.' : 'Nothing changed.');
    }

    private function payload(): array
    {
        return [
            'values' => $this->settings->all(),
            'definitions' => collect(SettingsService::DEFINITIONS)->map(fn ($d, $key) => ['key' => $key, 'label' => $d['label'], 'type' => $d['type'], 'default' => $d['default']])->values(),
        ];
    }
}
