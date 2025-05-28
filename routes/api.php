<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\GroupController;

Route::post('/login', [UserController::class, 'login']);
Route::post('/register', [UserController::class, 'register']);
Route::post('/auth/login', [UserController::class, 'login']);
Route::post('/auth/register', [UserController::class, 'register']);

Route::middleware('auth:api')->group(function () {
    Route::get('/user', [UserController::class, 'getAuthenticatedUser']);
    Route::get('users/me', [UserController::class, 'getCurrentUser']);
    Route::get('/me', [UserController::class, 'getCurrentUser']); // For /api/me
    Route::get('users/pending', [UserController::class, 'getPendingVerifications']); // For /api/users/pending
    Route::get('users/by-invite-code/{inviteCode}', [UserController::class, 'getByInviteCode']);
    Route::get('users/{userId}/with-groups', [UserController::class, 'getWithGroups']);
    Route::get('users/pending-verifications', [UserController::class, 'getPendingVerifications']);
    Route::put('users/{userId}/status', [UserController::class, 'updateUserStatus']);
    Route::patch('users/{id}', [UserController::class, 'patchUserProfile']);
    Route::put('users/{id}/plan', [UserController::class, 'updateUserPlan']);
    Route::apiResource('users', UserController::class);
    Route::post('users', [UserController::class, 'createUser']);
    Route::post('users/{userId}/check-increment-level', [UserController::class, 'publicCheckAndIncrementUserLevel']);

    // Invites
    Route::get('invites/member-groups', [InviteController::class, 'getMemberGroups']);
    Route::put('invites/{inviteId}/confirm', [InviteController::class, 'confirm']);
    Route::apiResource('invites', InviteController::class);

    // Groups
    Route::apiResource('groups', GroupController::class);
    Route::post('groups/create-if-needed/{userId}', [GroupController::class, 'createIfNeeded']);
    Route::get('groups/{groupId}/members', [GroupController::class, 'getMembers']);
    Route::post('groups/create-next/{userId}', [GroupController::class, 'createNext']);
    Route::get('groups/users-missing-next-group', [GroupController::class, 'usersMissingNextGroup']);
    Route::get('groups/groups/by-invite-code/{inviteCode}', [GroupController::class, 'groupByInviteCode']);
    Route::post('groups/confirm-member', [GroupController::class, 'confirmGroupMember']);
    Route::post('groups/join', [GroupController::class, 'joinGroupAsExistingUser']);
    Route::post('groups/next-group', [GroupController::class, 'createNextGroupIfEligible']);

    // Admin endpoints for dashboard and logs (Node.js compatible)
    Route::get('/admin/stats', [\App\Http\Controllers\AdminController::class, 'getDashboardStats']);
    Route::get('/admin/logs', [\App\Http\Controllers\AdminController::class, 'getRecentAdminLogs']);
    Route::get('/admin/missing-next-group', [\App\Http\Controllers\AdminController::class, 'findUsersMissingNextGroup']);
    Route::get('/dashboard/stats', [\App\Http\Controllers\AdminController::class, 'getDashboardStats']);
});
