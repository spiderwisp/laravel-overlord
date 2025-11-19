const mix = require('laravel-mix');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel package. This builds the terminal assets that will be
 | published to users' applications.
 |
 */

mix.js('resources/js/terminal.js', 'public/js')
	.vue()
	.setPublicPath('public')
	.after(() => {
		// Auto-publish assets after build (development only)
		if (process.env.NODE_ENV !== 'production') {
			const { execSync } = require('child_process');
			const path = require('path');
			const mainAppPath = path.resolve(__dirname, '../../..');
			
			try {
				// Check if we're in a Laravel app structure (has artisan file)
				if (require('fs').existsSync(path.join(mainAppPath, 'artisan'))) {
					console.log('\n📦 Auto-publishing assets to main app...');
					execSync('php artisan vendor:publish --tag=laravel-assets --force', {
						cwd: mainAppPath,
						stdio: 'inherit',
					});
					console.log('✅ Assets published!\n');
				}
			} catch (error) {
				// Silently fail if we can't publish (e.g., not in main app directory)
				console.log('\n⚠️  Could not auto-publish assets. Run manually: php artisan vendor:publish --tag=laravel-assets --force\n');
			}
		}
	});

