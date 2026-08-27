<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaChallenge extends Model
{
    use HasUuid;

    protected $table = 'tbl_mfa_challenges';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'device_name',
        'pending_secret',
        'attempts',
        'sms_code_hash',
        'sms_sent_at',
        'expires_at',
        'created_at',
    ];

    protected $hidden = [
        'pending_secret',
        'sms_code_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pending_secret' => 'encrypted',
            'attempts' => 'integer',
            'sms_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
