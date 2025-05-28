<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Group;

class DashboardController extends Controller
{
    public function stats()
    {
        return response()->json([
            'totalUsers' => User::count(),
            'pendingVerifications' => User::where('status', 'pending')->count(),
            'activeUsers' => User::where('status', 'active')->count(),
            'totalGroups' => Group::count()
        ]);
    }
}
