<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class AuditIntegrityAlert extends Model
{
    use HasUuid;

    protected $table = 'tbl_audit_integrity_alerts';

    protected $fillable = [
        'audit_log_id',
        'attempt',
        'actor_id',
        'detail',
        'ip_address',
    ];
}
