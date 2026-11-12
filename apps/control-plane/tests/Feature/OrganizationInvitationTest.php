<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Organizations\Actions\AcceptInvitation;
use App\Domains\Organizations\Actions\InviteMember;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Organizations\Models\Invitation;
use App\Domains\Organizations\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationInvitationTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_owner_can_invite_a_member_and_the_token_is_stored_hashed(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        $response = $this->actingIn($organization, $owner)->post(route('members.invitations.store'), [
            'email' => 'Engineer@Example.com',
            'role' => OrganizationRole::Developer->value,
        ]);

        $response->assertRedirect()->assertSessionHas('invitation_url');

        $invitation = Invitation::query()->sole();

        $this->assertSame('engineer@example.com', $invitation->email);
        $this->assertSame(OrganizationRole::Developer, $invitation->role);
        $this->assertSame($owner->id, $invitation->invited_by_user_id);
        $this->assertNull($invitation->accepted_at);
        $this->assertTrue($invitation->expires_at->between(now()->addDays(6), now()->addDays(8)));

        parse_str((string) parse_url($response->getSession()->get('invitation_url'), PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('invitation', $query);
        $this->assertSame(Invitation::hashToken($query['invitation']), $invitation->token_hash);
        $this->assertNotSame($query['invitation'], $invitation->token_hash);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::MemberInvited->value,
            'organization_id' => $organization->id,
            'actor_id' => $owner->id,
        ]);
    }

    public function test_admin_can_invite_assignable_roles_but_not_admins_or_owners(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $admin = $this->memberWithRole($organization, OrganizationRole::Admin);

        $this->actingIn($organization, $admin)
            ->post(route('members.invitations.store'), [
                'email' => 'developer@example.com',
                'role' => OrganizationRole::Developer->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('organization_invitations', [
            'organization_id' => $organization->id,
            'email' => 'developer@example.com',
            'role' => OrganizationRole::Developer->value,
        ]);

        foreach ([OrganizationRole::Admin, OrganizationRole::Owner] as $role) {
            $this->actingIn($organization, $admin)
                ->post(route('members.invitations.store'), [
                    'email' => "{$role->value}@example.com",
                    'role' => $role->value,
                ])
                ->assertSessionHasErrors('role');
        }

        $this->assertSame(1, Invitation::query()->count());
    }

    public function test_developers_operators_and_viewers_cannot_invite_members(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        foreach ([OrganizationRole::Developer, OrganizationRole::Operator, OrganizationRole::Viewer] as $role) {
            $member = $this->memberWithRole($organization, $role);

            $this->actingIn($organization, $member)
                ->post(route('members.invitations.store'), [
                    'email' => 'someone@example.com',
                    'role' => OrganizationRole::Viewer->value,
                ])
                ->assertForbidden();
        }

        $this->assertSame(0, Invitation::query()->count());
    }

    public function test_existing_members_cannot_be_invited_again(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $viewer = $this->memberWithRole($organization, OrganizationRole::Viewer);

        $this->actingIn($organization, $owner)
            ->post(route('members.invitations.store'), [
                'email' => $viewer->email,
                'role' => OrganizationRole::Developer->value,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Invitation::query()->count());
    }

    public function test_reinviting_the_same_email_replaces_the_pending_invitation(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        $first = app(InviteMember::class)->execute(
            $organization,
            $owner,
            'engineer@example.com',
            OrganizationRole::Developer,
        );

        $second = app(InviteMember::class)->execute(
            $organization,
            $owner,
            'ENGINEER@example.com',
            OrganizationRole::Admin,
        );

        $this->assertSame(1, Invitation::query()->count());
        $this->assertSame(OrganizationRole::Admin, $second['invitation']->role);
        $this->assertNull(app(AcceptInvitation::class)->pending($first['token']));
        $this->assertNotNull(app(AcceptInvitation::class)->pending($second['token']));
    }

    public function test_expired_invitations_cannot_be_accepted(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        $result = app(InviteMember::class)->execute(
            $organization,
            $owner,
            'late@example.com',
            OrganizationRole::Viewer,
        );

        $result['invitation']->forceFill(['expires_at' => now()->subMinute()])->save();

        $user = User::factory()->create(['email' => 'late@example.com']);

        $this->assertNull(app(AcceptInvitation::class)->pending($result['token']));

        try {
            app(AcceptInvitation::class)->execute($result['token'], $user);
            $this->fail('An expired invitation was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'This invitation has expired.',
                $exception->errors()['token'][0] ?? null,
            );
        }

        $this->assertDatabaseMissing('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_invitations_cannot_be_accepted_by_a_different_email_address(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        $result = app(InviteMember::class)->execute(
            $organization,
            $owner,
            'intended@example.com',
            OrganizationRole::Developer,
        );

        $intruder = User::factory()->create(['email' => 'intruder@example.com']);

        $this->expectException(ValidationException::class);

        try {
            app(AcceptInvitation::class)->execute($result['token'], $intruder);
        } finally {
            $this->assertNull($result['invitation']->refresh()->accepted_at);
            $this->assertDatabaseMissing('organization_members', [
                'organization_id' => $organization->id,
                'user_id' => $intruder->id,
            ]);
        }
    }

    public function test_invitations_cannot_be_accepted_twice(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $invited = User::factory()->create(['email' => 'member@example.com']);

        $result = app(InviteMember::class)->execute(
            $organization,
            $owner,
            'member@example.com',
            OrganizationRole::Operator,
        );

        app(AcceptInvitation::class)->execute($result['token'], $invited);

        $this->assertNotNull($result['invitation']->refresh()->accepted_at);
        $this->assertSame($invited->id, $result['invitation']->accepted_by_user_id);
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $invited->id,
            'role' => OrganizationRole::Operator->value,
        ]);

        $this->expectException(ValidationException::class);

        app(AcceptInvitation::class)->execute($result['token'], $invited);
    }

    public function test_owner_cannot_be_demoted(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $admin = $this->memberWithRole($organization, OrganizationRole::Admin);

        $ownerMembership = $organization->memberships()->where('user_id', $owner->id)->sole();

        $this->actingIn($organization, $admin)
            ->patch(route('members.update', $ownerMembership), [
                'role' => OrganizationRole::Viewer->value,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(OrganizationRole::Owner, $ownerMembership->refresh()->role);
    }

    public function test_members_cannot_change_their_own_role(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $admin = $this->memberWithRole($organization, OrganizationRole::Admin);

        $membership = $organization->memberships()->where('user_id', $admin->id)->sole();

        $this->actingIn($organization, $admin)
            ->patch(route('members.update', $membership), [
                'role' => OrganizationRole::Viewer->value,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(OrganizationRole::Admin, $membership->refresh()->role);
    }

    public function test_admin_cannot_assign_a_role_they_cannot_grant(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $admin = $this->memberWithRole($organization, OrganizationRole::Admin);
        $viewer = $this->memberWithRole($organization, OrganizationRole::Viewer);

        $membership = $organization->memberships()->where('user_id', $viewer->id)->sole();

        $this->actingIn($organization, $admin)
            ->patch(route('members.update', $membership), [
                'role' => OrganizationRole::Admin->value,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(OrganizationRole::Viewer, $membership->refresh()->role);
    }

    public function test_owner_can_change_a_member_role_and_the_change_is_audited(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $viewer = $this->memberWithRole($organization, OrganizationRole::Viewer);

        $membership = $organization->memberships()->where('user_id', $viewer->id)->sole();

        $this->actingIn($organization, $owner)
            ->patch(route('members.update', $membership), [
                'role' => OrganizationRole::Developer->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(OrganizationRole::Developer, $membership->refresh()->role);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::MemberRoleChanged->value,
            'organization_id' => $organization->id,
            'actor_id' => $owner->id,
            'resource_id' => $membership->id,
        ]);
    }

    public function test_owner_cannot_be_removed_from_the_organization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $admin = $this->memberWithRole($organization, OrganizationRole::Admin);

        $ownerMembership = $organization->memberships()->where('user_id', $owner->id)->sole();

        $this->actingIn($organization, $admin)
            ->delete(route('members.destroy', $ownerMembership))
            ->assertSessionHasErrors('member');

        $this->assertDatabaseHas('organization_members', ['id' => $ownerMembership->id]);
    }

    public function test_members_cannot_remove_themselves(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $admin = $this->memberWithRole($organization, OrganizationRole::Admin);

        $membership = $organization->memberships()->where('user_id', $admin->id)->sole();

        $this->actingIn($organization, $admin)
            ->delete(route('members.destroy', $membership))
            ->assertSessionHasErrors('member');

        $this->assertDatabaseHas('organization_members', ['id' => $membership->id]);
    }

    public function test_admin_can_remove_another_member_and_the_removal_is_audited(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $admin = $this->memberWithRole($organization, OrganizationRole::Admin);
        $developer = $this->memberWithRole($organization, OrganizationRole::Developer);

        $membership = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $developer->id)
            ->sole();

        $this->actingIn($organization, $admin)
            ->delete(route('members.destroy', $membership))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('organization_members', ['id' => $membership->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::MemberRemoved->value,
            'organization_id' => $organization->id,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_invitations_cannot_be_revoked_across_organizations(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        $invitation = app(InviteMember::class)->execute(
            $otherOrganization,
            $otherOrganization->owner,
            'other@example.com',
            OrganizationRole::Viewer,
        )['invitation'];

        $this->actingIn($organization, $owner)
            ->delete(route('members.invitations.destroy', $invitation))
            ->assertNotFound();

        $this->assertDatabaseHas('organization_invitations', ['id' => $invitation->id]);
    }

    public function test_owner_can_revoke_a_pending_invitation(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        $invitation = app(InviteMember::class)->execute(
            $organization,
            $owner,
            'pending@example.com',
            OrganizationRole::Viewer,
        )['invitation'];

        $this->actingIn($organization, $owner)
            ->delete(route('members.invitations.destroy', $invitation))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('organization_invitations', ['id' => $invitation->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::InvitationRevoked->value,
            'organization_id' => $organization->id,
            'actor_id' => $owner->id,
        ]);
    }
}
