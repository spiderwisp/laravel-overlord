<?php

namespace Spiderwisp\LaravelOverlord\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseScanIssue extends Model
{
	use HasFactory;

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'overlord_database_scan_issues';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = [
		'scan_history_id',
		'user_id',
		'table_name',
		'issue_type',
		'severity',
		'title',
		'description',
		'location',
		'suggestion',
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
		'location' => 'array',
		'resolved' => 'boolean',
		'resolved_at' => 'datetime',
	];

	/**
	 * Get the scan history that this issue belongs to.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function scanHistory(): BelongsTo
	{
		return $this->belongsTo(DatabaseScanHistory::class, 'scan_history_id');
	}

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
	 * Scope a query to filter by scan history ID.
	 */
	public function scopeByScanHistoryId($query, $scanHistoryId)
	{
		return $query->where('scan_history_id', $scanHistoryId);
	}

	/**
	 * Scope a query to filter by severity.
	 */
	public function scopeBySeverity($query, $severity)
	{
		return $query->where('severity', $severity);
	}

	/**
	 * Scope a query to filter by table name.
	 */
	public function scopeByTableName($query, $tableName)
	{
		return $query->where('table_name', $tableName);
	}

	/**
	 * Scope a query to filter by issue type.
	 */
	public function scopeByIssueType($query, $issueType)
	{
		return $query->where('issue_type', $issueType);
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