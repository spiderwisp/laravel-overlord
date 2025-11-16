<?php

namespace Spiderwisp\LaravelOverlord\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScanHistory extends Model
{
	use HasFactory;

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'overlord_scan_history';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = [
		'scan_id',
		'user_id',
		'status',
		'scan_mode',
		'selected_paths',
		'total_files',
		'processed_files',
		'total_batches',
		'processed_batches',
		'total_issues_found',
		'issues_saved',
		'error',
		'started_at',
		'completed_at',
	];

	/**
	 * The attributes that should be cast.
	 *
	 * @var array
	 */
	protected $casts = [
		'selected_paths' => 'array',
		'started_at' => 'datetime',
		'completed_at' => 'datetime',
	];

	/**
	 * Get the user who initiated the scan.
	 */
	public function user(): BelongsTo
	{
		$userModel = config('laravel-overlord.user_model', \App\Models\User::class);
		return $this->belongsTo($userModel, 'user_id');
	}

	/**
	 * Get all issues for this scan.
	 */
	public function issues(): HasMany
	{
		return $this->hasMany(ScanIssue::class, 'scan_id', 'scan_id');
	}

	/**
	 * Scope a query to only include completed scans.
	 */
	public function scopeCompleted($query)
	{
		return $query->where('status', 'completed');
	}

	/**
	 * Scope a query to only include failed scans.
	 */
	public function scopeFailed($query)
	{
		return $query->where('status', 'failed');
	}

	/**
	 * Scope a query to filter by user.
	 */
	public function scopeForUser($query, $userId)
	{
		return $query->where('user_id', $userId);
	}
}