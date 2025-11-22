<?php

namespace Spiderwisp\LaravelOverlord\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentFileChange extends Model
{
	use HasFactory;

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'overlord_agent_file_changes';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = [
		'agent_session_id',
		'file_path',
		'original_content',
		'new_content',
		'status',
		'rejection_reason',
		'backup_path',
		'change_summary',
	];

	/**
	 * The attributes that should be cast.
	 *
	 * @var array
	 */
	protected $casts = [
		'change_summary' => 'array',
	];

	/**
	 * Get the agent session this file change belongs to.
	 */
	public function agentSession(): BelongsTo
	{
		return $this->belongsTo(AgentSession::class, 'agent_session_id');
	}

	/**
	 * Scope a query to only include pending changes.
	 */
	public function scopePending($query)
	{
		return $query->where('status', 'pending');
	}

	/**
	 * Scope a query to only include approved changes.
	 */
	public function scopeApproved($query)
	{
		return $query->where('status', 'approved');
	}

	/**
	 * Scope a query to only include rejected changes.
	 */
	public function scopeRejected($query)
	{
		return $query->where('status', 'rejected');
	}

	/**
	 * Scope a query to only include applied changes.
	 */
	public function scopeApplied($query)
	{
		return $query->where('status', 'applied');
	}

	/**
	 * Check if change is pending approval.
	 */
	public function isPending(): bool
	{
		return $this->status === 'pending';
	}

	/**
	 * Check if change has been approved.
	 */
	public function isApproved(): bool
	{
		return $this->status === 'approved';
	}

	/**
	 * Check if change has been rejected.
	 */
	public function isRejected(): bool
	{
		return $this->status === 'rejected';
	}

	/**
	 * Check if change has been applied.
	 */
	public function isApplied(): bool
	{
		return $this->status === 'applied';
	}
}

