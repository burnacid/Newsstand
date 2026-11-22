<?php

namespace App\Services\Teams;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Team membership rules in one place:
 *   - a team always has exactly one owner;
 *   - owners and admins manage members, shared folders, the watchlist and channels;
 *   - only the owner makes or removes admins, hands over ownership or deletes the team.
 */
class TeamManager
{
    /** How long a new invite link works, in days. */
    public const INVITE_DAYS = 7;

    public function create(User $owner, string $name): Team
    {
        return DB::transaction(function () use ($owner, $name) {
            $team = new Team(['name' => trim($name)]);
            $team->created_by = $owner->id;
            $team->save();
            $team->members()->attach($owner->id, ['role' => 'owner']);

            return $team;
        });
    }

    /** Add (or re-role) a member. Owners are only made through transferOwnership(). */
    public function addMember(Team $team, User $user, string $role = 'member'): void
    {
        if (! in_array($role, ['admin', 'member'], true)) {
            throw new InvalidArgumentException("Invalid team role [{$role}].");
        }

        if ($team->roleOf($user) === 'owner') {
            return;
        }

        $team->members()->syncWithoutDetaching([$user->id => ['role' => $role]]);
        $team->members()->updateExistingPivot($user->id, ['role' => $role]);
    }

    /** Whether $actor may change $target's role or remove them. */
    public function canManageMember(Team $team, User $actor, User $target): bool
    {
        $actorRole = $team->roleOf($actor);
        $targetRole = $team->roleOf($target);

        if ($targetRole === null || $targetRole === 'owner' || $actor->is($target)) {
            return false;
        }

        // Admins manage members; only the owner manages admins.
        return $actorRole === 'owner' || ($actorRole === 'admin' && $targetRole === 'member');
    }

    public function changeRole(Team $team, User $actor, User $target, string $role): bool
    {
        if (! in_array($role, ['admin', 'member'], true) || ! $this->canManageMember($team, $actor, $target)) {
            return false;
        }

        // Only the owner may promote to admin.
        if ($role === 'admin' && $team->roleOf($actor) !== 'owner') {
            return false;
        }

        $team->members()->updateExistingPivot($target->id, ['role' => $role]);

        return true;
    }

    public function removeMember(Team $team, User $actor, User $target): bool
    {
        if (! $this->canManageMember($team, $actor, $target)) {
            return false;
        }

        $this->detach($team, $target);

        return true;
    }

    /** Leave a team. The owner can't leave: hand over ownership or delete the team first. */
    public function leave(Team $team, User $user): bool
    {
        if ($team->roleOf($user) === null || $team->roleOf($user) === 'owner') {
            return false;
        }

        $this->detach($team, $user);

        return true;
    }

    public function transferOwnership(Team $team, User $owner, User $newOwner): bool
    {
        if ($team->roleOf($owner) !== 'owner' || $team->roleOf($newOwner) === null || $owner->is($newOwner)) {
            return false;
        }

        DB::transaction(function () use ($team, $owner, $newOwner) {
            $team->members()->updateExistingPivot($newOwner->id, ['role' => 'owner']);
            $team->members()->updateExistingPivot($owner->id, ['role' => 'admin']);
        });

        return true;
    }

    public function invite(Team $team, User $by, string $role = 'member'): TeamInvitation
    {
        // Admins can invite members; only the owner can hand out admin links.
        if ($role !== 'member' && $team->roleOf($by) !== 'owner') {
            $role = 'member';
        }

        return $team->invitations()->create([
            'token' => Str::random(40),
            'role' => $role,
            'expires_at' => now()->addDays(self::INVITE_DAYS),
            'created_by' => $by->id,
        ]);
    }

    /** Join through an invite link; returns the team, or null when the link is invalid or expired. */
    public function accept(string $token, User $user): ?Team
    {
        $invitation = TeamInvitation::with('team')->where('token', $token)->first();

        if (! $invitation || $invitation->isExpired()) {
            return null;
        }

        $team = $invitation->team;

        // Already a member: keep the current role (never downgrade an admin through a member link).
        if (! $team->isMember($user)) {
            $this->addMember($team, $user, $invitation->role === 'admin' ? 'admin' : 'member');
        }

        return $team;
    }

    /**
     * Notes a former member shared with the team stay with the team (they're part of
     * its shared knowledge); the member loses access to everything team-owned.
     */
    private function detach(Team $team, User $user): void
    {
        $team->members()->detach($user->id);

        // Team dashboards they were given access to directly stay; team access ends here.
    }
}
