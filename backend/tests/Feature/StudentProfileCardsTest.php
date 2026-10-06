<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentSkill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * The reworked profile: skill tags, "Report a correction", the admin
 * directory's new filters and the faculty view of an assigned student.
 */
class StudentProfileCardsTest extends TestCase
{
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlacementFixtures();
    }

    public function test_skills_dedupe_case_insensitively_and_are_capped(): void
    {
        $student = $this->makeStudentUser();
        Sanctum::actingAs($student, ['*']);

        $this->postJson('/api/student/profile/skills', ['skill_name' => '  React   Native '])->assertOk()
            ->assertJsonPath('data.skills.0.skill_name', 'React Native');
        $this->postJson('/api/student/profile/skills', ['skill_name' => 'react native'])
            ->assertStatus(422)->assertJsonPath('errors.skill_name.0', 'This skill is already on your profile.');
        $this->postJson('/api/student/profile/skills', ['skill_name' => str_repeat('x', 41)])
            ->assertStatus(422)->assertJsonValidationErrors(['skill_name']);

        for ($i = 2; $i <= 30; $i++) {
            $this->postJson('/api/student/profile/skills', ['skill_name' => "Skill {$i}"])->assertOk();
        }
        $this->postJson('/api/student/profile/skills', ['skill_name' => 'One too many'])
            ->assertStatus(422)->assertJsonPath('errors.skill_name.0', 'You can list up to 30 skills. Remove one to add another.');
        $this->assertSame(30, StudentSkill::where('student_id', $student->student->id)->count());
    }

    public function test_skill_suggestions_group_spellings_and_skip_own_skills(): void
    {
        foreach (['React', 'react', 'React', 'Redis', 'Python'] as $name) {
            StudentSkill::create(['student_id' => $this->makeStudentUser()->student->id, 'skill_name' => $name]);
        }
        $job = $this->makeJobPosting();
        $job->requiredSkills()->create(['skill_name' => 'Redux']);

        $me = $this->makeStudentUser();
        StudentSkill::create(['student_id' => $me->student->id, 'skill_name' => 'Redis']);
        Sanctum::actingAs($me, ['*']);

        $this->getJson('/api/student/profile/skills/suggestions?q=re')->assertOk()
            ->assertExactJson(['data' => ['React', 'Redux']]);
    }

    public function test_report_a_correction_notifies_the_placement_office(): void
    {
        $student = $this->makeStudentUser();
        $admin = $this->makeAdmin();
        $superAdmin = $this->makeSuperAdmin();
        Sanctum::actingAs($student, ['*']);

        $this->postJson('/api/student/profile/corrections', ['field' => 'cgpa', 'note' => ''])->assertStatus(422)->assertJsonValidationErrors(['note']);
        $this->postJson('/api/student/profile/corrections', ['field' => 'password', 'note' => 'Something wrong'])->assertStatus(422)->assertJsonValidationErrors(['field']);

        $this->postJson('/api/student/profile/corrections', ['field' => 'cgpa', 'note' => 'My CGPA is 8.4 after revaluation.'])->assertOk()
            ->assertJsonPath('data.correction_requests.0.field_label', 'CGPA')
            ->assertJsonPath('data.correction_requests.0.status', 'open');

        foreach ([$admin, $superAdmin] as $recipient) {
            $this->assertDatabaseHas('notifications', ['recipient_user_id' => $recipient->id, 'type' => 'CORRECTION_REQUESTED']);
        }
        $this->assertDatabaseMissing('notifications', ['recipient_user_id' => $student->id]);

        $correctionId = $student->student->correctionRequests()->value('id');
        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/admin/students/{$student->student->id}/corrections/{$correctionId}/resolve")->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->getJson("/api/admin/students/{$student->student->id}")->assertOk()->assertJsonPath('data.profile.correction_requests.0.status', 'resolved');
    }

    public function test_directory_filters_by_backlogs_cgpa_section_entry_type_year_and_skill(): void
    {
        $make = fn (string $name, array $attrs) => $this->makeStudentUser(['name' => $name] + $attrs);
        $make('None', ['backlogs' => 0, 'cgpa' => 8.5, 'section' => 'A', 'entry_type' => 'regular', 'year_of_joining' => 2023]);
        $make('Some', ['backlogs' => 2, 'cgpa' => 6.5, 'section' => 'B', 'entry_type' => 'lateral', 'year_of_joining' => 2023]);
        $make('Many', ['backlogs' => 4, 'cgpa' => 5.9, 'section' => 'B', 'entry_type' => 'regular', 'year_of_joining' => 2022]);
        $unknown = $make('Unknown', ['backlogs' => null, 'cgpa' => null, 'section' => 'C', 'entry_type' => 'regular', 'year_of_joining' => 2024]);
        StudentSkill::create(['student_id' => $unknown->student->id, 'skill_name' => 'Kubernetes']);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $names = fn (string $query) => collect($this->getJson("/api/admin/students?{$query}")->assertOk()->json('data.items'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['None'], $names('backlogs=none'));
        $this->assertSame(['Some'], $names('backlogs=some'));
        $this->assertSame(['Many'], $names('backlogs=many'));
        $this->assertSame(['Unknown'], $names('backlogs=not_reported'));
        $this->assertSame(['None', 'Some'], $names('cgpa_min=6&cgpa_max=9')); // never matches a missing CGPA
        $this->assertSame(['Many', 'Some'], $names('section=b'));
        $this->assertSame(['Some'], $names('entry_type=lateral'));
        $this->assertSame(['Many'], $names('year_of_joining=2022'));
        $this->assertSame(['Unknown'], $names('skill=kube'));

        $this->getJson('/api/admin/students?cgpa_min=8&cgpa_max=7')->assertStatus(422);
        $this->assertSame(['Many', 'Some'], $names('cgpa_max=7'));
        $row = collect($this->getJson('/api/admin/students?backlogs=some')->json('data.items'))->first();
        $this->assertEquals(['cgpa' => 6.5, 'backlogs' => 2, 'section' => 'B', 'entry_type' => 'lateral'], array_intersect_key($row, array_flip(['cgpa', 'backlogs', 'section', 'entry_type'])));
    }

    public function test_faculty_see_an_assigned_students_profile_without_the_personal_record(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();
        $student = $fixture['student']->student;
        $student->update(['phone' => '9876500001', 'gender' => 'female', 'linkedin_url' => 'https://linkedin.com/in/x']);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->getJson("/api/faculty/students/{$student->id}")->assertOk()
            ->assertJsonPath('data.roll_number', $student->roll_number)
            ->assertJsonPath('data.cgpa', 8)
            ->assertJsonPath('data.linkedin_url', 'https://linkedin.com/in/x')
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.gender', null);

        $other = $this->makeStudentUser();
        $this->getJson("/api/faculty/students/{$other->student->id}")->assertForbidden();
    }
}
