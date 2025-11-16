<?php

namespace Spiderwisp\LaravelOverlord\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spiderwisp\LaravelOverlord\Models\OverlordCommandLog;
use Spiderwisp\LaravelOverlord\Services\SensitiveDataRedactor;

class LogOverlordCommand implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	protected $user_id;
	protected $command;
	protected $output;
	protected $error;
	protected $execution_time;
	protected $memory_usage;
	protected $success;
	protected $output_type;
	protected $ip_address;

	/**
	 * Create a new job instance.
	 *
	 * @param int|null $user_id
	 * @param string $command
	 * @param string|null $output
	 * @param string|null $error
	 * @param float|null $execution_time
	 * @param int|null $memory_usage
	 * @param bool $success
	 * @param string|null $output_type
	 * @param string|null $ip_address
	 */
	public function __construct(
		?int $user_id,
		string $command,
		?string $output,
		?string $error,
		?float $execution_time,
		?int $memory_usage,
		bool $success,
		?string $output_type,
		?string $ip_address
	) {
		// Redact sensitive data from command
		$this->command = SensitiveDataRedactor::redact($command);

		// Truncate and redact output (max 10KB)
		if ($output !== null) {
			$redactedOutput = SensitiveDataRedactor::redact($output);
			$this->output = mb_strlen($redactedOutput) > 10240
				? mb_substr($redactedOutput, 0, 10240) . '... [TRUNCATED]'
				: $redactedOutput;
		} else {
			$this->output = null;
		}

		// Redact sensitive data from error
		$this->error = $error !== null ? SensitiveDataRedactor::redact($error) : null;

		$this->user_id = $user_id;
		$this->execution_time = $execution_time;
		$this->memory_usage = $memory_usage;
		$this->success = $success;
		$this->output_type = $output_type;
		$this->ip_address = $ip_address;
	}

	/**
	 * Execute the job.
	 *
	 * @return void
	 */
	public function handle()
	{
		OverlordCommandLog::create([
			'user_id' => $this->user_id,
			'command' => $this->command,
			'output' => $this->output,
			'error' => $this->error,
			'execution_time' => $this->execution_time,
			'memory_usage' => $this->memory_usage,
			'success' => $this->success,
			'output_type' => $this->output_type,
			'ip_address' => $this->ip_address,
		]);
	}
}