<script setup>
import TerminalHistory from './TerminalHistory.vue';
import TerminalTemplates from './TerminalTemplates.vue';
import TerminalModelDiagram from './TerminalModelDiagram.vue';
import TerminalControllers from './TerminalControllers.vue';
import TerminalRoutes from './TerminalRoutes.vue';
import TerminalClasses from './TerminalClasses.vue';
import TerminalTraits from './TerminalTraits.vue';
import TerminalServices from './TerminalServices.vue';
import TerminalRequests from './TerminalRequests.vue';
import TerminalProviders from './TerminalProviders.vue';
import TerminalMiddleware from './TerminalMiddleware.vue';
import TerminalJobs from './TerminalJobs.vue';
import TerminalExceptions from './TerminalExceptions.vue';
import TerminalCommandClasses from './TerminalCommandClasses.vue';
import TerminalMigrations from './TerminalMigrations.vue';
import TerminalCommands from './TerminalCommands.vue';
import TerminalFavorites from './TerminalFavorites.vue';
import TerminalAi from './TerminalAi.vue';
import TerminalHorizon from './TerminalHorizon.vue';
import TerminalLogs from './TerminalLogs.vue';
import TerminalIssues from './TerminalIssues.vue';
import TerminalScanConfig from './TerminalScanConfig.vue';
import TerminalScanResults from './TerminalScanResults.vue';
import TerminalScanHistory from './TerminalScanHistory.vue';
import TerminalDatabaseScanConfig from './TerminalDatabaseScanConfig.vue';
import TerminalDatabaseScanResults from './TerminalDatabaseScanResults.vue';
import TerminalDatabaseScanHistory from './TerminalDatabaseScanHistory.vue';
import TerminalDatabase from './TerminalDatabase.vue';
import TerminalSettings from './TerminalSettings.vue';
import TerminalOutput from './TerminalOutput.vue';

const props = defineProps({
	activeTab: {
		type: String,
		required: true,
	},
	isTabActive: {
		type: Function,
		required: true,
	},
	// Refs
	favoritesRef: Object,
	aiRef: Object,
	scanHistoryRef: Object,
	databaseScanHistoryRef: Object,
	outputContainerRef: Object,
	// Data
	commandInput: String,
	outputHistory: Array,
	isExecuting: Boolean,
	fontSize: Number,
	activeScanId: [String, Number],
	activeDatabaseScanId: [String, Number],
	issuePrefillData: Object,
	logsNavigateTo: Object,
	// Handlers
	useCommandFromHistory: Function,
	insertCommand: Function,
	insertCommandFromAi: Function,
	executeCommandFromAi: Function,
	executeCommandFromFavorite: Function,
	handleAddToFavorites: Function,
	handleNavigateToReference: Function,
	handleNavigateToSource: Function,
	handleCreateIssueFromLogs: Function,
	handleCreateIssueFromTerminal: Function,
	handleCreateIssueFromScan: Function,
	handleStartScan: Function,
	handleStartDatabaseScan: Function,
	handleViewScan: Function,
	handleViewScanIssues: Function,
	handleScanIssuesCleared: Function,
	handleViewDatabaseScan: Function,
	handleViewDatabaseScanIssues: Function,
	handleDatabaseIssuesCleared: Function,
	closeTab: Function,
	clearTerminal: Function,
	clearSession: Function,
	closeTerminal: Function,
	loadIssuesStats: Function,
});
</script>

