import { ref, onUnmounted } from 'vue';
import axios from 'axios';
import Swal from '../utils/swalConfig';
import { useOverlordApi } from './useOverlordApi';

export function useTerminalDatabaseScans(api, { isTabOpen, closeTab, ensureTabOpen, switchTab }) {
	const activeDatabaseScanId = ref(null);
	const databaseScanPollingInterval = ref(null);

	// Handle database scan start from config component
	async function handleStartDatabaseScan(config) {
		try {
			// Check for existing issues first
			const existingIssuesResponse = await axios.get(api.databaseScan.hasExistingIssues());
			let clearExisting = false;
			
			if (existingIssuesResponse.data && existingIssuesResponse.data.success && existingIssuesResponse.data.result.has_issues) {
				const unresolvedCount = existingIssuesResponse.data.result.unresolved_count;
				const result = await Swal.fire({
					icon: 'question',
					title: 'Existing Database Scan Issues Found',
					html: `You have <strong>${unresolvedCount}</strong> unresolved database scan issue${unresolvedCount !== 1 ? 's' : ''} from previous scans.<br><br>Would you like to clear them before starting a new scan?`,
					showCancelButton: true,
					confirmButtonText: 'Clear and Scan',
					cancelButtonText: 'Keep and Scan',
				});
				
				if (result.isConfirmed) {
					clearExisting = true;
				}
			}
			
			// Close config tab and open scan results tab
			closeTab('database-scan-config');
			ensureTabOpen('database-scan-results');
			
			const response = await axios.post(api.databaseScan.start(), {
				clear_existing: clearExisting,
				type: config.type,
				mode: config.mode,
				tables: config.tables || [],
				sample_size: config.sample_size || 100,
			});
			
			// Add logging to debug production issue
			console.log('Database scan start response:', response.data);
			
			// Check if we have a scan_id even if success is missing
			const scanId = response.data?.result?.scan_id || response.data?.scan_id;
			if (response.data && (response.data.success || scanId)) {
				if (scanId) {
					activeDatabaseScanId.value = scanId;
					
					// Start polling immediately
					startDatabaseScanPolling();
					
					// Request notification permission
					if ('Notification' in window && Notification.permission === 'default') {
						Notification.requestPermission();
					}
					return; // Exit early on success
				}
			}
			
			// Only show error if we truly don't have a scan_id
			const errorCode = response.data?.code;
			let errorMessage = response.data?.error || 'Unknown error';
			
			if (errorCode === 'QUOTA_EXCEEDED') {
				errorMessage = response.data.error || 'Unknown error';
			}
			
			Swal.fire({
				icon: 'error',
				title: 'Failed to start database scan',
				text: errorMessage,
				toast: true,
				position: 'top-end',
				showConfirmButton: false,
				timer: 3000,
			});
		} catch (error) {
			console.error('Failed to start database scan:', error);
			// Check for QUOTA_EXCEEDED code and use error message from response
			const errorCode = error.response?.data?.code;
			let errorMessage = error.response?.data?.error || 'Unknown error';
			
			if (errorCode === 'QUOTA_EXCEEDED') {
				errorMessage = error.response?.data?.error || 'Unknown error';
			}
			
			Swal.fire({
				icon: 'error',
				title: 'Failed to start database scan',
				text: errorMessage,
				toast: true,
				position: 'top-end',
				showConfirmButton: false,
				timer: 3000,
			});
		}
	}

	function startDatabaseScanPolling() {
		if (databaseScanPollingInterval.value) {
			clearInterval(databaseScanPollingInterval.value);
		}
		
		databaseScanPollingInterval.value = setInterval(async () => {
			if (!activeDatabaseScanId.value) {
				stopDatabaseScanPolling();
				return;
			}
			
			try {
				const response = await axios.get(api.databaseScan.status(activeDatabaseScanId.value));
				if (response.data && response.data.success) {
					const status = response.data.result;
					
					if (status.status === 'completed') {
						stopDatabaseScanPolling();
						
						// Close config tab if it's still open
						if (isTabOpen('database-scan-config')) {
							closeTab('database-scan-config');
						}
						
						// Ensure scan results tab is open and active
						ensureTabOpen('database-scan-results');
						switchTab('database-scan-results');
						
						// Show browser notification
						if ('Notification' in window && Notification.permission === 'granted') {
							const notification = new Notification('Database Scan Complete', {
								body: `Scan completed successfully. Found ${status.total_issues_found || 0} issues. Click to view results.`,
								icon: '/favicon.ico',
							});
							
							notification.onclick = () => {
								window.focus();
								ensureTabOpen('database-scan-results');
								switchTab('database-scan-results');
								notification.close();
							};
						}
						
						// Show toast notification with proper styling
						Swal.fire({
							icon: 'success',
							title: 'Database Scan Complete',
							text: `Found ${status.total_issues_found || 0} issue${status.total_issues_found !== 1 ? 's' : ''}`,
							toast: true,
							position: 'top-end',
							showConfirmButton: false,
							timer: 3000,
							width: 'auto',
							padding: '1rem',
						});
						
						// No need to force reload - the component's checkStatus() will detect completion
						// and automatically load results via loadResults() in the status check handler
					} else if (status.status === 'failed') {
						stopDatabaseScanPolling();
						
						// Check if it's a rate limit error
						const isRateLimit = status.rate_limit_exceeded || 
						                   (status.error && (
						                       status.error.toLowerCase().includes('rate limit') ||
						                       status.error.toLowerCase().includes('quota exceeded') ||
						                       status.error.toLowerCase().includes('rate limit exceeded')
						                   ));
						
						const errorTitle = isRateLimit ? 'Rate Limit Exceeded' : 'Database Scan Failed';
						let errorText = status.error || (isRateLimit ? 'Rate limit exceeded. Please upgrade your plan.' : 'Unknown error');
						
						// For rate limit errors, ensure we have a proper message with link
						if (isRateLimit) {
							// Remove any existing HTML tags from the error message to avoid duplication
							const cleanError = errorText.replace(/<[^>]*>/g, '').trim();
							
							// Build the message with proper HTML link
							errorText = `${cleanError} Please upgrade your plan at <a href="https://laravel-overlord.com/signin" target="_blank" rel="noopener noreferrer">laravel-overlord.com/signin</a> to continue.`;
							
							// Add animation indicator
							errorText = `<span class="rate-limit-indicator"></span>${errorText}<span class="rate-limit-indicator"></span>`;
						}
						
						Swal.fire({
							icon: isRateLimit ? 'warning' : 'error',
							title: errorTitle,
							html: errorText,
							toast: true,
							position: 'top-end',
							showConfirmButton: false,
							timer: isRateLimit ? 6000 : 5000,
						});
					}
				}
			} catch (error) {
				console.error('Failed to check database scan status:', error);
			}
		}, 2500);
	}

	function stopDatabaseScanPolling() {
		if (databaseScanPollingInterval.value) {
			clearInterval(databaseScanPollingInterval.value);
			databaseScanPollingInterval.value = null;
		}
	}

	// Cleanup on unmount
	onUnmounted(() => {
		stopDatabaseScanPolling();
	});

	return {
		activeDatabaseScanId,
		handleStartDatabaseScan,
		startDatabaseScanPolling,
		stopDatabaseScanPolling,
	};
}

