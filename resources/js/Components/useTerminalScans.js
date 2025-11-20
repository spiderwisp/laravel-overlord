import { ref, onUnmounted } from 'vue';
import axios from 'axios';
import Swal from '../utils/swalConfig';
import { useOverlordApi } from './useOverlordApi';

export function useTerminalScans(api, { isTabOpen, closeTab, ensureTabOpen, switchTab }) {
	const activeScanId = ref(null);
	const scanPollingInterval = ref(null);

	// Handle scan start from config component
	async function handleStartScan(config) {
		try {
			// Check for existing issues first
			const existingIssuesResponse = await axios.get(api.scan.hasExistingIssues());
			let clearExisting = false;
			
			if (existingIssuesResponse.data && existingIssuesResponse.data.success && existingIssuesResponse.data.result.has_issues) {
				const unresolvedCount = existingIssuesResponse.data.result.unresolved_count;
				const result = await Swal.fire({
					icon: 'question',
					title: 'Existing Scan Issues Found',
					html: `You have <strong>${unresolvedCount}</strong> unresolved scan issue${unresolvedCount !== 1 ? 's' : ''} from previous scans.<br><br>Would you like to clear them before starting a new scan?`,
					showCancelButton: true,
					confirmButtonText: 'Clear and Scan',
					cancelButtonText: 'Keep and Scan',
					confirmButtonColor: '#3085d6',
					cancelButtonColor: '#6c757d',
				});
				
				if (result.isConfirmed) {
					clearExisting = true;
				}
			}
			
			// Close config tab and open scan results tab
			closeTab('scan-config');
			ensureTabOpen('scan-results');
			switchTab('scan-results'); // Switch to results tab immediately so user sees loading state
			
			const response = await axios.post(api.scan.start(), {
				clear_existing: clearExisting,
				mode: config.mode,
				paths: config.paths || [],
			});
			
			// Add logging to debug production issue
			console.log('Codebase scan start response:', response.data);
			
			// Check if we have a scan_id even if success is missing
			const scanId = response.data?.result?.scan_id || response.data?.scan_id;
			if (response.data && (response.data.success || scanId)) {
				if (scanId) {
					activeScanId.value = scanId;
					
					// Start polling immediately
					startScanPolling();
					
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
				title: 'Failed to start scan',
				text: errorMessage,
				toast: true,
				position: 'top-end',
				showConfirmButton: false,
				timer: 3000,
			});
		} catch (error) {
			console.error('Failed to start scan:', error);
			// Check for QUOTA_EXCEEDED code and use error message from response
			const errorCode = error.response?.data?.code;
			let errorMessage = error.response?.data?.error || 'Unknown error';
			
			if (errorCode === 'QUOTA_EXCEEDED') {
				errorMessage = error.response?.data?.error || 'Unknown error';
			}
			
			Swal.fire({
				icon: 'error',
				title: 'Failed to start scan',
				text: errorMessage,
				toast: true,
				position: 'top-end',
				showConfirmButton: false,
				timer: 3000,
			});
		}
	}

	// Start polling for scan status
	function startScanPolling() {
		// Clear any existing interval
		if (scanPollingInterval.value) {
			clearInterval(scanPollingInterval.value);
		}
		
		// Poll every 2.5 seconds
		scanPollingInterval.value = setInterval(async () => {
			if (!activeScanId.value) {
				stopScanPolling();
				return;
			}
			
			try {
				const response = await axios.get(api.scan.status(activeScanId.value));
				if (response.data && response.data.success) {
					const status = response.data.result;
					
					if (status.status === 'completed') {
						stopScanPolling();
						
						// Close config tab if it's still open
						if (isTabOpen('scan-config')) {
							closeTab('scan-config');
						}
						
						// Ensure scan results tab is open and active
						ensureTabOpen('scan-results');
						switchTab('scan-results');
						
						// Show browser notification
						if ('Notification' in window && Notification.permission === 'granted') {
							const notification = new Notification('Codebase Scan Complete', {
								body: `Scan completed successfully. Found ${status.total_issues_found || 0} issues. Click to view results.`,
								icon: '/favicon.ico',
								tag: 'scan-complete',
							});
							
							// Make notification clickable to focus the terminal
							notification.onclick = () => {
								window.focus();
								ensureTabOpen('scan-results');
								switchTab('scan-results');
								notification.close();
							};
						}
						
						// Show toast notification
						Swal.fire({
							icon: 'success',
							title: 'Scan Complete',
							text: `Found ${status.total_issues_found || 0} issues. Results tab opened.`,
							toast: true,
							position: 'top-end',
							showConfirmButton: false,
							timer: 4000,
						});
					} else if (status.status === 'failed') {
						stopScanPolling();
						
						// Check if it's a rate limit error
						const isRateLimit = status.rate_limit_exceeded || 
						                   (status.error && (
						                       status.error.toLowerCase().includes('rate limit') ||
						                       status.error.toLowerCase().includes('quota exceeded') ||
						                       status.error.toLowerCase().includes('rate limit exceeded')
						                   ));
						
						const errorTitle = isRateLimit ? 'Rate Limit Exceeded' : 'Scan Failed';
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
						
						// Show error notification
						if ('Notification' in window && Notification.permission === 'granted') {
							// Strip HTML for notification
							const plainText = errorText.replace(/<[^>]*>/g, '');
							new Notification(errorTitle, {
								body: plainText,
								icon: '/favicon.ico',
								tag: 'scan-failed',
							});
						}
						
						Swal.fire({
							icon: isRateLimit ? 'warning' : 'error',
							title: errorTitle,
							html: errorText,
							toast: true,
							position: 'top-end',
							showConfirmButton: false,
							timer: isRateLimit ? 6000 : 3000,
						});
					}
				}
			} catch (error) {
				console.error('Failed to check scan status:', error);
			}
		}, 2500);
	}

	// Stop polling for scan status
	function stopScanPolling() {
		if (scanPollingInterval.value) {
			clearInterval(scanPollingInterval.value);
			scanPollingInterval.value = null;
		}
	}

	// Handle view scan
	function handleViewScan(scanId, closeTab, ensureTabOpen, switchTab) {
		activeScanId.value = scanId;
		closeTab('scan-history');
		ensureTabOpen('scan-results');
		switchTab('scan-results');
	}

	// Handle view scan issues
	function handleViewScanIssues(scanId, closeTab, ensureTabOpen, switchTab) {
		// Open issues tab filtered by scan ID
		// For now, just open the scan results which shows issues
		handleViewScan(scanId, closeTab, ensureTabOpen, switchTab);
	}

	// Handle scan issues cleared
	function handleScanIssuesCleared(scanHistoryRef) {
		// Refresh the scan history component if it's open
		if (scanHistoryRef && scanHistoryRef.value && scanHistoryRef.value.loadHistory) {
			scanHistoryRef.value.loadHistory();
		}
	}

	// Cleanup on unmount
	onUnmounted(() => {
		stopScanPolling();
	});

	return {
		activeScanId,
		handleStartScan,
		startScanPolling,
		stopScanPolling,
		handleViewScan,
		handleViewScanIssues,
		handleScanIssuesCleared,
	};
}

