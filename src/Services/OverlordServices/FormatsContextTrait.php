<?php

namespace Spiderwisp\LaravelOverlord\Services\OverlordServices;

trait FormatsContextTrait
{
	/**
	 * Get the important reminders section for LLM context
	 *
	 * @return string
	 */
	protected function getImportantReminders(): string
	{
		return "\n\n## Important Reminders\n\n" .
			"- Only suggest models that are listed in the 'Available Models' section above\n" .
			"- For tables without models (listed in 'Tables without Models'), use `DB::table('table_name')` instead\n" .
			"- **CRITICAL**: Use EXACT column names and fillable field names from the database context - NEVER guess\n" .
			"  - If the context shows `Fillable: ['stock_id', 'old_price', 'new_price', 'source']`, use ONLY those exact names\n" .
			"  - If the context shows `Columns: ['stock_id', 'old_price', 'new_price', 'source']`, use ONLY those exact names\n" .
			"  - DO NOT guess column names like `price` when the context shows `old_price` and `new_price`\n" .
			"  - DO NOT suggest field names that are not listed in the fillable array or columns list\n" .
			"- Check foreign key constraints before suggesting INSERT operations\n" .
			"- Verify that foreign key values exist in referenced tables\n" .
			"- Use `Model::query()->method()` for instance methods, not `Model::method()`\n" .
			"- ALWAYS check for null before accessing properties (e.g., `if (\\\$user) { \\\$user->watchlists; }`)\n" .
			"- Use `return` statements instead of `echo` for output - the terminal displays return values\n" .
			"- **CRITICAL**: NEVER use HTML entities in code blocks - ALWAYS use proper PHP syntax\n" .
			"  - Use `->` NOT `-&gt;` in code blocks\n" .
			"  - Use `::` NOT `::` in code blocks\n" .
			"  - Use `<` NOT `&lt;` in code blocks\n" .
			"  - Use `>` NOT `&gt;` in code blocks\n" .
			"  - Code blocks should contain raw PHP code, not HTML-encoded text\n" .
			"- 🚨🚨🚨 CRITICAL: Code blocks (```php ... ```) are EXECUTED DIRECTLY as PHP code - they are NOT examples\n" .
			"  - ❌ NEVER put shell commands in code blocks: ```php composer show laravel/tinker ``` → WILL CAUSE PARSE ERROR\n" .
			"  - ❌ NEVER put file editing instructions in code blocks\n" .
			"  - ✅ Put shell commands in REGULAR TEXT: \"Run `composer show laravel/tinker` in your terminal\"\n" .
			"  - ✅ Put file edits in REGULAR TEXT: \"Add this to Kernel.php: ...\"\n" .
			"  - ✅ Code blocks MUST contain ONLY valid, executable PHP code that can run in the terminal\n" .
			"  - Example WRONG: ```php composer show laravel/tinker ``` (THIS WILL FAIL)\n" .
			"  - Example RIGHT: Run `composer show laravel/tinker` in your terminal (in regular text, not code block)\n" .
			"- **CRITICAL - DEBUGGING ERRORS - READ THIS FIRST**:\n" .
			"  - **ALWAYS READ THE FULL ERROR MESSAGE** - The error message contains critical information\n" .
			"  - **UNDERSTAND THE ERROR TYPE BEFORE RESPONDING**:\n" .
			"    * If error mentions \"Rate limit\", \"429\", \"API error\", \"authentication\", \"token\" → This is an API/service issue, NOT a code issue\n" .
			"    * If error mentions \"Parse error\", \"syntax error\", \"unexpected\" → This is a PHP syntax issue\n" .
			"    * If error mentions \"Class not found\", \"Method not found\" → This is a code structure issue\n" .
			"    * If error mentions \"SQL\", \"database\", \"query\" → This is a database issue\n" .
			"  - **DO NOT give generic code examples if the error is clearly about external services**\n" .
			"    * If error says \"Rate limit reached for model\" → Explain the rate limit, suggest waiting or upgrading, DO NOT give User::all() examples\n" .
			"    * If error says \"API error\" or \"429\" → Explain it's a rate limit/API issue, not a code problem\n" .
			"  - **Only provide code examples if the error is about PHP syntax, Laravel code, or database queries**\n" .
			"  - **For Parse Errors**: Examine the actual code at the specified file and line number from \"Surrounding Context\"\n" .
			"  - **For API Errors**: Explain the service issue and suggest solutions (wait, upgrade, check credentials)\n";
	}
}