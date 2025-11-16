<?php

namespace Spiderwisp\LaravelOverlord\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OverlordCommandLog extends Model
{
	use HasFactory;

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'overlord_command_logs';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = [
		'user_id',
		'command',
		'output',
		'error',
		'execution_time',
		'memory_usage',
		'success',
		'output_type',
		'ip_address',
	];

	/**
	 * The attributes that should be cast.
	 *
	 * @var array
	 */
	protected $casts = [
		'success' => 'boolean',
		'execution_time' => 'decimal:2',
		'memory_usage' => 'integer',
	];

	/**
	 * Get the user that executed the command.
	 */
	public function user()
	{
		$userModel = config('laravel-overlord.user_model', \App\Models\User::class);
		return $this->belongsTo($userModel)->withTrashed();
	}

	/**
	 * Scope a query to only include successful commands.
	 */
	public function scopeSuccessful($query)
	{
		return $query->where('success', true);
	}

	/**
	 * Scope a query to only include failed commands.
	 */
	public function scopeFailed($query)
	{
		return $query->where('success', false);
	}

	/**
	 * Scope a query to only include recent commands.
	 */
	public function scopeRecent($query, $days = 7)
	{
		return $query->where('created_at', '>=', now()->subDays($days));
	}
}