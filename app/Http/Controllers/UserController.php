<?php

namespace App\Http\Controllers;

use App\Models\Invite;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Group;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::all(), 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        dd($request->all());
        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => $request->role,
            'referred_by' => $request->referred_by,
            'invite_code' => $request->invite_code,
            'pack_type'   => $request->pack_type,
            'status'      => $request->status,
            'current_level' => $request->current_level,
            'whatsapp'      => $request->whatsapp,
        ]);

        return response()->json($user, 201);
    }

    public function show(User $user)
    {
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'User not found'], 404);
        }
        return response()->json(['success' => true, 'user' => $user], 200);
    }

    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'email|unique:users,email,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->fill($request->only([
            'name', 'email', 'role', 'referred_by',
            'invite_code', 'pack_type', 'status',
            'current_level', 'whatsapp'
        ]));

        if ($request->has('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return response()->json($user, 200);
    }

    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(['message' => 'User deleted successfully.'], 204);
    }

    public function getByInviteCode($inviteCode)
    {
        $user = User::where('invite_code', $inviteCode)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        return response()->json($user);
    }

    public function getWithGroups($userId)
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'User not found'], 404);
        }
        // Get groups owned by user
        $groups = Group::where('owner_id', $userId)->get();
        $groupsWithCounts = $groups->map(function ($group) {
            // Count all members (invites)
            $members = $group->invites()->count();
            // Count verified members: owner_confirmed = true AND referredUser.status = 'active'
            $verified_members = $group->invites()
                ->where('owner_confirmed', true)
                ->whereHas('referredUser', function($q) {
                    $q->where('status', 'active');
                })->count();
            $groupArr = $group->toArray();
            $groupArr['members'] = $members;
            $groupArr['verified_members'] = $verified_members;
            return $groupArr;
        });
        $userArr = $user->toArray();
        $userArr['groups'] = $groupsWithCounts;
        return response()->json(['success' => true, 'user' => $userArr]);
    }

    public function getPendingVerifications()
    {
        $pendingUsers = User::where('status', 'pending')->get();
        return response()->json(['users' => $pendingUsers]);
    }

    public function updateUserStatus(Request $request, $userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|string',
        ]);

        $user->status = $validated['status'];
        $user->save();

        // If status is set to active, trigger group creation logic
        if ($user->status === 'active') {
            $this->createGroupIfNeededInternal($user->id);
            $this->createNextGroupIfEligibleInternal($user->id);
            $this->checkAndIncrementUserLevel($user->id);

            // --- NEW: Also check/increment for the group owner where this user is a member ---
            $invite = \App\Models\Invite::where('referred_user_id', $user->id)
                ->where('owner_confirmed', true)
                ->first();
            if ($invite) {
                $group = \App\Models\Group::find($invite->group_id);
                if ($group && $group->owner_id) {
                    $this->checkAndIncrementUserLevel($group->owner_id);
                }
            }
        }

        return response()->json(['message' => 'Status updated successfully', 'status' => $user->status]);
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');
        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json(['success' => false, 'error' => 'Invalid credentials'], 401);
        }
        $user = auth('api')->user();
        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $user
        ]);
    }

    public function getAuthenticatedUser(Request $request)
    {
        return $request->user();
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'whatsapp' => 'nullable|string|max:255',
            'invite_code' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $data['password'] = Hash::make($data['password']);
        $data['status'] = 'pending';
        $data['current_level'] = 1;
        $data['role'] = 'user';
        

        // Check group invite code and member limit BEFORE creating user
        if ($request->filled('invite_code')) {
            $group = Group::where('code', $request->invite_code)->first();
            if (!$group || $group->group_number != 1) {
                return response()->json(['success' => false, 'error' => 'Invalid invite code or not a level 1 group.'], 400);
            }
            // Count non-rejected members in this group (via invites)
            $groupUserCount = Invite::where('group_id', $group->id)
                ->whereHas('referredUser', function($q) {
                    $q->where('status', '!=', 'rejected');
                })->count();
            if ($groupUserCount >= 4) {
                return response()->json(['success' => false, 'error' => 'This group is full. Please use another invite code.'], 400);
            }
            $data['referred_by'] = $group->owner_id; // <-- set referred_by to group owner
        }
        // Now safe to create user
        $user = User::create($data);
        // Handle invite logic if invite_code is present
        if ($request->filled('invite_code')) {
            $group = Group::where('code', $request->invite_code)->first();
            Invite::create([
                'group_id' => $group->id,
                'inviter_id' => $group->owner_id,
                'referred_user_id' => $user->id,
                'owner_confirmed' => false
            ]);
        }
        $token = auth('api')->login($user);
        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $user
        ], 201);
    }

    // PATCH /api/users/{id} - Partial user profile update (Node.js compatible)
    public function patchUserProfile(Request $request, $id)
    {
        $user = \App\Models\User::find($id);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'User not found'], 404);
        }
        $allowedFields = ['name', 'email', 'whatsapp'];
        $updates = [];
        foreach ($allowedFields as $field) {
            if ($request->has($field)) {
                $updates[$field] = $request->input($field);
            }
        }
        if (empty($updates)) {
            return response()->json(['success' => false, 'error' => 'No valid fields provided'], 400);
        }
        $user->update($updates);
        return response()->json(['success' => true, 'user' => $user]);
    }

    // PUT /api/users/{id}/plan - Update user plan/pack_type (Node.js compatible)
    public function updateUserPlan(Request $request, $id)
    {
        $user = \App\Models\User::find($id);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'User not found'], 404);
        }
        $packType = $request->input('pack_type') ?? $request->input('packType');
        if (!in_array($packType, ['starter', 'gold'])) {
            return response()->json(['success' => false, 'error' => 'Invalid pack_type'], 400);
        }
        $user->pack_type = $packType;
        $user->save();
        return response()->json(['success' => true]);
    }

    public function getCurrentUser(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Not authenticated'], 401);
        }
        return response()->json(['success' => true, 'user' => $user]);
    }

    public function getUserById($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'User not found'], 404);
        }
        return response()->json(['success' => true, 'user' => $user]);
    }

    public function createUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'status'   => $request->status ?? 'pending',
            'current_level' => $request->current_level ?? 1,
            'whatsapp' => $request->whatsapp ?? null,
        ]);
        return response()->json(['success' => true, 'user' => $user]);
    }

    // Internal helper: create group for user if eligible (level 1)
    private function createGroupIfNeededInternal($userId)
    {
        $user = User::find($userId);
        if (!$user || $user->status !== 'active') return false;
        // Check if user has an invite with owner_confirmed = true
        $invite = Invite::where('referred_user_id', $userId)->where('owner_confirmed', true)->first();
        if (!$invite) return false;
        // Check if user already has a group
        if (Group::where('owner_id', $userId)->count() > 0) return false;
        // Create group_number 1
        $code = $this->generateGroupCode();
        Group::create([
            'owner_id' => $userId,
            'code' => $code,
            'group_number' => 1
        ]);
        // After group creation, check for auto-increment for all levels
        $this->checkAndIncrementUserLevel($userId);
        return true;
    }

    // Internal helper: create next group for user if eligible (level 2/3)
    private function createNextGroupIfEligibleInternal($userId)
    {
        $user = User::find($userId);
        if (!$user) return false;
        $groups = Group::where('owner_id', $userId)->orderBy('group_number')->get();
        if ($groups->count() === 0 || $groups->count() >= 3) return false;

        $nextGroupNumber = $user->current_level;
        // Don't create if group already exists at this level
        if ($groups->where('group_number', $nextGroupNumber)->count()) return false;

        // Create the group at the user's current level
        $code = $this->generateGroupCode();
        $newGroup = Group::create([
            'owner_id' => $userId,
            'code' => $code,
            'group_number' => $nextGroupNumber
        ]);
        \Log::info("SUCCESS: Created group {$newGroup->id} at level $nextGroupNumber for user $userId");
        return true;
    }

    // PUBLIC: Allow other controllers to trigger level check (e.g., after owner_confirmed)
    public function publicCheckAndIncrementUserLevel($userId)
    {
        $this->checkAndIncrementUserLevel($userId);
        return response()->json(['success' => true]);
    }

    // Helper: check and increment user level if eligible (for all groups)
    // PUBLIC: Should be called after admin verifies user status or after owner confirms a member
    public function checkAndIncrementUserLevel($userId)
    {
        \Log::info("checkAndIncrementUserLevel: CALLED for userId=$userId");
        $user = User::find($userId);
        if (!$user) {
            \Log::info("checkAndIncrementUserLevel: User $userId not found");
            return false;
        }
        
        \Log::info("checkAndIncrementUserLevel started for userId: $userId, current_level: {$user->current_level}");
        
        $groups = Group::where('owner_id', $userId)->orderBy('group_number')->get();
        foreach ($groups as $group) {
            \Log::info("checkAndIncrementUserLevel: userId=$userId, user_current_level={$user->current_level}, group_id={$group->id}, group_number={$group->group_number}");
            if ($group->group_number != $user->current_level) continue;
            $verifiedCount = Invite::where('group_id', $group->id)
                ->where('owner_confirmed', true)
                ->whereHas('referredUser', function($q) { $q->where('status', 'active'); })
                ->count();
            \Log::info("checkAndIncrementUserLevel: group_id={$group->id}, group_number={$group->group_number}, verifiedCount=$verifiedCount");
            if ($verifiedCount >= 4 && $user->current_level < 3) {
                $oldLevel = $user->current_level;
                $user->current_level = $user->current_level + 1;
                $user->save();
                \Log::info("SUCCESS: User $userId level incremented from $oldLevel to {$user->current_level}");
                return true;
            }
        }
        return false;
    }

    // Helper: generate group code
    private function generateGroupCode()
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $code;
    }
}
