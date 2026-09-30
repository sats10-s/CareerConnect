<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Company;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET/POST/PATCH /api/admin/companies[...] - see Api\Admin\CompanyController.
 */
class CompanyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }
    }

    private function makeUser(RoleName $role): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => strtolower($role->value).'-'.uniqid().'@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => Role::where('name', $role->value)->firstOrFail()->id,
        ]);
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs($this->makeUser(RoleName::PLACEMENT_ADMIN), ['*']);
    }

    public function test_admin_lists_companies_with_posting_counts_search_and_industries(): void
    {
        $nova = Company::create(['name' => 'NovaTech Solutions', 'industry' => 'Software', 'is_active' => true]);
        Company::create(['name' => 'FinEdge', 'industry' => 'Finance', 'is_active' => true]);
        JobPosting::create(['company_id' => $nova->id, 'title' => 'SE I', 'status' => 'published']);
        Internship::create(['company_id' => $nova->id, 'title' => 'Frontend Intern', 'status' => 'draft']);

        $this->actingAsAdmin();
        $this->getJson('/api/admin/companies')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.items.1.name', 'NovaTech Solutions')
            ->assertJsonPath('data.items.1.job_postings_count', 1)
            ->assertJsonPath('data.items.1.internships_count', 1)
            ->assertJsonPath('data.industries', ['Finance', 'Software']);

        $this->getJson('/api/admin/companies?search=nova')->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/admin/companies?industry=Finance')->assertJsonPath('data.items.0.name', 'FinEdge');
    }

    public function test_admin_creates_a_company_with_validation(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/companies', ['name' => '   '])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/companies', ['name' => 'Craftly', 'website' => 'not a url'])->assertStatus(422)->assertJsonValidationErrors('website');

        $this->postJson('/api/admin/companies', ['name' => '  Craftly ', 'industry' => 'Design', 'website' => 'https://craftly.example.com'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Craftly')
            ->assertJsonPath('data.job_postings_count', 0);

        $this->postJson('/api/admin/companies', ['name' => 'Craftly'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'A company with this name already exists.');
    }

    public function test_admin_updates_a_company_and_show_lists_its_postings(): void
    {
        $nova = Company::create(['name' => 'NovaTech Solutions', 'industry' => 'Software', 'is_active' => true]);
        Company::create(['name' => 'FinEdge', 'is_active' => true]);
        JobPosting::create(['company_id' => $nova->id, 'title' => 'SE I', 'status' => 'published']);

        $this->actingAsAdmin();
        $this->patchJson("/api/admin/companies/{$nova->id}", ['name' => 'NovaTech Solutions'])->assertOk();
        $this->patchJson("/api/admin/companies/{$nova->id}", ['name' => 'FinEdge'])->assertStatus(422);
        $this->patchJson("/api/admin/companies/{$nova->id}", ['industry' => '', 'description' => 'Product company'])
            ->assertOk()
            ->assertJsonPath('data.industry', null)
            ->assertJsonPath('data.description', 'Product company');

        $this->getJson("/api/admin/companies/{$nova->id}")
            ->assertOk()
            ->assertJsonPath('data.postings.0.title', 'SE I')
            ->assertJsonPath('data.postings.0.type', 'job');
    }

    public function test_non_admins_cannot_read_or_write_companies(): void
    {
        $company = Company::create(['name' => 'NovaTech Solutions', 'is_active' => true]);

        foreach ([RoleName::FACULTY, RoleName::STUDENT] as $role) {
            Sanctum::actingAs($this->makeUser($role), ['*']);
            $this->getJson('/api/admin/companies')->assertForbidden();
            $this->postJson('/api/admin/companies', ['name' => 'X'])->assertForbidden();
            $this->patchJson("/api/admin/companies/{$company->id}", ['name' => 'Y'])->assertForbidden();
        }

        Sanctum::actingAs($this->makeUser(RoleName::SUPER_ADMIN), ['*']);
        $this->getJson('/api/admin/companies')->assertOk();
    }
}
