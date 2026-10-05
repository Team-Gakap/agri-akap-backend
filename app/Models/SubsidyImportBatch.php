<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubsidyImportBatch extends Model
{
    use HasUuid;

    protected $table = 'tbl_subsidy_import_batches';

    protected $fillable = [
        'batch_name',
        'original_filename',
        'stored_path',
        'month_year',
        'uploaded_by',
        'total_sheets',
        'status',
        'error_message',
    ];

    protected $casts = [
        'total_sheets' => 'integer',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function programs(): HasMany
    {
        return $this->hasMany(SubsidyProgram::class, 'batch_id');
    }
}
