<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\OpportunityStatus;
use App\Enums\RoleName;
use App\Exceptions\CompanyReviewException;
use App\Models\Company;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\User;

/**
 * Job and internship postings created from the company portal.
 *
 *   create()  -> draft, source COMPANY, owned by the recruiter's company
 *   update()  only while draft
 *   submit()  company_postings_require_review ON:  marks it for the placement
 *             office to publish (they use the normal admin publish flow)
 *             company_postings_require_review OFF: publishes immediately
 *   close()   published -> closed
 *
 * Recruiters never set eligibility rules or a company id: eligibility stays
 * placement-admin only, and the company is always the caller's own.
 */
class CompanyPostingService
{
    public const TYPES = ['job' => JobPosting::class, 'internship' => Internship::class];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(string $type, Company $company, User $actor, array $data): JobPosting|Internship
    {
        $class = self::TYPES[$type];

        $posting = new $class($data);
        $posting->company_id = $company->id;
        $posting->status = OpportunityStatus::DRAFT;
        $posting->source = 'COMPANY';
        $posting->posted_by = $actor->id;
        $posting->is_active = true;
        $posting->save();

        $this->audit->record('company.posting_created', "{$company->name} drafted {$type} \"{$posting->title}\"", $posting, ['type' => $type], $actor);

        return $posting;
    }

    /** @param array<string, mixed> $data */
    public function update(JobPosting|Internship $posting, User $actor, array $data): JobPosting|Internship
    {
        if ($posting->status !== OpportunityStatus::DRAFT) {
            throw new CompanyReviewException('Only draft postings can be edited. Ask the placement office to change a published posting.', [
                ['code' => 'not_draft', 'message' => 'This posting is '.$posting->status->value.'.'],
            ]);
        }

        $posting->fill($data);
        $changed = array_keys($posting->getDirty());
        $posting->updated_by = $actor->id;
        $posting->save();

        if ($changed) {
            $this->audit->record('company.posting_updated', "Updated \"{$posting->title}\"", $posting, ['fields' => $changed], $actor);
        }

        return $posting;
    }

    /** @return array{posting: JobPosting|Internship, published: bool} */
    public function submit(JobPosting|Internship $posting, User $actor): array
    {
        if ($posting->status !== OpportunityStatus::DRAFT) {
            throw new CompanyReviewException('This posting has already been published or closed.', [
                ['code' => 'not_draft', 'message' => 'Only drafts can be submitted.'],
            ]);
        }

        if (! $this->settings->get('company_postings_require_review')) {
            $posting->status = OpportunityStatus::PUBLISHED;
            $posting->submitted_for_review_at ??= now();
            $posting->updated_by = $actor->id;
            $posting->save();
            $this->audit->record('company.posting_published', "Published \"{$posting->title}\" (no review required)", $posting, [], $actor);

            return ['posting' => $posting, 'published' => true];
        }

        if ($posting->submitted_for_review_at !== null) {
            throw new CompanyReviewException('This posting is already waiting for the placement office.', [
                ['code' => 'already_submitted', 'message' => 'Already submitted for review.'],
            ]);
        }

        $posting->submitted_for_review_at = now();
        $posting->save();

        $company = $posting->company;
        $this->audit->record('company.posting_submitted', "{$company->name} submitted \"{$posting->title}\" for review", $posting, [], $actor);
        $this->notifications->createMany(
            User::query()->where('is_active', true)->whereHas('role', fn ($q) => $q->where('name', RoleName::PLACEMENT_ADMIN->value))->get(),
            NotificationType::COMPANY_POSTING_SUBMITTED,
            'Company posting waiting for review',
            "{$company->name} submitted \"{$posting->title}\". Review its eligibility and publish it from ".($posting instanceof Internship ? 'Internships' : 'Jobs').'.',
            ['posting_id' => $posting->id, 'type' => $this->typeOf($posting), 'company_id' => $company->id],
        );

        return ['posting' => $posting, 'published' => false];
    }

    public function close(JobPosting|Internship $posting, User $actor): JobPosting|Internship
    {
        if ($posting->status !== OpportunityStatus::PUBLISHED) {
            throw new CompanyReviewException('Only published postings can be closed.', [
                ['code' => 'not_published', 'message' => 'This posting is '.$posting->status->value.'.'],
            ]);
        }

        $posting->status = OpportunityStatus::CLOSED;
        $posting->updated_by = $actor->id;
        $posting->save();
        $this->audit->record('company.posting_closed', "Closed \"{$posting->title}\"", $posting, [], $actor);

        return $posting;
    }

    /**
     * Called by the admin posting controllers after an update: when the
     * placement office publishes a COMPANY posting, tell its recruiters.
     */
    public function afterAdminUpdate(JobPosting|Internship $posting, ?OpportunityStatus $previous): void
    {
        if ($posting->source !== 'COMPANY' || $previous === OpportunityStatus::PUBLISHED || $posting->status !== OpportunityStatus::PUBLISHED) {
            return;
        }

        $members = User::query()->where('company_id', $posting->company_id)->where('is_active', true)->get();
        $this->notifications->createMany(
            $members,
            NotificationType::COMPANY_POSTING_PUBLISHED,
            'Your posting is live',
            "\"{$posting->title}\" was published by the placement office and is now visible to eligible students.",
            ['posting_id' => $posting->id, 'type' => $this->typeOf($posting)],
        );
    }

    public function typeOf(JobPosting|Internship $posting): string
    {
        return $posting instanceof Internship ? 'internship' : 'job';
    }
}
