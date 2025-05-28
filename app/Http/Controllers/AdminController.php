<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Group;
use App\Models\Invite;

class AdminController extends Controller
{
    // GET /api/admin/stats and /api/dashboard/stats
    public function getDashboardStats()
    {
        $totalUsers = User::count();
        $pendingVerifications = User::where('status', 'pending')->count();
        $activeUsers = User::where('status', 'active')->count();
        $totalGroups = Group::count();
        return response()->json([
            'totalUsers' => $totalUsers,
            'pendingVerifications' => $pendingVerifications,
            'activeUsers' => $activeUsers,
            'totalGroups' => $totalGroups,
        ]);
    }

    // GET /api/admin/logs
    public function getRecentAdminLogs()
    {
        // User logs
        $users = User::whereIn('status', ['active', 'rejected'])
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();
        $userLogs = $users->map(function ($u) {
            return [
                'timestamp' => $u->created_at,
                'message' => $u->status === 'active'
                    ? 'User ' . ($u->name ?? '') . ' (' . ($u->email ?? '') . ') was verified.'
                    : 'User ' . ($u->name ?? '') . ' (' . ($u->email ?? '') . ') was rejected.',
                'level' => $u->status === 'active' ? 'info' : 'warning',
            ];
        });
        // Group logs
        $groups = Group::orderByDesc('created_at')->limit(10)->get();
        $groupLogs = $groups->map(function ($g) {
            return [
                'timestamp' => $g->created_at,
                'message' => 'Group #' . $g->group_number . ' (code: ' . $g->code . ') was created.',
                'level' => 'info',
            ];
        });
        // Combine and sort
        $logs = $userLogs->concat($groupLogs)->sortByDesc('timestamp')->values();
        return response()->json($logs);
    }

    // GET /api/admin/missing-next-group
    public function findUsersMissingNextGroup()
    {
        $users = User::where('status', 'active')->get();
        $eligibleUsers = [];
        foreach ($users as $user) {
            $groups = $user->groups()->orderBy('group_number')->get();
            if ($groups->isEmpty()) continue;
            $lastGroup = $groups->last();
            $nextGroupNumber = $lastGroup->group_number + 1;
            if ($groups->count() >= 3) continue;
            if ($groups->contains('group_number', $nextGroupNumber)) continue;
            // Count verified & owner_confirmed members in last group
            $verifiedCount = Invite::where('group_id', $lastGroup->id)
                ->where('owner_confirmed', true)
                ->whereHas('referredUser', function($q) {
                    $q->where('status', 'active');
                })->count();
            if ($verifiedCount >= 4) {
                $eligibleUsers[] = [
                    'userId' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'lastGroupNumber' => $lastGroup->group_number,
                    'verifiedCount' => $verifiedCount,
                ];
            }
        }
        return response()->json($eligibleUsers);
    }
}
