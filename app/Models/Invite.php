<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invite extends Model
{
    protected $fillable = [
        'group_id',
        'inviter_id',
        'referred_user_id',
        'created_at',
        'owner_confirmed',
    ];

    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function referredUser()
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

}
