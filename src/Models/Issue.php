<?php

namespace Spiderwisp\LaravelOverlord\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Issue extends Model
{
	use HasFactory;

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'overlord_issues';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = [
		'title',
		'description',
		'status',
		'priority',
		'creator_id',
		'assignee_id',
		'source_type',
		'source_id',
		'source_data',
		'tags',
		'resolved_at',
		'closed_at',
		'resolved_by_id',
		'closed_by_id',
	];

	/**
	 * The attributes that should be cast.
	 *
	 * @var array
	 */
	protected $casts = [
		'source_data' => 'array',
		'tags' => 'array',
		'resolved_at' => 'datetime',
		'closed_at' => 'datetime',
	];

	/**
	 * Get the user that created the issue.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function creator(): BelongsTo
	{
		try {
			$userModel = config('laravel-overlord.user_model', \App\Models\User::class);
			if (!class_exists($userModel)) {
				// Fallback to a dummy relationship if User model doesn't exist
				return $this->belongsTo(\App\Models\User::class, 'creator_id');
			}
			return $this->belongsTo($userModel, 'creator_id')->withTrashed();
		} catch (\Exception $e) {
			// Always return a relationship instance, even if there's an error
			return $this->belongsTo(\App\Models\User::class, 'creator_id');
		}
	}

	/**
	 * Get the user assigned to the issue.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function assignee(): BelongsTo
	{
		try {
			$userModel = config('laravel-overlord.user_model', \App\Models\User::class);
			if (!class_exists($userModel)) {
				return $this->belongsTo(\App\Models\User::class, 'assignee_id');
			}
			return $this->belongsTo($userModel, 'assignee_id')->withTrashed();
		} catch (\Exception $e) {
			return $this->belongsTo(\App\Models\User::class, 'assignee_id');
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
	 * Get the user that closed the issue.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function closedBy(): BelongsTo
	{
		try {
			$userModel = config('laravel-overlord.user_model', \App\Models\User::class);
			if (!class_exists($userModel)) {
				return $this->belongsTo(\App\Models\User::class, 'closed_by_id');
			}
			return $this->belongsTo($userModel, 'closed_by_id')->withTrashed();
		} catch (\Exception $e) {
			return $this->belongsTo(\App\Models\User::class, 'closed_by_id');
		}
	}

	/**
	 * Scope a query to only include open issues.
	 */
	public function scopeOpen($query)
	{
		return $query->where('status', 'open');
	}

	/**
	 * Scope a query to only include in-progress issues.
	 */
	public function scopeInProgress($query)
	{
		return $query->where('status', 'in_progress');
	}

	/**
	 * Scope a query to only include resolved issues.
	 */
	public function scopeResolved($query)
	{
		return $query->where('status', 'resolved');
	}

	/**
	 * Scope a query to only include closed issues.
	 */
	public function scopeClosed($query)
	{
		return $query->where('status', 'closed');
	}

	/**
	 * Scope a query to filter by priority.
	 */
	public function scopeByPriority($query, $priority)
	{
		return $query->where('priority', $priority);
	}

	/**
	 * Scope a query to filter by assignee.
	 */
	public function scopeAssignedTo($query, $userId)
	{
		return $query->where('assignee_id', $userId);
	}

	/**
	 * Scope a query to filter by creator.
	 */
	public function scopeCreatedBy($query, $userId)
	{
		return $query->where('creator_id', $userId);
	}

	/**
	 * Scope a query to filter by source type.
	 */
	public function scopeBySourceType($query, $type)
	{
		return $query->where('source_type', $type);
	}

	/**
	 * Mark the issue as resolved.
	 *
	 * @param int|null $userId
	 * @return $this
	 */
	public function resolve($userId = null)
	{
		$this->status = 'resolved';
		$this->resolved_at = now();
		if ($userId) {
			$this->resolved_by_id = $userId;
		}
		$this->save();
		return $this;
	}

	/**
	 * Mark the issue as closed.
	 *
	 * @param int|null $userId
	 * @return $this
	 */
	public function close($userId = null)
	{
		$this->status = 'closed';
		$this->closed_at = now();
		if ($userId) {
			$this->closed_by_id = $userId;
		}
		$this->save();
		return $this;
	}

	/**
	 * Reopen a closed or resolved issue.
	 *
	 * @return $this
	 */
	public function reopen()
	{
		$this->status = 'open';
		$this->resolved_at = null;
		$this->closed_at = null;
		$this->resolved_by_id = null;
		$this->closed_by_id = null;
		$this->save();
		return $this;
	}

	/**
	 * Assign the issue to a user.
	 *
	 * @param int|null $userId
	 * @return $this
	 */
	public function assignTo($userId)
	{
		$this->assignee_id = $userId;
		if ($userId && $this->status === 'open') {
			$this->status = 'in_progress';
		}
		$this->save();
		return $this;
	}

	/**
	 * Remove the assignee from the issue.
	 *
	 * @return $this
	 */
	public function unassign()
	{
		$this->assignee_id = null;
		if ($this->status === 'in_progress') {
			$this->status = 'open';
		}
		$this->save();
		return $this;
	}
}