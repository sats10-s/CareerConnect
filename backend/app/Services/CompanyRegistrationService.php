<?php

namespace App\Services;

use App\Enums\CompanyMemberRole;
use App\Enums\CompanySource;
use App\Enums\CompanyStatus;
use App\Enums\NotificationType;
use App\Enums\RoleName;
use App\Exceptions\CompanyReviewException;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Company self-registration and the Super Admin review workflow:
 *
 *   register()   -> PENDING (company + OWNER login, one transaction)
 *   approve()    PENDING|REJECTED -> APPROVED
 *   reject()     PENDING -> REJECTED            (reason required)
 *   suspend()    APPROVED -> SUSPENDED          (reason required, signs every company login out)
 *   reactivate() SUSPENDED -> APPROVED
 *
 * Every transition is audited and notifies the company's owner. Callers pass
 * the acting User explicitly; nothing here reads the request.
 */
class CompanyRegistrationService
{
    /** Legal-form suffixes ignored when comparing names for the duplicate flag. */
    private const NAME_SUFFIXES = ['private', 'pvt', 'limited', 'ltd', 'inc', 'incorporated', 'llp', 'llc', 'corp', 'corporation', 'co', 'company', 'plc', 'gmbh'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated RegisterCompanyRequest payload
     */
    public function register(array $data): User
    {
        if (! $this->settings->get('allow_company_registration')) {
            $this->audit->record('company.registration_blocked', 'Company registration attempt while registration is closed', null, ['company' => $data['company_name'] ?? null, 'email' => $data['email'] ?? null]);

            throw ValidationException::withMessages([
                'company_name' => ['Company registration is currently closed. Contact the placement office.'],
            ]);
        }

        $user = DB::transaction(function () use ($data) {
            $company = new Company([
                'name' => $data['company_name'],
                'industry' => $data['industry'] ?? null,
                'website' => $data['website'] ?? null,
                'description' => $data['description'] ?? null,
                'headquarters' => $data['headquarters'] ?? null,
                'company_size' => $data['company_size'] ?? null,
                'registration_number' => $data['registration_number'] ?? null,
                'contact_name' => $data['contact_name'],
                'contact_email' => $data['email'],
                'contact_phone' => $data['contact_phone'] ?? null,
                'is_active' => true,
            ]);
            $company->status = CompanyStatus::PENDING;
            $company->source = CompanySource::SELF_REGISTERED;
            $company->save();

            return User::create([
                'name' => $data['contact_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role_id' => Role::where('name', RoleName::COMPANY->value)->firstOrFail()->id,
                'company_id' => $company->id,
                'company_member_role' => CompanyMemberRole::OWNER,
                'is_active' => true,
            ]);
        });

        $user->load(['role', 'company']);
        $duplicates = $this->duplicatesFor($user->company);
        $this->audit->record('company.registered', "{$user->company->name} registered (owner {$user->email})", $user->company, array_filter([
            'owner_email' => $user->email,
            'possible_duplicates' => $duplicates->pluck('name')->all() ?: null,
        ]), $user);

        return $user;
    }

    public function approve(Company $company, User $actor, ?string $note = null): Company
    {
        $this->assertStatus($company, [CompanyStatus::PENDING, CompanyStatus::REJECTED], 'approve');
        $this->transition($company, CompanyStatus::APPROVED, $actor, $note);
        $this->audit->record('company.approved', "Approved company {$company->name}", $company, array_filter(['note' => $note]), $actor);
        $this->notifyOwner($company, 'Your company is approved', "{$company->name} has been approved on CareerConnect. Your company portal is now unlocked.");

        return $company;
    }

    public function reject(Company $company, User $actor, string $reason): Company
    {
        $this->assertStatus($company, [CompanyStatus::PENDING], 'reject');
        $this->transition($company, CompanyStatus::REJECTED, $actor, $reason);
        $this->audit->record('company.rejected', "Rejected company {$company->name}", $company, ['reason' => $reason], $actor);
        $this->notifyOwner($company, 'Your company registration was not approved', "Reason: {$reason}");

        return $company;
    }

    public function suspend(Company $company, User $actor, string $reason): Company
    {
        $this->assertStatus($company, [CompanyStatus::APPROVED], 'suspend');

        $revoked = DB::transaction(function () use ($company, $actor, $reason) {
            $this->transition($company, CompanyStatus::SUSPENDED, $actor, $reason);

            return $company->members()->get()->sum(fn (User $member) => $this->revokeAccess($member));
        });

        $this->audit->record('company.suspended', "Suspended company {$company->name}", $company, ['reason' => $reason, 'sessions_revoked' => $revoked], $actor);
        $this->notifyOwner($company, 'Your company account is suspended', "Reason: {$reason}");

        return $company;
    }

    public function reactivate(Company $company, User $actor, ?string $note = null): Company
    {
        $this->assertStatus($company, [CompanyStatus::SUSPENDED], 'reactivate');
        $this->transition($company, CompanyStatus::APPROVED, $actor, $note);
        $this->audit->record('company.reactivated', "Reactivated company {$company->name}", $company, array_filter(['note' => $note]), $actor);
        $this->notifyOwner($company, 'Your company account is active again', "{$company->name} has been reactivated on CareerConnect.");

        return $company;
    }

    /**
     * Other companies whose normalised name or website domain matches this
     * one's - a hint for the reviewer, never a block.
     *
     * @return Collection<int, array{id: int, name: string, status: string|null, reasons: list<string>}>
     */
    public function duplicatesFor(Company $company, ?Collection $pool = null): Collection
    {
        $name = $this->normaliseName($company->name);
        $domain = $this->domain($company->website);
        $pool ??= Company::query()->get(['id', 'name', 'website', 'status']);

        return $pool
            ->reject(fn (Company $other) => $other->id === $company->id)
            ->map(function (Company $other) use ($name, $domain) {
                $reasons = [];
                if ($name !== '' && $this->normaliseName($other->name) === $name) {
                    $reasons[] = 'name';
                }
                if ($domain && $this->domain($other->website) === $domain) {
                    $reasons[] = 'website';
                }

                return $reasons ? ['id' => $other->id, 'name' => $other->name, 'status' => $other->status?->value, 'reasons' => $reasons] : null;
            })
            ->filter()
            ->values();
    }

    public function normaliseName(?string $name): string
    {
        $words = preg_split('/\s+/', trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $name))), -1, PREG_SPLIT_NO_EMPTY);
        while ($words && in_array(end($words), self::NAME_SUFFIXES, true)) {
            array_pop($words);
        }

