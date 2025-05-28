<?php

namespace App\Http\Controllers;

use App\Models\Invite;
use Illuminate\Http\Request;

class InviteController extends Controller
{
    public function index()
    {
        return response()->json(
            Invite::with(['group', 'inviter', 'referredUser'])->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'group_id' => 'nullable|integer|exists:groups,id',
            'inviter_id' => 'nullable|integer|exists:users,id',
            'referred_user_id' => 'nullable|integer|exists:users,id',
            'created_at' => 'nullable|date',
            'owner_confirmed' => 'nullable|boolean',
        ]);

        $invite = Invite::create($validated);

        return response()->json($invite, 201);
    }

    public function show($id)
    {
        $invite = Invite::with(['group', 'inviter', 'referredUser'])->findOrFail($id);
        return response()->json($invite);
    }

    public function update(Request $request, $id)
    {
        $invite = Invite::findOrFail($id);

        $validated = $request->validate([
            'group_id' => 'nullable|integer|exists:groups,id',
            'inviter_id' => 'nullable|integer|exists:users,id',
            'referred_user_id' => 'nullable|integer|exists:users,id',
            'created_at' => 'nullable|date',
            'owner_confirmed' => 'nullable|boolean',
        ]);

        $invite->update($validated);

        // If owner_confirmed is being set to true, trigger group creation logic
        if (isset($validated['owner_confirmed']) && $validated['owner_confirmed']) {
            $referredUserId = $validated['referred_user_id'] ?? $invite->referred_user_id;
            // Call group creation logic if needed (mimic Node.js)
            // You may want to inject GroupController or move logic to a service for real use
            \App::make('App\\Http\\Controllers\\GroupController')->createIfNeeded($referredUserId);
        }

        return response()->json($invite);
    }

    public function destroy($id)
    {
        $invite = Invite::findOrFail($id);
        $invite->delete();

        return response()->json(['message' => 'Invite deleted successfully']);
    }

    public function confirm($inviteId)
    {
        $invite = Invite::find($inviteId);

        if (!$invite) {
            return response()->json(['success' => false, 'error' => 'Invite not found'], 404);
        }

        $invite->owner_confirmed = true;
        $invite->save();

        // Trigger group creation and progression logic for referred user
        $referredUserId = $invite->referred_user_id;
        $userController = app(\App\Http\Controllers\UserController::class);
        $userController->createGroupIfNeededInternal($referredUserId);
        $userController->createNextGroupIfEligibleInternal($referredUserId);
        // Always check and increment group owner's level after confirmation
        $group = $invite->group;
        if ($group && $group->owner_id) {
            app(\App\Http\Controllers\UserController::class)->checkAndIncrementUserLevel($group->owner_id);
        }

        return response()->json(['success' => true, 'message' => 'Invite confirmed successfully']);
    }

    /**
     * Get groups where user is a member (via invites), but not owner
     */
    public function getMemberGroups(Request $request)
    {
        $userId = $request->query('userId');
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'userId is required'], 400);
        }
        
        try {
            // Fix GROUP BY syntax error by including all selected columns
            $groups = \DB::table('groups as g')
                ->join('invites as i', 'i.group_id', '=', 'g.id')
                ->select('g.id', 'g.code', 'g.group_number', 'g.owner_id')
                ->where('i.referred_user_id', $userId)
                ->where('g.owner_id', '!=', $userId)
                ->where('i.owner_confirmed', 1)
                ->groupBy(['g.id', 'g.code', 'g.group_number', 'g.owner_id'])
                ->get();
            
            return response()->json(['success' => true, 'groups' => $groups]);
        } catch (\Exception $e) {
            \Log::error('Error fetching member groups: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
