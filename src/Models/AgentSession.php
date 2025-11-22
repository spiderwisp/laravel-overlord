<?php

namespace Spiderwisp\LaravelOverlord\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentSession extends Model
{
	use HasFactory;

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'overlord_agent_sessions';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = [
		'user_id',
		'status',
		'larastan_level',
		'auto_apply',
		'total_scans',
		'total_issues_found',
		'total_issues_fixed',
		'current_iteration',
		'max_iterations',
		'error_message',
	];

	/**
	 * The attributes that should be cast.
	 *
	 * @var array
	 */
	protected $casts = [
		'auto_apply' => 'boolean',
		'larastan_level' => 'integer',
		'total_scans' => 'integer',
		'total_issues_found' => 'integer',
		'total_issues_fixed' => 'integer',
		'current_iteration' => 'integer',
		'max_iterations' => 'integer',
	];

	/**
	 * Get the user who started the agent session.
	 */
	public function user(): BelongsTo
	{
		$userModel = config('laravel-overlord.user_model', \App\Models\User::class);
		return $this->belongsTo($userModel, 'user_id');
	}

	/**
	 * Get all logs for this agent session.
	 */
	public function logs(): HasMany
	{
		return $this->hasMany(AgentLog::class, 'agent_session_id');
	}

	/**
	 * Get all file changes for this agent session.
	 */
	public function fileChanges(): HasMany
	{
		return $this->hasMany(AgentFileChange::class, 'agent_session_id');
	}

	/**
	 * Get pending file changes (for review mode).
	 */
	public function pendingFileChanges(): HasMany
	{
		return $this->hasMany(AgentFileChange::class, 'agent_session_id')
			->where('status', 'pending');
	}

	/**
	 * Scope a query to only include running sessions.
	 */
	public function scopeRunning($query)
	{
		return $query->where('status', 'running');
	}

	/**
	 * Scope a query to only include completed sessions.
	 */
	public function scopeCompleted($query)
	{
		return $query->where('status', 'completed');
	}

	/**
	 * Scope a query to filter by user.
	 */
	public function scopeForUser($query, $userId)
	{
		return $query->where('user_id', $userId);
	}

	/**
	 * Check if session is active (running or paused).
	 */
	public function isActive(): bool
	{
		return in_array($this->status, ['running', 'paused']);
	}

	/**
	 * Check if session can be resumed.
	 */
	public function canResume(): bool
	{
		return $this->status === 'paused';
	}

	/**
	 * Check if session can be stopped.
	 */
	public function canStop(): bool
	{
		return in_array($this->status, ['running', 'paused']);
	}
}

