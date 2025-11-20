<?php

namespace Spiderwisp\LaravelOverlord\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Spiderwisp\LaravelOverlord\Services\BugReportService;

class BugReportController extends Controller
{
	protected $bugReportService;

	public function __construct(BugReportService $bugReportService)
	{
		$this->bugReportService = $bugReportService;
	}

	/**
	 * Submit bug report
	 */
	public function submit(Request $request)
	{
		try {
			// Validate request
			$validated = $request->validate([
				'title' => 'required|string|max:255',
				'description' => 'required|string',
				'steps_to_reproduce' => 'nullable|string',
				'error_message' => 'nullable|string',
				'stack_trace' => 'nullable|string',
				'include_system_info' => 'nullable|boolean',
				'include_environment_info' => 'nullable|boolean',
				'include_browser_info' => 'nullable|boolean',
				'include_package_version' => 'nullable|boolean',
			]);

			// Check if bug reporting is enabled
			if (!config('laravel-overlord.bug_report.enabled', true)) {
				return response()->json([
					'success' => false,
					'error' => 'Bug reporting is disabled',
				], 403);
			}

			// Prepare include options
			$includeOptions = [
				'include_system_info' => $request->input('include_system_info', false),
				'include_environment_info' => $request->input('include_environment_info', false),
				'include_browser_info' => $request->input('include_browser_info', false),
				'include_package_version' => $request->input('include_package_version', false),
			];

			// Submit bug report
			$result = $this->bugReportService->submit($validated, $request, $includeOptions);

			if ($result['success']) {
				return response()->json([
					'success' => true,
					'message' => $result['message'] ?? 'Bug report submitted successfully',
					'data' => $result['data'] ?? null,
				]);
			}

			return response()->json([
				'success' => false,
				'error' => $result['error'] ?? 'Failed to submit bug report',
			], 500);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return response()->json([
				'success' => false,
				'error' => 'Validation failed',
				'errors' => $e->errors(),
			], 422);
		} catch (\Exception $e) {
			Log::error('Bug report submission failed', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return response()->json([
				'success' => false,
				'error' => config('app.debug')
					? 'Failed to submit bug report: ' . $e->getMessage()
					: 'Failed to submit bug report',
			], 500);
		}
	}
}