<template>
	<!-- History View -->
	<TerminalHistory
		:visible="isTabActive('history')"
		@use-command="useCommandFromHistory"
		@close="closeTab('history')"
	/>

	<!-- Templates/Snippets Panel -->
	<TerminalTemplates
		:visible="isTabActive('templates')"
		:current-command="commandInput"
		@insert-command="insertCommand"
		@add-to-favorites="handleAddToFavorites"
		@close="closeTab('templates')"
	/>

	<!-- Model Relationships Diagram -->
	<TerminalModelDiagram
		:visible="isTabActive('model-diagram')"
		@close="closeTab('model-diagram')"
	/>

	<!-- Controllers View -->
	<TerminalControllers
		:visible="isTabActive('controllers')"
		@close="closeTab('controllers')"
		@navigate-to="handleNavigateToReference"
	/>

	<!-- Routes View -->
	<TerminalRoutes
		:visible="isTabActive('routes')"
		@close="closeTab('routes')"
		@navigate-to="handleNavigateToReference"
	/>

	<!-- Classes View -->
	<TerminalClasses
		:visible="isTabActive('classes')"
		@close="closeTab('classes')"
	/>

	<!-- Traits View -->
	<TerminalTraits
		:visible="isTabActive('traits')"
		@close="closeTab('traits')"
	/>

	<!-- Services View -->
	<TerminalServices
		:visible="isTabActive('services')"
		@close="closeTab('services')"
	/>

	<!-- Requests View -->
	<TerminalRequests
		:visible="isTabActive('requests')"
		@close="closeTab('requests')"
	/>

	<!-- Providers View -->
	<TerminalProviders
		:visible="isTabActive('providers')"
		@close="closeTab('providers')"
	/>

	<!-- Middleware View -->
	<TerminalMiddleware
		:visible="isTabActive('middleware')"
		@close="closeTab('middleware')"
	/>

	<!-- Jobs View -->
	<TerminalJobs
		:visible="isTabActive('jobs')"
		@close="closeTab('jobs')"
	/>

	<!-- Exceptions View -->
	<TerminalExceptions
		:visible="isTabActive('exceptions')"
		@close="closeTab('exceptions')"
	/>

	<!-- Command Classes View -->
	<TerminalCommandClasses
		:visible="isTabActive('command-classes')"
		@close="closeTab('command-classes')"
	/>

	<!-- Migrations View -->
	<TerminalMigrations
		:visible="isTabActive('migrations')"
		@close="closeTab('migrations')"
	/>

	<!-- Commands View -->
	<TerminalCommands
		:visible="isTabActive('commands')"
		@close="closeTab('commands')"
		@add-to-favorites="handleAddToFavorites"
	/>

	<!-- Favorites View -->
	<TerminalFavorites
		ref="favoritesRef"
		:visible="isTabActive('favorites')"
		:current-command="commandInput"
		@insert-command="insertCommand"
		@execute-command="executeCommandFromFavorite"
		@close="closeTab('favorites')"
	/>

	<!-- AI View -->
	<TerminalAi
		ref="aiRef"
		:visible="isTabActive('ai')"
		:hide-input="true"
		@insert-command="insertCommandFromAi"
		@execute-command="executeCommandFromAi"
		@close="closeTab('ai')"
	/>

	<!-- Horizon View -->
	<TerminalHorizon
		:visible="isTabActive('horizon')"
		@close="closeTab('horizon')"
	/>

	<!-- Logs View -->
	<TerminalLogs
		:visible="isTabActive('logs')"
		:navigate-to="logsNavigateTo"
		@close="closeTab('logs')"
		@insert-command="insertCommandFromAi"
		@execute-command="executeCommandFromAi"
		@create-issue="handleCreateIssueFromLogs"
	/>

	<!-- Issues View -->
	<TerminalIssues
		:visible="isTabActive('issues')"
		:prefill-data="issuePrefillData"
		@close="closeTab('issues')"
		@create-issue="(issue) => {}"
		@navigate-to-source="handleNavigateToSource"
		@issue-updated="loadIssuesStats"
	/>

	<!-- Scan Results View -->
	<TerminalScanConfig
		v-if="isTabActive('scan-config')"
		:visible="true"
		@close="closeTab('scan-config')"
		@start-scan="handleStartScan"
	/>
	<TerminalScanResults
		v-if="isTabActive('scan-results')"
		:visible="true"
		:scan-id="activeScanId"
		@close="closeTab('scan-results')"
		@create-issue="handleCreateIssueFromScan"
		@issues-cleared="handleScanIssuesCleared"
	/>
	<TerminalScanHistory
		v-if="isTabActive('scan-history')"
		ref="scanHistoryRef"
		:visible="true"
		@close="closeTab('scan-history')"
		@view-scan="handleViewScan"
		@view-issues="handleViewScanIssues"
	/>

	<!-- Database Scan Views -->
	<TerminalDatabaseScanConfig
		v-if="isTabActive('database-scan-config')"
		:visible="true"
		@close="closeTab('database-scan-config')"
		@start-scan="handleStartDatabaseScan"
	/>
	<TerminalDatabaseScanResults
		v-if="isTabActive('database-scan-results')"
		:visible="true"
		:scan-id="activeDatabaseScanId"
		@close="closeTab('database-scan-results')"
		@create-issue="handleCreateIssueFromScan"
		@issues-cleared="handleDatabaseIssuesCleared"
	/>
	<TerminalDatabaseScanHistory
		v-if="isTabActive('database-scan-history')"
		ref="databaseScanHistoryRef"
		:visible="true"
		@close="closeTab('database-scan-history')"
		@view-scan="handleViewDatabaseScan"
		@view-issues="handleViewDatabaseScanIssues"
	/>
	
	<TerminalDatabase
		v-if="isTabActive('database')"
		:visible="true"
		@close="closeTab('database')"
	/>

	<!-- Settings View -->
	<TerminalSettings
		v-if="isTabActive('settings')"
		:visible="true"
		:is-modal="false"
		@close="closeTab('settings')"
	/>

	<!-- Terminal Output Area -->
	<TerminalOutput
		v-if="isTabActive('terminal')"
		ref="outputContainerRef"
		:output-history="outputHistory"
		:is-executing="isExecuting"
		:font-size="fontSize"
		:show-actions="isTabActive('terminal')"
		@insert-command="insertCommandFromAi"
		@execute-command="executeCommandFromAi"
		@create-issue="handleCreateIssueFromTerminal"
		@clear-output="clearTerminal"
		@clear-session="clearSession"
		@close-terminal="closeTerminal"
	/>
</template>

