<?php

namespace Spiderwisp\LaravelOverlord\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLog extends Model
{
	use HasFactory;

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'overlord_agent_logs';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = [
		'agent_session_id',
		'type',
		'message',
		'data',
	];

	/**
	 * The attributes that should be cast.
	 *
	 * @var array
	 */
	protected $casts = [
		'data' => 'array',
	];

	/**
	 * Get the agent session this log belongs to.
	 */
	public function agentSession(): BelongsTo
	{
		return $this->belongsTo(AgentSession::class, 'agent_session_id');
	}

	/**
	 * Scope a query to filter by log type.
	 */
	public function scopeOfType($query, string $type)
	{
		return $query->where('type', $type);
	}

	/**
	 * Scope a query to get recent logs.
	 */
	public function scopeRecent($query, int $limit = 100)
	{
		return $query->orderBy('created_at', 'desc')->limit($limit);
	}
}

