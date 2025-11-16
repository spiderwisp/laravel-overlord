<?php

namespace Spiderwisp\LaravelOverlord\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Spiderwisp\LaravelOverlord\LaravelOverlordServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
	/**
	 * Setup the test environment.
	 */
	protected function setUp(): void
	{
		parent::setUp();
	}

	/**
	 * Get package providers.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return array
	 */
	protected function getPackageProviders($app)
	{
		return [
			LaravelOverlordServiceProvider::class,
		];
	}

	/**
	 * Define environment setup.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return void
	 */
	protected function defineEnvironment($app)
	{
		// Setup default database to use sqlite :memory:
		$app['config']->set('database.default', 'testing');
		$app['config']->set('database.connections.testing', [
			'driver' => 'sqlite',
			'database' => ':memory:',
			'prefix' => '',
		]);
	}
}