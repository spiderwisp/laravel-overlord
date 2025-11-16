<?php

namespace Spiderwisp\LaravelOverlord\Services;

class SensitiveDataRedactor
{
	/**
	 * Redact sensitive data from text (passwords, API keys, tokens, etc.)
	 *
	 * @param string $text
	 * @return string
	 */
	public static function redact(string $text): string
	{
		if (empty($text)) {
			return $text;
		}

		$patterns = [
			// Passwords
			'/password\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',
			'/pwd\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',
			'/pass\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',

			// API keys
			'/api[_-]?key\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',
			'/apikey\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',

			// Tokens
			'/token\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',
			'/access[_-]?token\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',
			'/refresh[_-]?token\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',

			// Database credentials
			'/db[_-]?(password|pass|pwd)\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',
			'/database[_-]?(password|pass|pwd)\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',

			// Secret keys
			'/secret[_-]?key\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',
			'/secret\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',

			// AWS credentials
			'/aws[_-]?(access[_-]?key|secret[_-]?key|secret[_-]?access[_-]?key)\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',

			// Authorization headers
			'/authorization\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',
			'/auth[_-]?token\s*[=:]\s*[\'"]?[^\'"\s]+[\'"]?/i',
		];

		$redacted = $text;
		foreach ($patterns as $pattern) {
			$redacted = preg_replace($pattern, '[REDACTED]', $redacted);
		}

		return $redacted;
	}
}