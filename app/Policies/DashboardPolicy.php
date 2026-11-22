<?php

namespace App\Policies;

use App\Models\Dashboard;
use App\Models\User;

/**
 * owner:  everything
 * editor: view and change widgets
 * viewer: view only
 */
class DashboardPolicy
{
    public function view(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->roleOf($user) !== null;
    }

    public function update(User $user, Dashboard $dashboard): bool
    {
        return in_array($dashboard->roleOf($user), ['owner', 'editor'], true);
    }

    /** Rename, delete, manage members and the public link. */
    public function manage(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->roleOf($user) === 'owner';
    }
}
