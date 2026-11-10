<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Models\Ward;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUserAndBedSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
        Notification::fake();
    }

    public function test_super_admin_can_create_a_user_with_one_of_the_five_roles(): void
    {
        $admin = $this->superAdmin();
        $email = 'new-patient-'.Str::uuid().'@example.test';

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.create'))->assertOk();
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Test Patient',
            'email' => $email,
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role' => 'patient',
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-01-15',
            'sex' => 'Female',
        ])->assertRedirect(route('admin.users.index'));

        $user = User::where('email', $email)->firstOrFail();
        $this->assertSame(['patient'], $user->roles()->pluck('name')->all());
        $this->assertTrue($user->is_active);
        $this->assertSame($user->id, Patient::where('email', $email)->value('user_id'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ADMIN_CREATE_USER',
            'resource_id' => $user->id,
        ]);
    }

    public function test_user_creation_validates_role_and_patient_data_and_forbids_non_admins(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Invalid Role User',
            'email' => 'invalid-'.Str::uuid().'@example.test',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role' => 'billing',
        ])->assertSessionHasErrors('role');
    }

    public function test_patient_user_creation_requires_patient_demographics(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.users.store'), [
            'name' => 'Missing Patient Details',
            'email' => 'missing-'.Str::uuid().'@example.test',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role' => 'patient',
        ])->assertSessionHasErrors(['first_name', 'last_name', 'date_of_birth', 'sex']);
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $nurse = $this->userWithRole('nurse');
        $this->assertTrue($nurse->is_active);
        $this->actingAs($nurse)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_admin_can_change_roles_but_cannot_change_own_role_or_deactivate_self(): void
    {
        $admin = $this->superAdmin();
        $target = $this->userWithRole('nurse');

        $this->actingAs($admin)->patch(route('admin.users.role', $target), [
            'role' => 'doctor',
        ])->assertRedirect();
        $this->assertSame(['doctor'], $target->fresh()->roles()->pluck('name')->all());

        $this->actingAs($admin)->patch(route('admin.users.role', $target), [
            'role' => 'patient',
            'first_name' => 'Nurse',
            'last_name' => 'Converted',
            'date_of_birth' => '1991-02-03',
            'sex' => 'Female',
        ])->assertRedirect();
        $this->assertSame(['patient'], $target->fresh()->roles()->pluck('name')->all());
        $this->assertSame($target->id, Patient::where('email', $target->email)->value('user_id'));

        $this->actingAs($admin)->patch(route('admin.users.role', $admin), [
            'role' => 'nurse',
        ])->assertSessionHasErrors('role');
        $this->actingAs($admin)->patch(route('admin.users.status', $admin), [
            'is_active' => false,
        ])->assertSessionHasErrors('is_active');
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->hasRole('super-admin'));
    }

    public function test_patient_role_assignment_requires_demographics_for_unlinked_user(): void
    {
        $target = $this->userWithRole('doctor');

        $this->actingAs($this->superAdmin())->patch(route('admin.users.role', $target), [
            'role' => 'patient',
        ])->assertSessionHasErrors(['first_name', 'last_name', 'date_of_birth', 'sex']);

        $this->assertFalse($target->fresh()->hasRole('patient'));
        $this->assertDatabaseMissing('patients', ['user_id' => $target->id]);
    }

    public function test_deactivation_revokes_api_tokens_and_blocks_web_and_api_login(): void
    {
        $admin = $this->superAdmin();
        $target = $this->userWithRole('nurse', ['password' => Hash::make('StrongPass123!')]);
        $plainTextToken = $target->createToken('test-token')->plainTextToken;

        $this->actingAs($admin)->patch(route('admin.users.status', $target), [
            'is_active' => '0',
        ])->assertRedirect();

        $target->refresh();
        $this->assertFalse($target->is_active);
        $this->assertSame(0, $target->tokens()->count());
        $this->post(route('logout'))->assertRedirect();
        $this->post('/login', [
            'email' => $target->email,
            'password' => 'StrongPass123!',
        ])->assertSessionHasErrors('email');
        $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => 'StrongPass123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->withToken($plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->actingAs($target->fresh())->get('/dashboard')->assertRedirect(route('login'));
        $this->actingAs($target->fresh(), 'sanctum')->getJson('/api/v1/auth/me')->assertForbidden();
        $this->actingAs($admin)->patch(route('admin.users.status', $target), [
            'is_active' => '1',
        ])->assertRedirect();
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_super_admin_can_create_update_and_delete_unused_bed_setup_records(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get(route('admin.bed-setup.index'))->assertOk();

        $this->post(route('admin.bed-setup.wards.store'), [
            'code' => 'QA-'.Str::upper(Str::random(6)),
            'name' => 'QA Ward '.Str::upper(Str::random(4)),
            'type' => 'General',
        ])->assertRedirect();
        $ward = Ward::query()->latest('id')->firstOrFail();
        $originalCode = $ward->code;

        $this->patch(route('admin.bed-setup.wards.update', $ward), [
            'code' => $originalCode,
            'name' => 'Updated '.$ward->name,
            'type' => 'Semi-private',
            'active' => '1',
        ])->assertRedirect();

        $this->post(route('admin.bed-setup.rooms.store'), [
            'ward_id' => $ward->id,
            'number' => 'QA-ROOM-1',
            'type' => 'Private',
        ])->assertRedirect();
        $room = Room::query()->where('ward_id', $ward->id)->firstOrFail();

        $this->post(route('admin.bed-setup.beds.store'), [
            'room_id' => $room->id,
            'number' => 'QA-BED-1',
            'status' => Bed::STATUS_OCCUPIED,
        ])->assertRedirect();
        $bed = Bed::query()->where('room_id', $room->id)->firstOrFail();
        $this->assertSame(Bed::STATUS_AVAILABLE, $bed->status);

        $this->patch(route('admin.bed-setup.rooms.update', $room), [
            'ward_id' => $ward->id,
            'number' => 'QA-ROOM-2',
            'type' => 'Semi-private',
        ])->assertRedirect();
        $this->patch(route('admin.bed-setup.beds.update', $bed), [
            'room_id' => $room->id,
            'number' => 'QA-BED-2',
        ])->assertRedirect();
        $this->assertSame(Bed::STATUS_AVAILABLE, $bed->fresh()->status);

        $this->delete(route('admin.bed-setup.beds.destroy', $bed))->assertRedirect();
        $this->delete(route('admin.bed-setup.rooms.destroy', $room))->assertRedirect();
        $this->delete(route('admin.bed-setup.wards.destroy', $ward))->assertRedirect();
        $this->assertDatabaseMissing('wards', ['id' => $ward->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CREATE_WARD']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'DELETE_BED']);
    }

    public function test_bed_setup_validates_duplicates_and_preserves_nonempty_hierarchy(): void
    {
        $admin = $this->superAdmin();
        $ward = Ward::query()->firstOrFail();
        $room = Room::query()->where('ward_id', $ward->id)->firstOrFail();
        $bed = Bed::query()->where('room_id', $room->id)->firstOrFail();

        $this->actingAs($admin)->post(route('admin.bed-setup.wards.store'), [
            'code' => $ward->code,
            'name' => 'Another Ward '.Str::uuid(),
        ])->assertSessionHasErrors('code');

        $this->delete(route('admin.bed-setup.wards.destroy', $ward))
            ->assertSessionHasErrors('ward');

        $this->delete(route('admin.bed-setup.rooms.destroy', $room))
            ->assertSessionHasErrors('room');

        $bed->update(['status' => Bed::STATUS_OCCUPIED]);
        $destinationRoom = Room::query()->create([
            'ward_id' => $ward->id,
            'number' => 'QA-DESTINATION-'.Str::upper(Str::random(4)),
        ]);
        $this->patch(route('admin.bed-setup.beds.update', $bed), [
            'room_id' => $destinationRoom->id,
            'number' => $bed->number,
        ])->assertSessionHasErrors('room_id');
        $this->delete(route('admin.bed-setup.beds.destroy', $bed))
            ->assertSessionHasErrors('bed');
        $this->assertDatabaseHas('beds', ['id' => $bed->id]);
    }

    public function test_bed_setup_renders_consistently_aligned_ward_room_and_bed_actions(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.bed-setup.index'))
            ->assertOk()
            ->assertSee('md:grid-cols-5')
            ->assertSee('md:grid-cols-4')
            ->assertSee('type="checkbox" name="active"', false)
            ->assertSee('Save ward')
            ->assertSee('Save room')
            ->assertSee('Delete');
    }

    public function test_nurse_cannot_access_or_change_bed_setup(): void
    {
        $nurse = $this->userWithRole('nurse');
        $this->actingAs($nurse)->get(route('admin.bed-setup.index'))->assertForbidden();
        $this->actingAs($nurse)->post(route('admin.bed-setup.wards.store'), [
            'code' => 'NOPE',
            'name' => 'Unauthorized Ward',
        ])->assertForbidden();
    }

    private function superAdmin(): User
    {
        return User::query()->where('email', 'super-admin@coor.test')->firstOrFail();
    }

    private function userWithRole(string $roleName, array $attributes = []): User
    {
        $user = User::factory()->create([
            'email' => $roleName.'-'.Str::uuid().'@example.test',
            ...$attributes,
        ]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
