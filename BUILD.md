# Building Package Assets

> **Note:** This document is for **package maintainers/developers** only. End-users do not need to build assets - they are pre-compiled and published automatically.

This package includes pre-compiled assets that are published to user applications. Assets **must be built before publishing the package**.

## Building Assets

### Option 1: Build from Package Directory

From the package root directory (`packages/laravel-overlord/`):

```bash
npm install
npm run production
```

This will build the terminal assets to:
- `public/js/terminal.js` (which gets published to `public/vendor/laravel-overlord/js/terminal.js`)

### Option 2: Build from Main App (Recommended for Development)

If you're developing in the main app that uses this package:

1. Sync package assets to the app:
   ```bash
   npm run sync-package
   ```

2. Build using the main app's webpack config (which includes terminal.js):
   ```bash
   npm run production
   ```

3. Copy the built asset to the package:
   ```bash
   cp public/js/vendor/laravel-overlord/terminal.js packages/laravel-overlord/public/js/terminal.js
   cp public/mix-manifest.json packages/laravel-overlord/public/mix-manifest.json  # if needed
   ```

## Asset Publishing

The built assets are published to user applications via:
```bash
php artisan vendor:publish --tag=laravel-assets
```

Assets are published to `public/vendor/laravel-overlord/` in the user's application.

## Development

For development, you can use:
```bash
npm run watch
```

This will watch for changes and rebuild automatically.

## Important Notes

- **Always build assets before committing** - The `public/` directory with compiled assets should be committed to the repository (like Horizon does)
- **Test asset publishing** - After building, test that assets publish correctly: `php artisan vendor:publish --tag=laravel-assets`
- **Version control** - The built assets are part of the package distribution
- **Docker/WSL** - If building in Docker/WSL, ensure paths are correct or use Option 2 above

