<?php

namespace Spiderwisp\LaravelOverlord\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spiderwisp\LaravelOverlord\Models\AgentSession;
use Spiderwisp\LaravelOverlord\Services\AgentService;

class AgentJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	protected int $sessionId;

	/**
	 * Create a new job instance.
	 */
	public function __construct(int $sessionId)
	{
		$this->sessionId = $sessionId;
	}

	/**
	 * Execute the job.
	 */
	public function handle(AgentService $agentService): void
	{
		try {
			Log::info('AgentJob: Job started', ['session_id' => $this->sessionId]);
			
			$session = AgentSession::find($this->sessionId);

			if (!$session) {
				Log::error('AgentJob: Session not found', [
					'session_id' => $this->sessionId,
				]);
				return;
			}

			Log::info('AgentJob: Session found', [
				'session_id' => $this->sessionId,
				'status' => $session->status,
			]);

			// Check if session is already running (avoid duplicate jobs)
			if ($session->status === 'running') {
				Log::warning('AgentJob: Session is already running', [
					'session_id' => $this->sessionId,
				]);
				return;
			}

			// Only run if status is pending or running
			if (!in_array($session->status, ['pending', 'running'])) {
				Log::info('AgentJob: Session status does not allow execution', [
					'session_id' => $this->sessionId,
					'status' => $session->status,
				]);
				return;
			}

			Log::info('AgentJob: Starting agent service', ['session_id' => $this->sessionId]);
			
			// Run the agent
			$agentService->runAgent($session);
			
			Log::info('AgentJob: Agent service completed', ['session_id' => $this->sessionId]);
		} catch (\Exception $e) {
			Log::error('AgentJob: Execution failed', [
				'session_id' => $this->sessionId,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			// Update session status
			try {
				$session = AgentSession::find($this->sessionId);
				if ($session) {
					$session->update([
						'status' => 'failed',
						'error_message' => $e->getMessage(),
					]);
				}
			} catch (\Exception $updateException) {
				Log::error('AgentJob: Failed to update session status', [
					'session_id' => $this->sessionId,
					'error' => $updateException->getMessage(),
				]);
			}
		}
	}
}

