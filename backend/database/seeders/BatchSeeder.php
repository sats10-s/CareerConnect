<?php

namespace Database\Seeders;

use App\Models\Batch;
use Illuminate\Database\Seeder;

class BatchSeeder extends Seeder
{
    public function run(): void
    {
        $batches = [
            ['name' => '2021-2025', 'start_year' => 2021, 'end_year' => 2025],
            ['name' => '2022-2026', 'start_year' => 2022, 'end_year' => 2026],
            ['name' => '2023-2027', 'start_year' => 2023, 'end_year' => 2027],
        ];

        foreach ($batches as $batch) {
            Batch::updateOrCreate(
                ['start_year' => $batch['start_year'], 'end_year' => $batch['end_year']],
                $batch
            );
        }
    }
}