        return implode(' ', $words);
    }

    public function domain(?string $website): ?string
    {
        if (! $website) {
            return null;
        }
        $host = parse_url(str_contains($website, '://') ? $website : "https://{$website}", PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }

    /** @param list<CompanyStatus> $from */
    private function assertStatus(Company $company, array $from, string $verb): void
    {
        if (! in_array($company->status, $from, true)) {
            $current = $company->status?->label() ?? 'Unknown';
            throw new CompanyReviewException("You can't {$verb} a company that is {$current}.", [
                ['code' => 'invalid_status', 'message' => "Only ".implode(' or ', array_map(fn (CompanyStatus $s) => strtolower($s->label()), $from))." companies can be {$verb}d."],
            ]);
        }
    }

    private function transition(Company $company, CompanyStatus $to, User $actor, ?string $note): void
    {
        $company->status = $to;
        $company->reviewed_by = $actor->id;
        $company->reviewed_at = now();
        $company->review_note = $note;
        $company->save();
    }

    private function notifyOwner(Company $company, string $title, string $message): void
    {
        $owner = $company->owner()->first();
        if ($owner) {
            $this->notifications->create($owner, NotificationType::COMPANY_STATUS_CHANGED, $title, $message, [
                'company_id' => $company->id,
                'status' => $company->status->value,
            ]);
        }
    }

    /** Delete API tokens and (database-driver) web sessions; returns how many were removed. */
    private function revokeAccess(User $user): int
    {
        $count = $user->tokens()->delete();
        if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
            $count += DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return $count;
    }
}
