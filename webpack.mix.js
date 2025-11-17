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
	.setPublicPath('public');

