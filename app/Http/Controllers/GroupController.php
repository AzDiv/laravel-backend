<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function index()
    {
        $groups = Group::with('owner', 'invites')->get();
        return response()->json(['success' => true, 'groups' => $groups]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'owner_id' => 'required|integer|exists:users,id',
            'code' => 'required|string',
            'group_number' => 'required|integer',
        ]);

        $group = Group::create($validated);

        return response()->json(['success' => true, 'group' => $group]);
    }

    public function show($id)
    {
        $group = Group::with('owner', 'invites')->find($id);
        if (!$group) {
            return response()->json(['success' => false, 'error' => 'Group not found'], 404);
        }
        return response()->json(['success' => true, 'group' => $group]);
    }

    public function update(Request $request, $id)
    {
        $group = Group::find($id);
        if (!$group) {
            return response()->json(['success' => false, 'error' => 'Group not found'], 404);
        }
        $validated = $request->validate([
            'owner_id' => 'required|integer|exists:users,id',
            'code' => 'required|string',
            'group_number' => 'required|integer',
        ]);

        $group->update($validated);

        return response()->json(['success' => true, 'group' => $group]);
    }

    public function destroy($id)
    {
        $group = Group::find($id);
        if (!$group) {
            return response()->json(['success' => false, 'error' => 'Group not found'], 404);
        }
        $group->delete();

        return response()->json(['success' => true]);
    }

    // Create a group for a user if needed (migrated from Node.js logic)
    public function createIfNeeded($userId)
    {
        $user = \App\Models\User::find($userId);
        if (!$user || $user->status !== 'active') {
            return response()->json(['success' => false, 'error' => 'User not found or not active'], 404);
        }
        $existingGroup = \App\Models\Group::where('owner_id', $userId)->first();
        if ($existingGroup) {
            return response()->json(['success' => false, 'error' => 'User already has a group'], 400);
        }
        $group = \App\Models\Group::create([
            'owner_id' => $userId,
            'code' => $this->generateGroupCode(),
            'group_number' => 1
        ]);
        return response()->json(['success' => true, 'group' => $group]);
    }

    /**
     * Create next group if user is eligible
     */
    public function createNextGroupIfEligible(Request $request)
    {
        $userId = $request->input('userId');
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'userId is required'], 400);
        }
        
        \Log::info('Creating next group for user: ' . $userId);
        
        try {
            // Delegate to the existing createNext method
            return $this->createNext($userId);
        } catch (\Exception $e) {
            \Log::error('Error creating next group: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // Create the next group for a user if eligible (migrated from Node.js logic)
    public function createNext($userId)
    {
        // At the start of the method
        \Log::info("Starting createNext for userId: $userId");
        
        $user = \App\Models\User::find($userId);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'User not found'], 404);
        }
        $groups = \App\Models\Group::where('owner_id', $userId)->orderBy('group_number', 'asc')->get();
        if ($groups->isEmpty()) {
            return response()->json(['success' => false, 'error' => 'No existing groups found'], 404);
        }
        if ($groups->count() >= 3) {
            return response()->json(['success' => false, 'error' => 'Maximum number of groups reached'], 400);
        }
        $lastGroup = $groups->last();
        $nextGroupNumber = $lastGroup->group_number + 1;
        // Check if next group already exists
        if ($groups->contains('group_number', $nextGroupNumber)) {
            return response()->json(['success' => false, 'error' => 'Next group already exists'], 400);
        }
        // Count verified & owner_confirmed members in last group
        $verifiedCount = \App\Models\Invite::where('group_id', $lastGroup->id)
            ->where('owner_confirmed', true)
            ->whereHas('referredUser', function($q) {
                $q->where('status', 'active');
            })->count();
        if ($verifiedCount < 4) {
            \Log::info("Not enough verified members. Only have: $verifiedCount");
            return response()->json(['success' => false, 'error' => 'Not enough verified members to create next group'], 400);
        }
        // Only create next group if user is confirmed as member in a group at this level
        $userInvites = \App\Models\Invite::where('referred_user_id', $userId)
            ->where('owner_confirmed', true)
            ->get();
        $confirmedAtLevel = false;
        foreach ($userInvites as $invite) {
            $group = \App\Models\Group::find($invite->group_id);
            if ($group && $group->group_number === $nextGroupNumber) {
                $confirmedAtLevel = true;
                break;
            }
        }
        if (!$confirmedAtLevel) {
            return response()->json(['success' => false, 'error' => 'User not confirmed at this level'], 400);
        }
        $group = \App\Models\Group::create([
            'owner_id' => $userId,
            'code' => $this->generateGroupCode(),
            'group_number' => $nextGroupNumber
        ]);
        // Update user's current_level if needed
        if ($user->current_level == $lastGroup->group_number) {
            $user->current_level = $nextGroupNumber;
            $user->save();
        }
        return response()->json(['success' => true, 'group' => $group]);
    }

    // Confirm a group member (migrated from Node.js logic)
    public function confirmGroupMember(Request $request)
    {
        $inviteId = $request->input('inviteId');
        if (!$inviteId) {
            return response()->json(['success' => false, 'error' => 'inviteId is required'], 400);
        }
        
        \Log::info('Confirming member: ' . $inviteId);
        
        $invite = \App\Models\Invite::find($inviteId);
        if (!$invite) {
            return response()->json(['success' => false, 'error' => 'Invite not found'], 404);
        }
        
        $invite->owner_confirmed = true;
        $invite->save();
        
        // Level auto-increment: check and increment group owner's level after confirmation
        $group = \App\Models\Group::find($invite->group_id);
        if ($group && $group->owner_id) {
            app(\App\Http\Controllers\UserController::class)->checkAndIncrementUserLevel($group->owner_id);
        }
        
        // Get group to determine if we should trigger next group creation
        $group = \App\Models\Group::find($invite->group_id);
        if (!$group) {
            return response()->json(['success' => false, 'error' => 'Group not found'], 404);
        }
        if ($group->group_number > 1) {
            $this->createNext($invite->referred_user_id);
        } else if ($group->group_number === 1) {
            $this->createIfNeeded($invite->referred_user_id);
        }
        
        return response()->json(['success' => true]);
    }

    // Helper to generate a group code
    private function generateGroupCode()
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $code;
    }

    public function getMembers($groupId)
    {
        $group = Group::find($groupId);

        if (!$group) {
            return response()->json(['success' => false, 'error' => 'Group not found'], 404);
        }

        $invites = $group->invites()->with('referredUser')->get();
        $members = $invites->map(function($invite) {
            $user = $invite->referredUser;
            return [
                'invite_id' => $invite->id,
                'owner_confirmed' => $invite->owner_confirmed,
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'created_at' => $user->created_at,
                'whatsapp' => $user->whatsapp,
            ];
        });
        // Return just the array instead of a wrapper object
        return response()->json($members->all());
    }

    public function usersMissingNextGroup()
    {
        $usersWithGroups = User::whereHas('groups')->get();
        $usersMissingNext = [];

        foreach ($usersWithGroups as $user) {
            $groups = $user->groups->sortBy('group_number');
            $lastGroup = $groups->last();
            $expectedNext = $lastGroup->group_number + 1;

            $hasNextGroup = $groups->contains('group_number', $expectedNext);

            if (!$hasNextGroup) {
                $usersMissingNext[] = [
                    'user_id'         => $user->id,
                    'user_name'       => $user->name,
                    'last_group_id'   => $lastGroup->id,
                    'last_group_code' => $lastGroup->code,
                    'last_group_number' => $lastGroup->group_number,
                ];
            }
        }

        return response()->json(['success' => true, 'users' => $usersMissingNext]);
    }

    public function groupByInviteCode($inviteCode)
    {
        $user = User::where('invite_code', $inviteCode)->first();

        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Invite code not found.'], 404);
        }

        $groups = $user->groups()->orderBy('group_number')->get();

        return response()->json(['success' => true, 'groups' => $groups]);
    }

    public function createNextGroup(Request $request)
    {
        $userId = $request->input('userId');
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'userId is required'], 400);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'User not found'], 404);
        }

        // Get user's groups in order
        $groups = Group::where('owner_id', $userId)->orderBy('group_number')->get();
        
        // Check if user has the maximum number of groups
        if ($groups->count() >= 3) {
            return response()->json(['success' => false, 'error' => 'Maximum number of groups reached'], 400);
        }

        // If user has no groups, can't create "next" group
        if ($groups->count() === 0) {
            return response()->json(['success' => false, 'error' => 'User has no existing groups'], 400);
        }

        $lastGroup = $groups->last();
        $nextGroupNumber = $lastGroup->group_number + 1;
        
        // Check if this group number already exists for this user
        if (Group::where('owner_id', $userId)->where('group_number', $nextGroupNumber)->exists()) {
            return response()->json(['success' => false, 'error' => 'Group at this level already exists'], 400);
        }

        // Count verified members in the last group
        $verifiedCount = Invite::where('group_id', $lastGroup->id)
            ->where('owner_confirmed', true)
            ->whereHas('referredUser', function($q) {
                $q->where('status', 'active');
            })->count();

        // In Node.js, only need 4 verified members
        if ($verifiedCount < 4) {
            return response()->json(['success' => false, 'error' => 'Not enough verified members'], 400);
        }

        // Update user's current_level (if needed)
        if ($user->current_level < $nextGroupNumber) {
            $user->current_level = $nextGroupNumber;
            $user->save();
        }

        // Create the next group
        $code = Str::random(6); // Or use your generateGroupCode method
        $newGroup = Group::create([
            'owner_id' => $userId,
            'code' => $code,
            'group_number' => $nextGroupNumber
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Next group created successfully',
            'group' => $newGroup
        ]);
    }

    public function joinGroupAsExistingUser(Request $request)
    {
        $userId = $request->input('userId');
        $groupCode = $request->input('groupCode');
        if (!$userId || !$groupCode) {
            return response()->json(['success' => false, 'error' => 'userId and groupCode are required'], 400);
        }
        $user = \App\Models\User::find($userId);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'User not found'], 404);
        }
        $group = \App\Models\Group::where('code', $groupCode)->first();
        if (!$group) {
            return response()->json(['success' => false, 'error' => 'Invalid invite code.'], 400);
        }
        // User can only join group at their current level
        if ($group->group_number != $user->current_level) {
            return response()->json(['success' => false, 'error' => 'You can only join a group at your current level.'], 400);
        }
        // Count non-rejected members in this group (via invites)
        $groupUserCount = \App\Models\Invite::where('group_id', $group->id)
            ->whereHas('referredUser', function($q) {
                $q->where('status', '!=', 'rejected');
            })->count();
        if ($groupUserCount >= 4) {
            return response()->json(['success' => false, 'error' => 'This group is full. Please use another invite code.'], 400);
        }
        // Check if user already has an invite for this group
        $existingInvite = \App\Models\Invite::where('group_id', $group->id)
            ->where('referred_user_id', $userId)
            ->first();
        if ($existingInvite) {
            return response()->json(['success' => false, 'error' => 'You have already requested to join this group.'], 400);
        }
        // Create invite
        \App\Models\Invite::create([
            'group_id' => $group->id,
            'inviter_id' => $group->owner_id,
            'referred_user_id' => $userId,
            'owner_confirmed' => false
        ]);
        return response()->json(['success' => true, 'message' => 'Join request sent or group joined!']);
    }
}
