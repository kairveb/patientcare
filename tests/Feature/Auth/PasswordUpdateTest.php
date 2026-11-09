<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'NewPass123!',
                'password_confirmation' => 'NewPass123!',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('NewPass123!', $user->refresh()->password));
    }

    public function test_password_update_displays_a_visible_success_message(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->followingRedirects()
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'NewPass123!',
                'password_confirmation' => 'NewPass123!',
            ])
            ->assertOk()
            ->assertSee('Password updated successfully.')
            ->assertSee('role="status"', false);
    }

    public function test_profile_password_fields_render_show_hide_controls(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/profile');

        $response->assertOk()
            ->assertSee('update_password_current_password')
            ->assertSee('update_password_password')
            ->assertSee('update_password_password_confirmation')
            ->assertSee('data-password-toggle', false);
        $this->assertSame(4, substr_count($response->getContent(), 'data-password-toggle'));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'NewPass123!',
                'password_confirmation' => 'NewPass123!',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');
    }
}
