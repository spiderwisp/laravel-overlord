<?php

namespace Spiderwisp\LaravelOverlord\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanIssue extends Model
{
	use HasFactory;

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'overlord_scan_issues';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = [
		'scan_id',
		'user_id',
		'file_path',
		'line',
		'type',
		'severity',
		'message',
		'raw_data',
		'resolved',
		'resolved_by_id',
		'resolved_at',
	];

	/**
	 * The attributes that should be cast.
	 *
	 * @var array
	 */
	protected $casts = [
		'raw_data' => 'array',
		'resolved' => 'boolean',
		'line' => 'integer',
		'resolved_at' => 'datetime',
	];

	/**
	 * Get the user that ran the scan.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function user(): BelongsTo
	{
		try {
			$userModel = config('laravel-overlord.user_model', \App\Models\User::class);
			if (!class_exists($userModel)) {
				return $this->belongsTo(\App\Models\User::class, 'user_id');
			}
			return $this->belongsTo($userModel, 'user_id')->withTrashed();
		} catch (\Exception $e) {
			return $this->belongsTo(\App\Models\User::class, 'user_id');
		}
	}

	/**
	 * Get the user that resolved the issue.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function resolvedBy(): BelongsTo
	{
		try {
			$userModel = config('laravel-overlord.user_model', \App\Models\User::class);
			if (!class_exists($userModel)) {
				return $this->belongsTo(\App\Models\User::class, 'resolved_by_id');
			}
			return $this->belongsTo($userModel, 'resolved_by_id')->withTrashed();
		} catch (\Exception $e) {
			return $this->belongsTo(\App\Models\User::class, 'resolved_by_id');
		}
	}

	/**
	 * Scope a query to only include unresolved issues.
	 */
	public function scopeUnresolved($query)
	{
		return $query->where('resolved', false);
	}

	/**
	 * Scope a query to only include resolved issues.
	 */
	public function scopeResolved($query)
	{
		return $query->where('resolved', true);
	}

	/**
	 * Scope a query to filter by scan ID.
	 */
	public function scopeByScanId($query, $scanId)
	{
		return $query->where('scan_id', $scanId);
	}

	/**
	 * Scope a query to filter by severity.
	 */
	public function scopeBySeverity($query, $severity)
	{
		return $query->where('severity', $severity);
	}

	/**
	 * Scope a query to filter by file path.
	 */
	public function scopeByFilePath($query, $filePath)
	{
		return $query->where('file_path', $filePath);
	}

	/**
	 * Mark the issue as resolved.
	 *
	 * @param int|null $userId
	 * @return $this
	 */
	public function markAsResolved($userId = null)
	{
		$this->resolved = true;
		$this->resolved_at = now();
		if ($userId) {
			$this->resolved_by_id = $userId;
		}
		$this->save();
		return $this;
	}

	/**
	 * Mark the issue as unresolved.
	 *
	 * @return $this
	 */
	public function markAsUnresolved()
	{
		$this->resolved = false;
		$this->resolved_at = null;
		$this->resolved_by_id = null;
		$this->save();
		return $this;
	}
}