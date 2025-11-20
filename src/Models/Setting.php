<?php

namespace Spiderwisp\LaravelOverlord\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'overlord_settings';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = [
		'key',
		'value',
		'description',
	];

	/**
	 * Indicates if the model should be timestamped.
	 *
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * Get the decrypted value.
	 *
	 * @param  string  $value
	 * @return string
	 */
	public function getValueAttribute($value)
	{
		if (empty($value)) {
			return $value;
		}

		try {
			return Crypt::decryptString($value);
		} catch (\Exception $e) {
			// If decryption fails, return the raw value (might be unencrypted from old data)
			return $value;
		}
	}

	/**
	 * Set the encrypted value.
	 *
	 * @param  string  $value
	 * @return void
	 */
	public function setValueAttribute($value)
	{
		if (empty($value)) {
			$this->attributes['value'] = $value;
			return;
		}

		try {
			$this->attributes['value'] = Crypt::encryptString($value);
		} catch (\Exception $e) {
			// If encryption fails, store as plain text (shouldn't happen, but handle gracefully)
			\Log::warning('Failed to encrypt setting value', [
				'key' => $this->attributes['key'] ?? 'unknown',
				'error' => $e->getMessage(),
			]);
			$this->attributes['value'] = $value;
		}
	}

	/**
	 * Get a setting value by key.
	 *
	 * @param  string  $key
	 * @param  mixed  $default
	 * @return mixed
	 */
	public static function get($key, $default = null)
	{
		$setting = static::where('key', $key)->first();
		return $setting ? $setting->value : $default;
	}

	/**
	 * Set a setting value by key.
	 *
	 * @param  string  $key
	 * @param  mixed  $value
	 * @param  string|null  $description
	 * @return static
	 */
	public static function set($key, $value, $description = null)
	{
		return static::updateOrCreate(
			['key' => $key],
			[
				'value' => $value,
				'description' => $description,
			]
		);
	}

	/**
	 * Check if a setting exists.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public static function has($key)
	{
		return static::where('key', $key)->exists();
	}

	/**
	 * Delete a setting by key.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public static function remove($key)
	{
		return static::where('key', $key)->delete();
	}
}

