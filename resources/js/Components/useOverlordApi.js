/**
 * Get the API base URL for Laravel Overlord endpoints
 * Reads from Inertia shared props or falls back to default
 */
export function useOverlordApi() {
    let routePrefix = '/admin/overlord';
    
    // Try to get from Inertia shared props first (if available)
    // Check if Inertia is available at runtime
    if (typeof window !== 'undefined' && window.Inertia) {
        try {
            // If Inertia is available, try to get props from the page
            const page = window.Inertia?.page;
            if (page && page.props && page.props.overlord) {
                routePrefix = page.props.overlord.routePrefix || routePrefix;
            }
        } catch (e) {
            // Continue with fallbacks
        }
    }
    
    // Fallback to window config or default
    routePrefix = routePrefix 
        || (typeof window !== 'undefined' && window.overlordConfig?.routePrefix)
        || '/admin/overlord';
    
    // Ensure it starts with / and doesn't end with /
    const baseUrl = routePrefix.startsWith('/') 
        ? routePrefix 
        : '/' + routePrefix;
    
    const cleanBaseUrl = baseUrl.endsWith('/') 
        ? baseUrl.slice(0, -1) 
        : baseUrl;
    
    return {
        baseUrl: cleanBaseUrl,
        url: (endpoint) => {
            // Remove leading slash from endpoint if present
            const cleanEndpoint = endpoint.startsWith('/') 
                ? endpoint.slice(1) 
                : endpoint;
            return `${cleanBaseUrl}/${cleanEndpoint}`;
        },
        // AI endpoints
        ai: {
            chat: () => `${cleanBaseUrl}/ai/chat`,
            models: () => `${cleanBaseUrl}/ai/models`,
            checkModel: () => `${cleanBaseUrl}/ai/models/check`,
            status: () => `${cleanBaseUrl}/ai/status`,
            apiKeyStatus: () => `${cleanBaseUrl}/ai/api-key-status`,
            getApiKeySetting: () => `${cleanBaseUrl}/ai/api-key-setting`,
            updateApiKeySetting: () => `${cleanBaseUrl}/ai/api-key-setting`,
            deleteApiKeySetting: () => `${cleanBaseUrl}/ai/api-key-setting`,
        },
        // Shell endpoints
        shell: {
            execute: () => `${cleanBaseUrl}/shell/execute`,
        },
        // Database endpoints
        database: {
            tables: () => `${cleanBaseUrl}/database/tables`,
            tableStructure: (table) => `${cleanBaseUrl}/database/tables/${table}/structure`,
            tableData: (table, params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/database/tables/${table}/data${queryString ? '?' + queryString : ''}`;
            },
            tableStats: (table) => `${cleanBaseUrl}/database/tables/${table}/stats`,
            executeQuery: () => `${cleanBaseUrl}/database/query`,
            exportQuery: () => `${cleanBaseUrl}/database/query/export`,
            getRow: (table, params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/database/tables/${table}/row${queryString ? '?' + queryString : ''}`;
            },
            createRow: (table) => `${cleanBaseUrl}/database/tables/${table}/row`,
            updateRow: (table, id) => `${cleanBaseUrl}/database/tables/${table}/row/${id}`,
            deleteRow: (table, id) => `${cleanBaseUrl}/database/tables/${table}/row/${id}`,
        },
        // Horizon endpoints
        horizon: {
            check: () => `${cleanBaseUrl}/horizon/check`,
            stats: () => `${cleanBaseUrl}/horizon/stats`,
            jobs: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/horizon/jobs${queryString ? '?' + queryString : ''}`;
            },
            jobDetails: (id) => `${cleanBaseUrl}/horizon/jobs/${id}`,
            retryJob: (id) => `${cleanBaseUrl}/horizon/jobs/${id}/retry`,
            deleteJob: (id) => `${cleanBaseUrl}/horizon/jobs/${id}`,
            executeJob: (id) => `${cleanBaseUrl}/horizon/jobs/${id}/execute`,
            createJob: () => `${cleanBaseUrl}/horizon/jobs/create`,
            // Horizon management commands
            pause: () => `${cleanBaseUrl}/horizon/pause`,
            continue: () => `${cleanBaseUrl}/horizon/continue`,
            terminate: () => `${cleanBaseUrl}/horizon/terminate`,
            restart: () => `${cleanBaseUrl}/horizon/restart`,
            clear: () => `${cleanBaseUrl}/horizon/clear`,
            snapshot: () => `${cleanBaseUrl}/horizon/snapshot`,
            status: () => `${cleanBaseUrl}/horizon/status`,
            supervisors: () => `${cleanBaseUrl}/horizon/supervisors`,
            config: () => `${cleanBaseUrl}/horizon/config`,
            systemInfo: () => `${cleanBaseUrl}/horizon/system-info`,
        },
        // Log endpoints
        logs: {
            list: () => `${cleanBaseUrl}/logs/list`,
            content: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/logs/content${queryString ? '?' + queryString : ''}`;
            },
            surrounding: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/logs/surrounding${queryString ? '?' + queryString : ''}`;
            },
            search: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/logs/search${queryString ? '?' + queryString : ''}`;
            },
            stats: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/logs/stats${queryString ? '?' + queryString : ''}`;
            },
        },
        // Issues endpoints
        issues: {
            list: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/issues${queryString ? '?' + queryString : ''}`;
            },
            show: (id) => `${cleanBaseUrl}/issues/${id}`,
            create: () => `${cleanBaseUrl}/issues`,
            update: (id) => `${cleanBaseUrl}/issues/${id}`,
            resolve: (id) => `${cleanBaseUrl}/issues/${id}/resolve`,
            close: (id) => `${cleanBaseUrl}/issues/${id}/close`,
            reopen: (id) => `${cleanBaseUrl}/issues/${id}/reopen`,
            assign: (id) => `${cleanBaseUrl}/issues/${id}/assign`,
            delete: (id) => `${cleanBaseUrl}/issues/${id}`,
            stats: () => `${cleanBaseUrl}/issues/stats`,
            users: () => `${cleanBaseUrl}/issues/users`,
        },
        // Scan endpoints
        scan: {
            fileTree: () => `${cleanBaseUrl}/scan/file-tree`,
            start: () => `${cleanBaseUrl}/scan/start`,
            history: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/scan/history${queryString ? '?' + queryString : ''}`;
            },
            historyDetails: (scanId) => `${cleanBaseUrl}/scan/history/${scanId}`,
            status: (scanId) => `${cleanBaseUrl}/scan/${scanId}/status`,
            results: (scanId) => `${cleanBaseUrl}/scan/${scanId}/results`,
            hasExistingIssues: () => `${cleanBaseUrl}/scan/issues/has-existing`,
            issues: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/scan/issues${queryString ? '?' + queryString : ''}`;
            },
            resolveIssue: (issueId) => `${cleanBaseUrl}/scan/issues/${issueId}/resolve`,
            unresolveIssue: (issueId) => `${cleanBaseUrl}/scan/issues/${issueId}/unresolve`,
            clearIssues: () => `${cleanBaseUrl}/scan/issues`,
        },
        // Database scan endpoints
        databaseScan: {
            tables: () => `${cleanBaseUrl}/scan/database/tables`,
            start: () => `${cleanBaseUrl}/scan/database/start`,
            history: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/scan/database/history${queryString ? '?' + queryString : ''}`;
            },
            historyDetails: (scanId) => `${cleanBaseUrl}/scan/database/history/${scanId}`,
            status: (scanId) => `${cleanBaseUrl}/scan/database/${scanId}/status`,
            results: (scanId) => `${cleanBaseUrl}/scan/database/${scanId}/results`,
            hasExistingIssues: () => `${cleanBaseUrl}/scan/database/issues/has-existing`,
            issues: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/scan/database/issues${queryString ? '?' + queryString : ''}`;
            },
            resolveIssue: (issueId) => `${cleanBaseUrl}/scan/database/issues/${issueId}/resolve`,
            unresolveIssue: (issueId) => `${cleanBaseUrl}/scan/database/issues/${issueId}/unresolve`,
            clearIssues: () => `${cleanBaseUrl}/scan/database/issues`,
        },
        // Migration endpoints
        migrations: {
            list: () => `${cleanBaseUrl}/migrations`,
            show: (migration) => `${cleanBaseUrl}/migrations/${migration}`,
            run: () => `${cleanBaseUrl}/migrations/run`,
            rollback: () => `${cleanBaseUrl}/migrations/rollback`,
            status: () => `${cleanBaseUrl}/migrations/status`,
            previewRun: () => `${cleanBaseUrl}/migrations/preview-run`,
            previewRollback: () => `${cleanBaseUrl}/migrations/preview-rollback`,
            generate: () => `${cleanBaseUrl}/migrations/generate`,
            create: () => `${cleanBaseUrl}/migrations/create`,
        },
        // Routes endpoints
        routes: {
            list: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/routes${queryString ? '?' + queryString : ''}`;
            },
            details: (identifier) => `${cleanBaseUrl}/routes/${encodeURIComponent(identifier)}`,
            generateUrl: () => `${cleanBaseUrl}/routes/generate-url`,
            test: () => `${cleanBaseUrl}/routes/test`,
        },
        // Controllers endpoints
        controllers: {
            list: () => `${cleanBaseUrl}/controllers`,
            methodSource: (controller, method) => {
                const params = new URLSearchParams({ controller, method });
                return `${cleanBaseUrl}/controllers/method-source?${params.toString()}`;
            },
        },
        // Traits endpoints
        traits: {
            list: () => `${cleanBaseUrl}/traits`,
        },
        // Services endpoints
        services: {
            list: () => `${cleanBaseUrl}/services`,
        },
        // Requests endpoints
        requests: {
            list: () => `${cleanBaseUrl}/requests`,
        },
        // Providers endpoints
        providers: {
            list: () => `${cleanBaseUrl}/providers`,
        },
        // Middleware endpoints
        middleware: {
            list: () => `${cleanBaseUrl}/middleware`,
        },
        // Jobs endpoints
        jobs: {
            list: () => `${cleanBaseUrl}/jobs`,
        },
        // Exceptions endpoints
        exceptions: {
            list: () => `${cleanBaseUrl}/exceptions`,
        },
        // Command Classes endpoints
        commandClasses: {
            list: () => `${cleanBaseUrl}/command-classes`,
        },
        // Bug report endpoints
        bugReport: {
            submit: () => `${cleanBaseUrl}/bug-report/submit`,
        },
        // Mermaid diagram endpoints
        mermaid: {
            diagram: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/mermaid/diagram${queryString ? '?' + queryString : ''}`;
            },
            generate: () => `${cleanBaseUrl}/mermaid/generate`,
            focused: (params = {}) => {
                const queryString = new URLSearchParams(params).toString();
                return `${cleanBaseUrl}/mermaid/focused${queryString ? '?' + queryString : ''}`;
            },
        }
    };
}

