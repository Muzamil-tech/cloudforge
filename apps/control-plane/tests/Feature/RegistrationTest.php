<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Organizations\Actions\InviteMember;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Organizations\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_guest_is_redirected_to_login_from_the_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_registration_page_is_open_when_the_platform_has_no_users(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Register')
                ->where('bootstrap', true)
                ->where('invitation', null));
    }

    public function test_first_user_can_register_and_is_sent_to_onboarding(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ADA@example.com',
            'password' => 'CloudForge-2026',
            'password_confirmation' => 'CloudForge-2026',
        ]);

        $response->assertRedirect(route('onboarding'));
        $this->assertAuthenticated();

        $user = User::query()->sole();
        $this->assertSame('ada@example.com', $user->email);
        $this->assertTrue(password_verify('CloudForge-2026', $user->password));

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::UserRegistered->value,
            'actor_id' => $user->id,
            'organization_id' => null,
        ]);
    }

    public function test_registration_is_closed_when_disabled_and_users_exist(): void
    {
        User::factory()->create();
        config(['cloudforge.registration.enabled' => false]);

        $this->get('/register')->assertForbidden();

        $this->post('/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'CloudForge-2026',
            'password_confirmation' => 'CloudForge-2026',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'mallory@example.com']);
    }

    public function test_invited_user_can_register_and_joins_the_organization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        $invitation = app(InviteMember::class)->execute(
            $organization,
            $owner,
            'new-engineer@example.com',
            OrganizationRole::Developer,
        );

        $this->get('/register?invitation='.$invitation['token'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Register')
                ->where('invitationValid', true)
                ->where('invitation.email', 'new-engineer@example.com')
                ->where('bootstrap', false));

        $response = $this->post('/register', [
            'name' => 'New Engineer',
            'email' => 'new-engineer@example.com',
            'password' => 'CloudForge-2026',
            'password_confirmation' => 'CloudForge-2026',
            'invitation' => $invitation['token'],
        ]);

        $response->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'new-engineer@example.com')->sole();

        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => OrganizationRole::Developer->value,
            'status' => MembershipStatus::Active->value,
        ]);

        $this->assertNotNull($invitation['invitation']->refresh()->accepted_at);
        $this->assertSame($user->id, $invitation['invitation']->accepted_by_user_id);
    }

    public function test_registration_with_a_different_email_than_the_invitation_is_rejected(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        $invitation = app(InviteMember::class)->execute(
            $organization,
            $owner,
            'invited@example.com',
            OrganizationRole::Viewer,
        );

        $this->post('/register', [
            'name' => 'Impostor',
            'email' => 'someone-else@example.com',
            'password' => 'CloudForge-2026',
            'password_confirmation' => 'CloudForge-2026',
            'invitation' => $invitation['token'],
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'someone-else@example.com']);
        $this->assertNull($invitation['invitation']->refresh()->accepted_at);
    }

    public function test_onboarding_creates_an_organization_with_an_active_owner_membership(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('onboarding'));

        $response = $this->actingAs($user)->post('/onboarding', [
            'name' => 'Acme Cloud',
        ]);

        $response->assertRedirect(route('dashboard'));

        $organization = Organization::query()->sole();

        $this->assertSame('Acme Cloud', $organization->name);
        $this->assertSame($user->id, $organization->owner_user_id);
        $this->assertDatabaseHas('organizations', ['slug' => 'acme-cloud']);

        $membership = Membership::query()->sole();
        $this->assertSame($user->id, $membership->user_id);
        $this->assertSame(OrganizationRole::Owner, $membership->role);
        $this->assertSame(MembershipStatus::Active, $membership->status);
    }
}
