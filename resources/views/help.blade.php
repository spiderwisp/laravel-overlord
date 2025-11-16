@php
	// PHP Syntax Highlighter Function
	function highlightPHP($code) {
		if (empty($code)) return '';
		
		// Escape HTML first - only escape <, >, and &, NOT quotes
		$highlighted = htmlspecialchars($code, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
		
		// Keywords (must be whole words) - do this first before other replacements
		$keywords = ['function', 'class', 'if', 'else', 'elseif', 'for', 'foreach', 'while', 'do', 'switch', 'case', 'break', 'continue', 'return', 'true', 'false', 'null', 'new', 'use', 'namespace', 'extends', 'implements', 'public', 'private', 'protected', 'static', 'abstract', 'final', 'const', 'var', 'echo', 'print', 'array', 'as', 'and', 'or', 'xor'];
		$keywordPattern = '/\b(' . implode('|', $keywords) . ')\b/i';
		$highlighted = preg_replace($keywordPattern, '<span class="php-keyword">$1</span>', $highlighted);
		
		// Strings (single and double quoted) - quotes are NOT encoded, so match them directly
		// Match single-quoted strings: '...' (handling escaped quotes)
		$highlighted = preg_replace("/'([^'\\\\]|\\\\.)*'/", '<span class="php-string">$0</span>', $highlighted);
		// Match double-quoted strings: "..." (handling escaped quotes)  
		$highlighted = preg_replace('/"([^"\\\\]|\\\\.)*"/', '<span class="php-string">$0</span>', $highlighted);
		
		// Numbers
		$highlighted = preg_replace('/\b(\d+\.?\d*)\b/', '<span class="php-number">$1</span>', $highlighted);
		
		// Variables (before operators to avoid conflicts)
		$highlighted = preg_replace('/\$(\w+)/', '<span class="php-variable">$$1</span>', $highlighted);
		
		// Method calls (->method) - match the actual -> after htmlspecialchars it becomes -&gt;
		$highlighted = preg_replace('/-&gt;(\w+)/', '<span class="php-operator">-&gt;</span><span class="php-method">$1</span>', $highlighted);
		
		// Static method calls (::method)
		$highlighted = preg_replace('/::(\w+)/', '<span class="php-operator">::</span><span class="php-method">$1</span>', $highlighted);
		
		// Class names (capitalized words before ::)
		$highlighted = preg_replace('/\b([A-Z][a-zA-Z0-9_]*)(?=\s*::)/', '<span class="php-class">$1</span>', $highlighted);
		
		// Operators (these are already encoded by htmlspecialchars)
		$highlighted = preg_replace('/(=&gt;|===|!==|==|!=|&lt;=|&gt;=|&lt;&lt;|&gt;&gt;)/', '<span class="php-operator">$1</span>', $highlighted);
		
		// Comments (// and /* */)
		$highlighted = preg_replace('/\/\/.*$/m', '<span class="php-comment">$0</span>', $highlighted);
		$highlighted = preg_replace('/\/\*[\s\S]*?\*\//', '<span class="php-comment">$0</span>', $highlighted);
		
		return $highlighted;
	}
	
	// Format models for display
	$chunkedModels = array_chunk($models, 4);
	$modelsHtml = '';
	foreach ($chunkedModels as $row) {
		$formattedRow = array_map(function($name) {
			return '<span class="help-model">' . htmlspecialchars($name) . '</span>';
		}, $row);
		$modelsHtml .= '<div class="help-models-row">' . implode('', $formattedRow) . '</div>';
	}
@endphp
<div class="help-content">
	<div class="help-header">
		<div class="help-title">🚀 Laravel Overlord - Complete Guide</div>
		<div class="help-subtitle">AI-Powered Development Console</div>
	</div>

	<div class="help-section">
		<div class="help-section-header">
			<span class="help-section-icon">📚</span>
			<span>Available Models</span>
		</div>
		<div class="help-section-desc">Use these models without namespace prefixes:</div>
		<div class="help-models-container">
			{!! $modelsHtml !!}
		</div>
	</div>

	<div class="help-section">
		<div class="help-section-header">
			<span class="help-section-icon">📖</span>
			<span>Example Commands</span>
		</div>
		
		<div class="help-subsection">
			<div class="help-subsection-title">Basic Queries</div>
			<div class="help-code-block">
				<div class="help-code">{!! highlightPHP("User::count()") !!}</div>
				<div class="help-code">{!! highlightPHP("User::where('role', 'CREATOR')->get()") !!}</div>
				<div class="help-code">{!! highlightPHP("Video::where('status', 'PUBLISHED')->count()") !!}</div>
				<div class="help-code">{!! highlightPHP("Creator::with('user')->first()") !!}</div>
			</div>
		</div>

		<div class="help-subsection">
			<div class="help-subsection-title">Working with Relationships</div>
			<div class="help-code-block">
				<div class="help-code">{!! highlightPHP('$user = User::find(1)') !!}</div>
				<div class="help-code">{!! highlightPHP('$user->creator') !!}</div>
				<div class="help-code">{!! highlightPHP('$creator = Creator::first()') !!}</div>
				<div class="help-code">{!! highlightPHP('$creator->videos()->take(5)->get()') !!}</div>
				<div class="help-code">{!! highlightPHP('$video = Video::first()') !!}</div>
				<div class="help-code">{!! highlightPHP('$video->creator') !!}</div>
			</div>
		</div>

		<div class="help-subsection">
			<div class="help-subsection-title">Chunking Large Datasets</div>
			<div class="help-code-block">
				<div class="help-code">{!! highlightPHP('Video::where(\'status\', \'PUBLISHED\')->chunk(100, function($videos) {' . "\n" . '  foreach($videos as $v) {' . "\n" . '    echo $v->id . "\n";' . "\n" . '  }' . "\n" . '})') !!}</div>
			</div>
		</div>

		<div class="help-subsection">
			<div class="help-subsection-title">Getting IDs</div>
			<div class="help-code-block">
				<div class="help-code">{!! highlightPHP("Video::where('status', 'PUBLISHED')->pluck('id')") !!}</div>
				<div class="help-code">{!! highlightPHP("User::pluck('email')->toArray()") !!}</div>
			</div>
		</div>

		<div class="help-subsection">
			<div class="help-subsection-title">Creating/Updating</div>
			<div class="help-code-block">
				<div class="help-code">{!! highlightPHP("User::create(['email' => 'test@example.com', 'role' => 'CREATOR'])") !!}</div>
				<div class="help-code">{!! highlightPHP('$user = User::find(1)') !!}</div>
				<div class="help-code">{!! highlightPHP('\$user->update([\'is_active\' => false])') !!}</div>
			</div>
		</div>

		<div class="help-subsection">
			<div class="help-subsection-title">Aggregations</div>
			<div class="help-code-block">
				<div class="help-code">{!! highlightPHP("Video::where('status', 'PUBLISHED')->sum('duration')") !!}</div>
				<div class="help-code">{!! highlightPHP("User::where('role', 'CREATOR')->avg('id')") !!}</div>
				<div class="help-code">{!! highlightPHP("Video::groupBy('status')->selectRaw('status, count(*) as count')->get()") !!}</div>
			</div>
		</div>
	</div>

	<div class="help-section">
		<div class="help-section-header">
			<span class="help-section-icon">🔧</span>
			<span>Laravel Facades</span>
		</div>
		<div class="help-section-desc">Available: <span class="help-facade">DB</span>, <span class="help-facade">Cache</span>, <span class="help-facade">Log</span>, <span class="help-facade">Queue</span>, <span class="help-facade">Mail</span>, <span class="help-facade">Storage</span>, <span class="help-facade">File</span>, <span class="help-facade">Hash</span>, <span class="help-facade">Str</span>, <span class="help-facade">Arr</span>, <span class="help-facade">Collection</span></div>
		<div class="help-subsection">
			<div class="help-subsection-title">Examples</div>
			<div class="help-code-block">
				<div class="help-code">{!! highlightPHP("DB::table('users')->count()") !!}</div>
				<div class="help-code">{!! highlightPHP("Cache::get('key')") !!}</div>
				<div class="help-code">{!! highlightPHP("Log::info('message')") !!}</div>
				<div class="help-code">{!! highlightPHP("Str::slug('Hello World')") !!}</div>
				<div class="help-code">{!! highlightPHP('collect([1, 2, 3])->map(fn($n) => $n * 2)') !!}</div>
			</div>
		</div>
	</div>

	<div class="help-section">
		<div class="help-section-header">
			<span class="help-section-icon">💡</span>
			<span>Tips & Tricks</span>
		</div>
		<div class="help-tips-list">
			<div class="help-tip-item">
				<span class="help-tip-icon">✓</span>
				<span class="help-tip-text">Variables persist between commands</span>
				<div class="help-code-inline">{!! highlightPHP('$user = User::first()') !!}</div>
			</div>
			<div class="help-tip-item">
				<span class="help-tip-icon">✓</span>
				<span class="help-tip-text">Use echo/print for output</span>
				<div class="help-code-inline">{!! highlightPHP('echo "Hello World"') !!}</div>
			</div>
			<div class="help-tip-item">
				<span class="help-tip-icon">✓</span>
				<span class="help-tip-text">Use var_dump() for debugging</span>
				<div class="help-code-inline">{!! highlightPHP('var_dump($user->toArray())') !!}</div>
			</div>
			<div class="help-tip-item">
				<span class="help-tip-icon">✓</span>
				<span class="help-tip-text">Access JSON columns directly</span>
				<div class="help-code-inline">{!! highlightPHP('$creator->socials') !!}</div>
			</div>
			<div class="help-tip-item">
				<span class="help-tip-icon">✓</span>
				<span class="help-tip-text">Use eager loading to avoid N+1 queries</span>
				<div class="help-code-inline">{!! highlightPHP("Video::with('creator', 'images')->get()") !!}</div>
			</div>
			<div class="help-tip-item">
				<span class="help-tip-icon">✓</span>
				<span class="help-tip-text">Use transactions for data integrity</span>
				<div class="help-code-inline">{!! highlightPHP('DB::transaction(function() { /* code */ })') !!}</div>
			</div>
		</div>
	</div>

	<div class="help-section">
		<div class="help-section-header">
			<span class="help-section-icon">🐘</span>
			<span>PHP Commands</span>
		</div>
		<div class="help-section-desc">You can run <span class="help-highlight">ANY PHP code</span>! This is a full PHP REPL environment.</div>
		<div class="help-subsection">
			<div class="help-subsection-title">Control structures, arrays, strings, math, files, dates</div>
			<div class="help-code-block">
				<div class="help-code">{!! highlightPHP('for($i = 0; $i < 10; $i++) { echo $i . "\n"; }') !!}</div>
				<div class="help-code">{!! highlightPHP('$arr = [1, 2, 3, 4, 5]') !!}</div>
				<div class="help-code">{!! highlightPHP('collect($arr)->map(fn($n) => $n * 2)') !!}</div>
				<div class="help-code">{!! highlightPHP("date('Y-m-d H:i:s')") !!}</div>
				<div class="help-code">{!! highlightPHP("\\Carbon\\Carbon::now()->format('Y-m-d')") !!}</div>
			</div>
		</div>
	</div>

	<div class="help-section">
		<div class="help-section-header">
			<span class="help-section-icon">🤖</span>
			<span>AI Assistant</span>
		</div>
		<div class="help-section-desc">Powered by laravel-overlord.com API service with full codebase and database context awareness.</div>
		<div class="help-features-list">
			<div class="help-feature-item">
				<span class="help-feature-name">Intelligent Code Suggestions</span>
				<span class="help-feature-detail">Get AI-powered code suggestions based on your codebase, database schema, and recent errors</span>
			</div>
			<div class="help-feature-item">
				<span class="help-feature-name">Context-Aware Assistance</span>
				<span class="help-feature-detail">AI automatically gathers context from your codebase, database tables, and application logs</span>
			</div>
			<div class="help-feature-item">
				<span class="help-feature-name">Intelligent AI Models</span>
				<span class="help-feature-detail">Uses advanced AI models through laravel-overlord.com service, automatically selected based on your plan</span>
			</div>
			<div class="help-feature-item">
				<span class="help-feature-name">Conversation History</span>
				<span class="help-feature-detail">Maintain context across multiple messages for complex debugging sessions</span>
			</div>
			<div class="help-feature-item">
				<span class="help-feature-name">Error Analysis</span>
				<span class="help-feature-detail">Paste error messages and get detailed explanations with suggested fixes</span>
			</div>
			<div class="help-feature-item">
				<span class="help-feature-name">Issue Creation</span>
				<span class="help-feature-detail">Generate structured issues from errors with automatic context gathering</span>
			</div>
		</div>
	</div>

	<div class="help-section">
		<div class="help-section-header">
			<span class="help-section-icon">🎨</span>
			<span>Terminal Features</span>
		</div>
		<div class="help-features-grid">
			<div class="help-feature-card">
				<div class="help-feature-title">Command Input</div>
				<div class="help-feature-desc">Type commands, use arrow keys for history, variables persist between commands</div>
			</div>
			<div class="help-feature-card">
				<div class="help-feature-title">History Panel</div>
				<div class="help-feature-desc">View past commands, filter by status, click to view output, search history</div>
			</div>
			<div class="help-feature-card">
				<div class="help-feature-title">Templates & Snippets</div>
				<div class="help-feature-desc">Pre-built templates, custom snippets, visual command builder</div>
			</div>
			<div class="help-feature-card">
				<div class="help-feature-title">Favorites</div>
				<div class="help-feature-desc">Save commands, organize with categories and tags, quick access</div>
			</div>
			<div class="help-feature-card">
				<div class="help-feature-title">Tools Menu</div>
				<div class="help-feature-desc">Models diagram, Controllers, Classes, Artisan Commands, Database Explorer</div>
			</div>
			<div class="help-feature-card">
				<div class="help-feature-title">Output Features</div>
				<div class="help-feature-desc">JSON formatting, syntax highlighting, copy to clipboard, export, error detection</div>
			</div>
			<div class="help-feature-card">
				<div class="help-feature-title">Database Scanning</div>
				<div class="help-feature-desc">Scan database tables, analyze schemas, discover relationships, sample data</div>
			</div>
			<div class="help-feature-card">
				<div class="help-feature-title">Codebase Scanning</div>
				<div class="help-feature-desc">Scan your codebase, discover classes, analyze structure, find dependencies</div>
			</div>
			<div class="help-feature-card">
				<div class="help-feature-title">Issues Tracker</div>
				<div class="help-feature-desc">Track errors, create issues from terminal output, manage bug reports</div>
			</div>
		</div>
	</div>

	<div class="help-section">
		<div class="help-section-header">
			<span class="help-section-icon">🛠️</span>
			<span>Advanced Features</span>
		</div>
		<div class="help-features-list">
			<div class="help-feature-item">
				<span class="help-feature-name">Models Diagram</span>
				<span class="help-feature-detail">Visualize Eloquent relationships with zoom, pan, and interactive nodes. See all model connections at a glance.</span>
			</div>
			<div class="help-feature-item">
				<span class="help-feature-name">Controllers Explorer</span>
				<span class="help-feature-detail">Browse all controllers, view methods, signatures, docblocks, and route bindings</span>
			</div>
			<div class="help-feature-item">
				<span class="help-feature-name">Classes Explorer</span>
				<span class="help-feature-detail">Explore PHP classes, inheritance hierarchies, traits, properties, and methods</span>
			</div>
			<div class="help-feature-item">
				<span class="help-feature-name">Migrations</span>
				<span class="help-feature-detail">View all Laravel migrations with status tracking, run or rollback migrations, and visualize migration dependencies with an interactive ancestry graph</span>
			</div>
			<div class="help-feature-item">
				<span class="help-feature-name">Artisan Commands</span>
				<span class="help-feature-detail">Execute Laravel Artisan commands with dynamic forms, parameter validation, and real-time output streaming</span>
			</div>
			<div class="help-feature-item">
				<span class="help-feature-name">Database Explorer</span>
				<span class="help-feature-detail">Browse database tables, view schemas, relationships, indexes, and sample data</span>
			</div>
			<div class="help-feature-item">
				<span class="help-feature-name">Application Logs</span>
				<span class="help-feature-detail">View and search application logs, filter by level, analyze errors in real-time</span>
			</div>
			<div class="help-feature-item">
				<span class="help-feature-name">Horizon Integration</span>
				<span class="help-feature-detail">Monitor Laravel Horizon queues, jobs, and workers directly from the terminal</span>
			</div>
		</div>
	</div>

	<div class="help-section">
		<div class="help-section-header">
			<span class="help-section-icon">⚠️</span>
			<span>Important Notes</span>
		</div>
		<div class="help-notes-list">
			<div class="help-note-item">
				<div class="help-note-category">Security & Logging</div>
				<div class="help-note-text">All commands are logged with your user ID. Sensitive data is automatically redacted.</div>
			</div>
			<div class="help-note-item">
				<div class="help-note-category">Performance</div>
				<div class="help-note-text">Long-running commands have a 5-minute timeout. Use chunk() for large datasets.</div>
			</div>
			<div class="help-note-item">
				<div class="help-note-category">Usage</div>
				<div class="help-note-text">Type "help" or "?" to show this guide. All Laravel models and facades work as expected. Use the AI assistant button (🤖) for intelligent code suggestions.</div>
			</div>
			<div class="help-note-item">
				<div class="help-note-category">AI Assistant</div>
				<div class="help-note-text">The AI assistant requires an API key configured in your .env file. Get your API key from laravel-overlord.com. Context is automatically gathered from your codebase and database.</div>
			</div>
		</div>
	</div>

	<div class="help-footer">
		<div class="help-footer-text">Happy coding! 🎉</div>
	</div>
</div>

