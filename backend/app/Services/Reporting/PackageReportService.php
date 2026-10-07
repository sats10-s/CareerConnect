<?php

namespace App\Services\Reporting;

use App\Enums\ApplicationStatus;
use App\Enums\PpoStatus;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\PpoRecord;
use App\Models\Student;
use App\Services\Reporting\Concerns\AppliesReportFilters;
use Illuminate\Support\Collection;

/**
 * Package (CTC) statistics. An "offer" is either a SELECTED job
 * application with an offer_package entered, or a PPO that was not
 * declined and has a package. Internship selections are left out: a
 * stipend is not an annual package.
 *
 * Amounts are stored as annual rupees and reported in LPA (lakhs per
 * annum). Student-level figures (overview, by department, best offer)
 * use each student's single best offer, the way NIRF/NAAC count median
 * salary of placed students; company-level figures use every offer
 * that company made. Offers selected without a package entered are
 * counted separately and never averaged in as 0.
 */
class PackageReportService
{
    use AppliesReportFilters;

    private const RUPEES_PER_LAKH = 100000;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{overview: array<string, int|float|null>, by_department: list<array<string, mixed>>, by_company: list<array<string, mixed>>, best_offers: list<array<string, mixed>>}
     */
    public function report(array $filters): array
    {
        $offers = $this->offers($filters);
        $priced = $offers->whereNotNull('lpa');
        $best = $priced->groupBy('student_id')->map(fn (Collection $o) => $o->sortByDesc('lpa')->first());
        $students = Student::with(['user', 'department', 'batch'])->whereIn('id', $offers->pluck('student_id')->unique())->get()->keyBy('id');

        $overview = [
            'students_with_offers' => $offers->pluck('student_id')->unique()->count(),
            'students_with_package' => $best->count(),
            'offers' => $offers->count(),
            'offers_without_package' => $offers->whereNull('lpa')->count(),
            ...$this->stats($best->pluck('lpa')),
        ];

        $byDepartment = $best
            ->groupBy(fn ($o) => $students->get($o['student_id'])?->department?->name ?? '—')
            ->map(fn (Collection $o, string $department) => ['department' => $department, 'students' => $o->count(), ...$this->stats($o->pluck('lpa'))])
            ->sortBy('department')
            ->values()
            ->all();

        $byCompany = $offers
            ->groupBy(fn ($o) => $o['company'] ?? '—')
            ->map(fn (Collection $o, string $company) => [
                'company' => $company,
                'offers' => $o->count(),
                'offers_without_package' => $o->whereNull('lpa')->count(),
                ...$this->stats($o->whereNotNull('lpa')->pluck('lpa')),
            ])
            ->sortByDesc('highest')
            ->values()
            ->all();

        $bestOffers = $offers->pluck('student_id')->unique()
            ->map(function (int $studentId) use ($offers, $best, $students) {
                $offer = $best->get($studentId) ?? $offers->firstWhere('student_id', $studentId);

                return [
                    'student' => $students->get($studentId),
                    'company' => $offer['company'],
                    'role' => $offer['role'],
                    'source' => $offer['source'],
                    'lpa' => $offer['lpa'],
                    'offers' => $offers->where('student_id', $studentId)->count(),
                ];
            })
            ->sortBy(fn ($r) => [$r['student']?->department?->name, $r['student']?->roll_number])
            ->values()
            ->all();

        return ['overview' => $overview, 'by_department' => $byDepartment, 'by_company' => $byCompany, 'best_offers' => $bestOffers];
    }

    /**
     * Every in-scope offer as a plain row.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{student_id: int, company: ?string, role: ?string, source: string, lpa: ?float}>
     */
    public function offers(array $filters): Collection
    {
        $jobs = Application::query()
            ->with('applicable.company')
            ->where('status', ApplicationStatus::SELECTED->value)
            ->where('applicable_type', JobPosting::class);
        $this->applyStudentRelationScope($jobs, $filters);
        $this->applyCompanyScope($jobs, $filters);
        $this->applyDateRange($jobs, 'selected_at', $filters);

        $ppos = PpoRecord::query()
            ->with(['company', 'internshipAssignment'])
            ->where('status', '!=', PpoStatus::DECLINED->value);
        $this->applyStudentRelationScope($ppos, $filters, 'internshipAssignment.student');
        $this->applyDateRange($ppos, 'offered_at', $filters);
        if (! empty($filters['company_id'])) {
            $ppos->where('company_id', $filters['company_id']);
        }

        return $jobs->get()->map(fn (Application $a) => [
            'student_id' => (int) $a->student_id,
            'company' => $a->applicable?->company?->name,
            'role' => $a->applicable?->title,
            'source' => 'Campus offer',
            'lpa' => $this->lpa($a->offer_package),
        ])->concat($ppos->get()->filter(fn (PpoRecord $p) => $p->internshipAssignment)->map(fn (PpoRecord $p) => [
            'student_id' => (int) $p->internshipAssignment->student_id,
            'company' => $p->company?->name,
            'role' => $p->designation,
            'source' => 'PPO',
            'lpa' => $this->lpa($p->package),
        ]))->values();
    }

    /**
     * @param  Collection<int, float>  $values
     * @return array{highest: ?float, average: ?float, median: ?float, lowest: ?float}
     */
    private function stats(Collection $values): array
    {
        if ($values->isEmpty()) {
            return ['highest' => null, 'average' => null, 'median' => null, 'lowest' => null];
        }

        return [
            'highest' => round($values->max(), 2),
            'average' => round($values->avg(), 2),
            'median' => round($values->median(), 2),
            'lowest' => round($values->min(), 2),
        ];
    }

    private function lpa(mixed $rupees): ?float
    {
        return $rupees === null ? null : ((float) $rupees) / self::RUPEES_PER_LAKH;
    }
}
