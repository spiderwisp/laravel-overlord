<?php

namespace Spiderwisp\LaravelOverlord\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spiderwisp\LaravelOverlord\Models\Issue;

class IssuesController extends Controller
{
	/**
	 * Get sanitized error message for production
	 * 
	 * @param \Exception $e
	 * @param string $defaultMessage
	 * @return string
	 */
	protected function getErrorMessage(\Exception $e, string $defaultMessage): string
	{
		return config('app.debug')
			? $defaultMessage . ': ' . $e->getMessage()
			: $defaultMessage;
	}
	/**
	 * List issues with filters
	 */
	public function index(Request $request)
	{
		try {
			// Check if table exists before querying
			if (!Schema::hasTable('overlord_issues')) {
				return response()->json([
					'success' => true,
					'result' => (object) [
						'data' => [],
						'current_page' => 1,
						'last_page' => 1,
						'per_page' => 20,
						'total' => 0,
					],
				]);
			}

			$query = Issue::with(['creator', 'assignee', 'resolvedBy', 'closedBy']);

			// Apply filters
			if ($request->has('status') && $request->input('status') !== 'all') {
				$query->where('status', $request->input('status'));
			}

			if ($request->has('priority') && $request->input('priority') !== 'all') {
				$query->where('priority', $request->input('priority'));
			}

			if ($request->has('assignee_id')) {
				$assigneeId = $request->input('assignee_id');
				if ($assigneeId === 'unassigned') {
					$query->whereNull('assignee_id');
				} elseif ($assigneeId) {
					$query->where('assignee_id', $assigneeId);
				}
			}

			if ($request->has('creator_id')) {
				$query->where('creator_id', $request->input('creator_id'));
			}

			if ($request->has('source_type') && $request->input('source_type') !== 'all') {
				$query->where('source_type', $request->input('source_type'));
			}

			if ($request->has('search') && $request->input('search')) {
				$search = $request->input('search');
				$query->where(function ($q) use ($search) {
					$q->where('title', 'like', "%{$search}%")
						->orWhere('description', 'like', "%{$search}%");
				});
			}

			// Sorting
			$sortBy = $request->input('sort_by', 'created_at');
			$sortOrder = $request->input('sort_order', 'desc');
			$query->orderBy($sortBy, $sortOrder);

			// Pagination
			$perPage = min($request->input('per_page', 20), 100);
			$issues = $query->paginate($perPage);

			return response()->json([
				'success' => true,
				'result' => $issues,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to list issues', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			// Return empty result instead of 500 to prevent page crashes
			return response()->json([
				'success' => true,
				'result' => (object) [
					'data' => [],
					'current_page' => 1,
					'last_page' => 1,
					'per_page' => 20,
					'total' => 0,
				],
			]);
		}
	}

	/**
	 * Get single issue with relationships
	 */
	public function show($id)
	{
		try {
			$issue = Issue::with(['creator', 'assignee', 'resolvedBy', 'closedBy'])->find($id);

			if (!$issue) {
				return response()->json([
					'success' => false,
					'error' => 'Issue not found',
				], 404);
			}

			return response()->json([
				'success' => true,
				'result' => $issue,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get issue', [
				'id' => $id,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to get issue'),
			], 500);
		}
	}

	/**
	 * Create new issue
	 */
	public function store(Request $request)
	{
		try {
			$request->validate([
				'title' => 'required|string|max:255',
				'description' => 'nullable|string',
				'status' => 'nullable|in:open,in_progress,resolved,closed',
				'priority' => 'nullable|in:low,medium,high,critical',
				'assignee_id' => 'nullable|integer|exists:users,id',
				'source_type' => 'nullable|string|max:50',
				'source_id' => 'nullable|string|max:255',
				'source_data' => 'nullable|array',
				'tags' => 'nullable|array',
			]);

			$userId = auth()->id();

			$issue = Issue::create([
				'title' => $request->input('title'),
				'description' => $request->input('description'),
				'status' => $request->input('status', 'open'),
				'priority' => $request->input('priority', 'medium'),
				'creator_id' => $userId,
				'assignee_id' => $request->input('assignee_id'),
				'source_type' => $request->input('source_type'),
				'source_id' => $request->input('source_id'),
				'source_data' => $request->input('source_data'),
				'tags' => $request->input('tags'),
			]);

			// Get the issue ID before refreshing
			$issueId = $issue->id;

			// Reload the issue with relationships using a fresh query
			// This ensures Laravel's relationship discovery works properly
			$issue = Issue::with(['creator', 'assignee', 'resolvedBy', 'closedBy'])->find($issueId);

			// If that fails, try loading relationships on the instance
			if (!$issue) {
				$issue = Issue::find($issueId);
				if ($issue) {
					try {
						$issue->load(['creator', 'assignee', 'resolvedBy', 'closedBy']);
					} catch (\Exception $e) {
						// If relationships can't be loaded, return issue without them
						Log::warning('Could not load relationships for issue', [
							'issue_id' => $issueId,
							'error' => $e->getMessage(),
						]);
					}
				}
			}

			return response()->json([
				'success' => true,
				'result' => $issue,
			], 201);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return response()->json([
				'success' => false,
				'error' => 'Validation failed',
				'errors' => $e->errors(),
			], 422);
		} catch (\Exception $e) {
			Log::error('Failed to create issue', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to create issue'),
			], 500);
		}
	}

	/**
	 * Update issue
	 */
	public function update(Request $request, $id)
	{
		try {
			$userId = auth()->id();
			$issue = Issue::find($id);

			if (!$issue) {
				return response()->json([
					'success' => false,
					'error' => 'Issue not found',
				], 404);
			}

			// SECURITY: Check ownership - only creator, assignee, or admin can update
			if ($userId && $issue->creator_id !== $userId && $issue->assignee_id !== $userId) {
				// Allow if user is assignee or creator, otherwise check if admin (optional)
				// For now, we'll allow updates if user is creator or assignee
				return response()->json([
					'success' => false,
					'error' => 'Unauthorized. You can only update issues you created or are assigned to.',
				], 403);
			}

			$request->validate([
				'title' => 'sometimes|required|string|max:255',
				'description' => 'nullable|string',
				'status' => 'nullable|in:open,in_progress,resolved,closed',
				'priority' => 'nullable|in:low,medium,high,critical',
				'assignee_id' => 'nullable|integer|exists:users,id',
				'source_type' => 'nullable|string|max:50',
				'source_id' => 'nullable|string|max:255',
				'source_data' => 'nullable|array',
				'tags' => 'nullable|array',
			]);

			$issue->update($request->only([
				'title',
				'description',
				'status',
				'priority',
				'assignee_id',
				'source_type',
				'source_id',
				'source_data',
				'tags',
			]));

			$issue->load(['creator', 'assignee', 'resolvedBy', 'closedBy']);

			return response()->json([
				'success' => true,
				'result' => $issue,
			]);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return response()->json([
				'success' => false,
				'error' => 'Validation failed',
				'errors' => $e->errors(),
			], 422);
		} catch (\Exception $e) {
			Log::error('Failed to update issue', [
				'id' => $id,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to update issue'),
			], 500);
		}
	}

	/**
	 * Mark issue as resolved
	 */
	public function resolve(Request $request, $id)
	{
		try {
			$userId = auth()->id();
			$issue = Issue::find($id);

			if (!$issue) {
				return response()->json([
					'success' => false,
					'error' => 'Issue not found',
				], 404);
			}

			// SECURITY: Check ownership - only creator, assignee, or admin can resolve
			if ($userId && $issue->creator_id !== $userId && $issue->assignee_id !== $userId) {
				return response()->json([
					'success' => false,
					'error' => 'Unauthorized. You can only resolve issues you created or are assigned to.',
				], 403);
			}

			$issue->resolve($userId);
			$issue->load(['creator', 'assignee', 'resolvedBy', 'closedBy']);

			return response()->json([
				'success' => true,
				'result' => $issue,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to resolve issue', [
				'id' => $id,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to resolve issue'),
			], 500);
		}
	}

	/**
	 * Mark issue as closed
	 */
	public function close(Request $request, $id)
	{
		try {
			$userId = auth()->id();
			$issue = Issue::find($id);

			if (!$issue) {
				return response()->json([
					'success' => false,
					'error' => 'Issue not found',
				], 404);
			}

			// SECURITY: Check ownership - only creator, assignee, or admin can close
			if ($userId && $issue->creator_id !== $userId && $issue->assignee_id !== $userId) {
				return response()->json([
					'success' => false,
					'error' => 'Unauthorized. You can only close issues you created or are assigned to.',
				], 403);
			}

			$issue->close($userId);
			$issue->load(['creator', 'assignee', 'resolvedBy', 'closedBy']);

			return response()->json([
				'success' => true,
				'result' => $issue,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to close issue', [
				'id' => $id,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to close issue'),
			], 500);
		}
	}

	/**
	 * Reopen issue
	 */
	public function reopen(Request $request, $id)
	{
		try {
			$issue = Issue::find($id);

			if (!$issue) {
				return response()->json([
					'success' => false,
					'error' => 'Issue not found',
				], 404);
			}

			$issue->reopen();
			$issue->load(['creator', 'assignee', 'resolvedBy', 'closedBy']);

			return response()->json([
				'success' => true,
				'result' => $issue,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to reopen issue', [
				'id' => $id,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to reopen issue'),
			], 500);
		}
	}

	/**
	 * Assign/unassign issue
	 */
	public function assign(Request $request, $id)
	{
		try {
			$userId = auth()->id();
			$issue = Issue::find($id);

			if (!$issue) {
				return response()->json([
					'success' => false,
					'error' => 'Issue not found',
				], 404);
			}

			// SECURITY: Check ownership - only creator or admin can assign
			if ($userId && $issue->creator_id !== $userId) {
				return response()->json([
					'success' => false,
					'error' => 'Unauthorized. You can only assign issues you created.',
				], 403);
			}

			$request->validate([
				'user_id' => 'nullable|integer|exists:users,id',
			]);

			$userId = $request->input('user_id');

			if ($userId) {
				$issue->assignTo($userId);
			} else {
				$issue->unassign();
			}

			$issue->load(['creator', 'assignee', 'resolvedBy', 'closedBy']);

			return response()->json([
				'success' => true,
				'result' => $issue,
			]);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return response()->json([
				'success' => false,
				'error' => 'Validation failed',
				'errors' => $e->errors(),
			], 422);
		} catch (\Exception $e) {
			Log::error('Failed to assign issue', [
				'id' => $id,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to assign issue'),
			], 500);
		}
	}

	/**
	 * Delete issue
	 */
	public function delete($id)
	{
		try {
			$userId = auth()->id();
			$issue = Issue::find($id);

			if (!$issue) {
				return response()->json([
					'success' => false,
					'error' => 'Issue not found',
				], 404);
			}

			// SECURITY: Check ownership - only creator can delete
			if ($userId && $issue->creator_id !== $userId) {
				return response()->json([
					'success' => false,
					'error' => 'Unauthorized. You can only delete issues you created.',
				], 403);
			}

			$issue->delete();

			return response()->json([
				'success' => true,
				'message' => 'Issue deleted successfully',
			]);
		} catch (\Exception $e) {
			Log::error('Failed to delete issue', [
				'id' => $id,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to delete issue'),
			], 500);
		}
	}

	/**
	 * Get list of users for assignee dropdown.
	 */
	public function users(Request $request)
	{
		try {
			$userModel = config('laravel-overlord.user_model', \App\Models\User::class);

			// Check if user model table exists
			try {
				$userTable = (new $userModel)->getTable();
				if (!Schema::hasTable($userTable)) {
					return response()->json([
						'success' => true,
						'users' => [],
					]);
				}
			} catch (\Exception $e) {
				// If we can't get the table name, return empty array
				return response()->json([
					'success' => true,
					'users' => [],
				]);
			}

			// SECURITY: Add limit to prevent returning all users
			$limit = min((int) $request->input('limit', 100), 500); // Max 500 users
			$users = $userModel::select('id', 'name', 'email')
				->orderBy('name')
				->limit($limit)
				->get();

			return response()->json([
				'success' => true,
				'users' => $users,
				'count' => $users->count(),
				'limit' => $limit,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to fetch users for issues', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
			// Return empty array instead of error to prevent frontend failures
			return response()->json([
				'success' => true,
				'users' => [],
			]);
		}
	}

	/**
	 * Get issue statistics
	 */
	public function stats()
	{
		try {
			// Check if table exists before querying
			if (!Schema::hasTable('overlord_issues')) {
				return response()->json([
					'success' => true,
					'result' => [
						'total' => 0,
						'by_status' => [
							'open' => 0,
							'in_progress' => 0,
							'resolved' => 0,
							'closed' => 0,
						],
						'by_priority' => [
							'low' => 0,
							'medium' => 0,
							'high' => 0,
							'critical' => 0,
						],
						'unassigned' => 0,
					],
				]);
			}

			$stats = [
				'total' => Issue::count(),
				'by_status' => [
					'open' => Issue::open()->count(),
					'in_progress' => Issue::inProgress()->count(),
					'resolved' => Issue::resolved()->count(),
					'closed' => Issue::closed()->count(),
				],
				'by_priority' => [
					'low' => Issue::byPriority('low')->count(),
					'medium' => Issue::byPriority('medium')->count(),
					'high' => Issue::byPriority('high')->count(),
					'critical' => Issue::byPriority('critical')->count(),
				],
				'unassigned' => Issue::whereNull('assignee_id')->count(),
			];

			return response()->json([
				'success' => true,
				'result' => $stats,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get issue stats', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			// Return empty stats instead of 500 to prevent page crashes
			return response()->json([
				'success' => true,
				'result' => [
					'total' => 0,
					'by_status' => [
						'open' => 0,
						'in_progress' => 0,
						'resolved' => 0,
						'closed' => 0,
					],
					'by_priority' => [
						'low' => 0,
						'medium' => 0,
						'high' => 0,
						'critical' => 0,
					],
					'unassigned' => 0,
				],
			]);
		}
	}
}