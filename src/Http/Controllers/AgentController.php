<?php

namespace Spiderwisp\LaravelOverlord\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Spiderwisp\LaravelOverlord\Models\AgentSession;
use Spiderwisp\LaravelOverlord\Models\AgentLog;
use Spiderwisp\LaravelOverlord\Models\AgentFileChange;
use Spiderwisp\LaravelOverlord\Services\AgentService;
use Spiderwisp\LaravelOverlord\Jobs\AgentJob;

class AgentController extends Controller
{
	/**
	 * Start a new agent session
	 */
	public function start(Request $request, AgentService $agentService)
	{
		try {
			$request->validate([
				'larastan_level' => 'required|integer|min:0|max:9',
				'auto_apply' => 'boolean',
				'max_iterations' => 'integer|min:1|max:100',
			]);

			$userId = auth()->id();
			$larastanLevel = (int) $request->input('larastan_level');
			$autoApply = $request->input('auto_apply', true);
			$maxIterations = $request->input('max_iterations', 50);

			// Check if user has an active session
			$activeSession = AgentSession::where('user_id', $userId)
				->whereIn('status', ['running', 'paused'])
				->first();

			if ($activeSession) {
				return response()->json([
					'success' => false,
					'error' => 'You already have an active agent session. Please stop it first.',
					'session_id' => $activeSession->id,
				], 400);
			}

			// Create new session
			$session = AgentSession::create([
				'user_id' => $userId,
				'status' => 'pending',
				'larastan_level' => $larastanLevel,
				'auto_apply' => $autoApply,
				'max_iterations' => $maxIterations,
			]);

			// Add initial log
			AgentLog::create([
				'agent_session_id' => $session->id,
				'type' => 'info',
				'message' => 'Agent session created. Starting agent...',
				'data' => [
					'larastan_level' => $larastanLevel,
					'auto_apply' => $autoApply,
					'max_iterations' => $maxIterations,
				],
			]);

			// Try to run synchronously first (for immediate feedback)
			// If that fails or times out, dispatch as job
			try {
				// Run in background process if exec is available
				if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
					$frameworkDir = storage_path('framework');
					if (!is_dir($frameworkDir)) {
						mkdir($frameworkDir, 0755, true);
					}

					$script = $frameworkDir . '/agent_' . $session->id . '.php';
					$logFile = storage_path('logs/agent_' . $session->id . '.log');

					$scriptContent = <<<PHP
<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '{$logFile}');

require __DIR__ . '/../../vendor/autoload.php';
\$app = require_once __DIR__ . '/../../bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    \$sessionId = {$session->id};
    \$job = new Spiderwisp\LaravelOverlord\Jobs\AgentJob(\$sessionId);
    \$job->handle(\$app->make(Spiderwisp\LaravelOverlord\Services\AgentService::class));
} catch (\\Exception \$e) {
    if (file_exists('{$logFile}')) {
        file_put_contents('{$logFile}', "Error: " . \$e->getMessage() . "\\n", FILE_APPEND);
    }
    throw \$e;
}

@unlink(__FILE__);
PHP;
					file_put_contents($script, $scriptContent);

					// Run in background
					$phpPath = PHP_BINARY;
					$command = sprintf(
						'%s %s >> %s 2>&1 &',
						escapeshellarg($phpPath),
						escapeshellarg($script),
						escapeshellarg($logFile)
					);
					exec($command);
				} else {
					// Fallback: dispatch as queue job
					AgentJob::dispatch($session->id);
				}
			} catch (\Exception $e) {
				Log::error('Failed to start agent job', [
					'session_id' => $session->id,
					'error' => $e->getMessage(),
				]);

				// Fallback: dispatch as queue job
				AgentJob::dispatch($session->id);
			}

			return response()->json([
				'success' => true,
				'result' => [
					'session_id' => $session->id,
					'status' => $session->status,
				],
			]);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return response()->json([
				'success' => false,
				'error' => 'Validation failed',
				'errors' => $e->errors(),
			], 422);
		} catch (\Exception $e) {
			Log::error('Failed to start agent', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to start agent: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get agent session status
	 */
	public function status(Request $request, string $sessionId)
	{
		try {
			$userId = auth()->id();
			$session = AgentSession::where('id', $sessionId)
				->where('user_id', $userId)
				->first();

			if (!$session) {
				return response()->json([
					'success' => false,
					'error' => 'Session not found',
				], 404);
			}

			return response()->json([
				'success' => true,
				'result' => [
					'session_id' => $session->id,
					'status' => $session->status,
					'larastan_level' => $session->larastan_level,
					'auto_apply' => $session->auto_apply,
					'total_scans' => $session->total_scans,
					'total_issues_found' => $session->total_issues_found,
					'total_issues_fixed' => $session->total_issues_fixed,
					'current_iteration' => $session->current_iteration,
					'max_iterations' => $session->max_iterations,
					'error_message' => $session->error_message,
					'created_at' => $session->created_at->toIso8601String(),
					'updated_at' => $session->updated_at->toIso8601String(),
					'pending_changes_count' => $session->pendingFileChanges()->count(),
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get agent status', [
				'session_id' => $sessionId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get status: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Stop agent session
	 */
	public function stop(Request $request, string $sessionId)
	{
		try {
			$userId = auth()->id();
			$session = AgentSession::where('id', $sessionId)
				->where('user_id', $userId)
				->first();

			if (!$session) {
				return response()->json([
					'success' => false,
					'error' => 'Session not found',
				], 404);
			}

			if (!$session->canStop()) {
				return response()->json([
					'success' => false,
					'error' => 'Session cannot be stopped in current status: ' . $session->status,
				], 400);
			}

			$session->update(['status' => 'stopped']);

			AgentLog::create([
				'agent_session_id' => $session->id,
				'type' => 'info',
				'message' => 'Agent stopped by user',
			]);

			return response()->json([
				'success' => true,
				'result' => [
					'session_id' => $session->id,
					'status' => $session->status,
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to stop agent', [
				'session_id' => $sessionId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to stop agent: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Pause agent session
	 */
	public function pause(Request $request, string $sessionId)
	{
		try {
			$userId = auth()->id();
			$session = AgentSession::where('id', $sessionId)
				->where('user_id', $userId)
				->first();

			if (!$session) {
				return response()->json([
					'success' => false,
					'error' => 'Session not found',
				], 404);
			}

			if ($session->status !== 'running') {
				return response()->json([
					'success' => false,
					'error' => 'Session is not running',
				], 400);
			}

			$session->update(['status' => 'paused']);

			AgentLog::create([
				'agent_session_id' => $session->id,
				'type' => 'info',
				'message' => 'Agent paused by user',
			]);

			return response()->json([
				'success' => true,
				'result' => [
					'session_id' => $session->id,
					'status' => $session->status,
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to pause agent', [
				'session_id' => $sessionId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to pause agent: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Resume agent session
	 */
	public function resume(Request $request, string $sessionId)
	{
		try {
			$userId = auth()->id();
			$session = AgentSession::where('id', $sessionId)
				->where('user_id', $userId)
				->first();

			if (!$session) {
				return response()->json([
					'success' => false,
					'error' => 'Session not found',
				], 404);
			}

			if (!$session->canResume()) {
				return response()->json([
					'success' => false,
					'error' => 'Session cannot be resumed in current status: ' . $session->status,
				], 400);
			}

			$session->update(['status' => 'running']);

			AgentLog::create([
				'agent_session_id' => $session->id,
				'type' => 'info',
				'message' => 'Agent resumed by user',
			]);

			// If job is not running, dispatch it again
			AgentJob::dispatch($session->id);

			return response()->json([
				'success' => true,
				'result' => [
					'session_id' => $session->id,
					'status' => $session->status,
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to resume agent', [
				'session_id' => $sessionId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to resume agent: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get pending file changes (for review mode)
	 */
	public function getPendingChanges(Request $request, string $sessionId)
	{
		try {
			$userId = auth()->id();
			$session = AgentSession::where('id', $sessionId)
				->where('user_id', $userId)
				->first();

			if (!$session) {
				return response()->json([
					'success' => false,
					'error' => 'Session not found',
				], 404);
			}

			$changes = AgentFileChange::where('agent_session_id', $session->id)
				->where('status', 'pending')
				->orderBy('created_at', 'desc')
				->get()
				->map(function ($change) {
					return [
						'id' => $change->id,
						'file_path' => $change->file_path,
						'original_content' => $change->original_content,
						'new_content' => $change->new_content,
						'status' => $change->status,
						'change_summary' => $change->change_summary,
						'created_at' => $change->created_at->toIso8601String(),
					];
				});

			return response()->json([
				'success' => true,
				'result' => [
					'changes' => $changes,
					'count' => $changes->count(),
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get pending changes', [
				'session_id' => $sessionId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get pending changes: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get agent logs
	 */
	public function getLogs(Request $request, string $sessionId)
	{
		try {
			$userId = auth()->id();
			$session = AgentSession::where('id', $sessionId)
				->where('user_id', $userId)
				->first();

			if (!$session) {
				return response()->json([
					'success' => false,
					'error' => 'Session not found',
				], 404);
			}

			$limit = (int) $request->input('limit', 100);
			$offset = (int) $request->input('offset', 0);

			$logs = AgentLog::where('agent_session_id', $session->id)
				->orderBy('created_at', 'desc')
				->offset($offset)
				->limit($limit)
				->get()
				->map(function ($log) {
					return [
						'id' => $log->id,
						'type' => $log->type,
						'message' => $log->message,
						'data' => $log->data,
						'created_at' => $log->created_at->toIso8601String(),
					];
				});

			return response()->json([
				'success' => true,
				'result' => [
					'logs' => $logs,
					'total' => AgentLog::where('agent_session_id', $session->id)->count(),
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get agent logs', [
				'session_id' => $sessionId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get logs: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Approve a file change (review mode)
	 */
	public function approveChange(Request $request, string $changeId)
	{
		try {
			$userId = auth()->id();
			$change = AgentFileChange::where('id', $changeId)
				->whereHas('agentSession', function ($query) use ($userId) {
					$query->where('user_id', $userId);
				})
				->first();

			if (!$change) {
				return response()->json([
					'success' => false,
					'error' => 'File change not found',
				], 404);
			}

			if (!$change->isPending()) {
				return response()->json([
					'success' => false,
					'error' => 'File change is not pending approval',
				], 400);
			}

			// Apply the change
			$fileEditService = app(\Spiderwisp\LaravelOverlord\Services\FileEditService::class);
			$writeResult = $fileEditService->writeFile($change->file_path, $change->new_content, true);

			if (!$writeResult['success']) {
				return response()->json([
					'success' => false,
					'error' => 'Failed to apply change: ' . $writeResult['error'],
				], 500);
			}

			$change->update([
				'status' => 'applied',
				'backup_path' => $writeResult['backup_path'],
			]);

			AgentLog::create([
				'agent_session_id' => $change->agent_session_id,
				'type' => 'fix_applied',
				'message' => "Approved and applied fix to {$change->file_path}",
				'data' => [
					'file_path' => $change->file_path,
					'change_id' => $change->id,
				],
			]);

			return response()->json([
				'success' => true,
				'result' => [
					'change_id' => $change->id,
					'status' => $change->status,
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to approve file change', [
				'change_id' => $changeId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to approve change: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Reject a file change (review mode)
	 */
	public function rejectChange(Request $request, string $changeId)
	{
		try {
			$userId = auth()->id();
			$change = AgentFileChange::where('id', $changeId)
				->whereHas('agentSession', function ($query) use ($userId) {
					$query->where('user_id', $userId);
				})
				->first();

			if (!$change) {
				return response()->json([
					'success' => false,
					'error' => 'File change not found',
				], 404);
			}

			if (!$change->isPending()) {
				return response()->json([
					'success' => false,
					'error' => 'File change is not pending approval',
				], 400);
			}

			$rejectionReason = $request->input('reason', 'Rejected by user');

			$change->update([
				'status' => 'rejected',
				'rejection_reason' => $rejectionReason,
			]);

			AgentLog::create([
				'agent_session_id' => $change->agent_session_id,
				'type' => 'warning',
				'message' => "Rejected fix for {$change->file_path}",
				'data' => [
					'file_path' => $change->file_path,
					'change_id' => $change->id,
					'reason' => $rejectionReason,
				],
			]);

			return response()->json([
				'success' => true,
				'result' => [
					'change_id' => $change->id,
					'status' => $change->status,
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to reject file change', [
				'change_id' => $changeId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to reject change: ' . $e->getMessage(),
			], 500);
		}
	}
}

