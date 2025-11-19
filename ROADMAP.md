# Laravel Overlord - Feature Roadmap

This document outlines the planned implementation of new features for the Laravel Overlord package. These features will extend the development console to provide comprehensive exploration and management of Laravel application components.

## Overview

The following 12 features will be added to provide developers with complete visibility and control over their Laravel application's architecture:

1. **Traits** - Discover and explore application traits
2. **Services** - Browse and analyze service classes
3. **Providers** - View and manage service providers
4. **Routes** - Explore and test application routes
5. **Config** - Browse and edit configuration files
6. **Middleware** - Discover and manage middleware
7. **Events/Listeners** - Explore event system and listeners
8. **Notifications** - View and test notification classes
9. **Mail** - Browse mailables and test email sending
10. **Cache** - Monitor and manage cache operations
11. **Sessions** - View and manage session data
12. **Facades** - Explore registered facades and their bindings

## Implementation Strategy

### Phase 1: Discovery Services (Backend Foundation)

Each feature will follow the existing pattern established by `ClassDiscovery`, `ControllerDiscovery`, and `ModelDiscovery`:

**Pattern:**
- Create a `Discovery` service in `src/Services/`
- Implement methods to scan and analyze the feature
- Return structured data with metadata
- Handle errors gracefully

**Files to Create:**
- `src/Services/TraitDiscovery.php`
- `src/Services/ServiceDiscovery.php`
- `src/Services/ProviderDiscovery.php`
- `src/Services/RouteDiscovery.php`
- `src/Services/ConfigDiscovery.php`
- `src/Services/MiddlewareDiscovery.php`
- `src/Services/EventDiscovery.php`
- `src/Services/NotificationDiscovery.php`
- `src/Services/MailDiscovery.php`
- `src/Services/CacheService.php` (different pattern - management service)
- `src/Services/SessionService.php` (different pattern - management service)
- `src/Services/FacadeDiscovery.php`

### Phase 2: API Controllers

Add controller methods following the pattern in `TerminalController.php`:

**Pattern:**
- Add methods to `TerminalController.php` or create dedicated controllers
- Return JSON responses with consistent structure
- Include error handling and validation
- Support filtering, searching, and pagination where applicable

**Routes to Add (in `routes/api.php`):**
```php
// Traits routes
Route::prefix('traits')->group(function () {
    Route::get('/', [TerminalController::class, 'getTraits']);
    Route::get('/{trait}', [TerminalController::class, 'getTraitDetails']);
});

// Services routes
Route::prefix('services')->group(function () {
    Route::get('/', [TerminalController::class, 'getServices']);
    Route::get('/{service}', [TerminalController::class, 'getServiceDetails']);
});

// Providers routes
Route::prefix('providers')->group(function () {
    Route::get('/', [TerminalController::class, 'getProviders']);
    Route::get('/{provider}', [TerminalController::class, 'getProviderDetails']);
});

// Routes explorer
Route::prefix('routes')->group(function () {
    Route::get('/', [TerminalController::class, 'getRoutes']);
    Route::get('/{name}', [TerminalController::class, 'getRouteDetails']);
    Route::post('/test', [TerminalController::class, 'testRoute']);
});

// Config routes
Route::prefix('config')->group(function () {
    Route::get('/', [TerminalController::class, 'getConfigFiles']);
    Route::get('/{file}', [TerminalController::class, 'getConfig']);
    Route::put('/{file}', [TerminalController::class, 'updateConfig']);
});

// Middleware routes
Route::prefix('middleware')->group(function () {
    Route::get('/', [TerminalController::class, 'getMiddleware']);
    Route::get('/{middleware}', [TerminalController::class, 'getMiddlewareDetails']);
});

// Events/Listeners routes
Route::prefix('events')->group(function () {
    Route::get('/', [TerminalController::class, 'getEvents']);
    Route::get('/{event}', [TerminalController::class, 'getEventDetails']);
    Route::get('/listeners/{listener}', [TerminalController::class, 'getListenerDetails']);
    Route::post('/dispatch', [TerminalController::class, 'dispatchEvent']);
});

// Notifications routes
Route::prefix('notifications')->group(function () {
    Route::get('/', [TerminalController::class, 'getNotifications']);
    Route::get('/{notification}', [TerminalController::class, 'getNotificationDetails']);
    Route::post('/test', [TerminalController::class, 'testNotification']);
});

// Mail routes
Route::prefix('mail')->group(function () {
    Route::get('/', [TerminalController::class, 'getMailables']);
    Route::get('/{mailable}', [TerminalController::class, 'getMailableDetails']);
    Route::post('/send', [TerminalController::class, 'sendTestEmail']);
});

// Cache routes
Route::prefix('cache')->group(function () {
    Route::get('/stats', [TerminalController::class, 'getCacheStats']);
    Route::get('/keys', [TerminalController::class, 'getCacheKeys']);
    Route::get('/key/{key}', [TerminalController::class, 'getCacheValue']);
    Route::delete('/key/{key}', [TerminalController::class, 'deleteCacheKey']);
    Route::post('/clear', [TerminalController::class, 'clearCache']);
    Route::post('/tags/{tag}/clear', [TerminalController::class, 'clearCacheTag']);
});

// Session routes
Route::prefix('sessions')->group(function () {
    Route::get('/', [TerminalController::class, 'getSessions']);
    Route::get('/{id}', [TerminalController::class, 'getSessionData']);
    Route::delete('/{id}', [TerminalController::class, 'deleteSession']);
    Route::post('/clear', [TerminalController::class, 'clearSessions']);
});

// Facades routes
Route::prefix('facades')->group(function () {
    Route::get('/', [TerminalController::class, 'getFacades']);
    Route::get('/{facade}', [TerminalController::class, 'getFacadeDetails']);
});
```

### Phase 3: Frontend Components

Create Vue components following the pattern of existing Terminal components:

**Pattern:**
- Create component in `resources/js/Components/Terminal/`
- Use similar structure to `TerminalClasses.vue`, `TerminalControllers.vue`
- Include search, filtering, and detail views
- Support tab system integration

**Components to Create:**
- `TerminalTraits.vue`
- `TerminalServices.vue`
- `TerminalProviders.vue`
- `TerminalRoutes.vue`
- `TerminalConfig.vue`
- `TerminalMiddleware.vue`
- `TerminalEvents.vue`
- `TerminalNotifications.vue`
- `TerminalMail.vue`
- `TerminalCache.vue`
- `TerminalSessions.vue`
- `TerminalFacades.vue`

### Phase 4: API Integration

Update `useOverlordApi.js` to include new endpoints:

**Pattern:**
```javascript
traits: {
    list: () => `${cleanBaseUrl}/traits`,
    details: (trait) => `${cleanBaseUrl}/traits/${trait}`,
},
// ... similar for all features
```

### Phase 5: Tab Integration

Add new tabs to `DeveloperTerminal.vue`:

**Pattern:**
- Add to `tabConfigs` object
- Import and register components
- Add to tools menu/sidebar

## Feature Specifications

### 1. Traits Explorer

**Purpose:** Discover and explore all traits used in the application.

**Features:**
- List all traits in the application
- Show trait location and namespace
- Display methods provided by each trait
- Show which classes use each trait
- View trait source code
- Search and filter traits

**Implementation:**
- `TraitDiscovery` service scans for traits using reflection
- Identifies trait usage across classes
- Returns trait metadata and relationships

**UI Components:**
- List view with search
- Detail view showing methods and usages
- Code viewer for trait source

### 2. Services Explorer

**Purpose:** Browse and analyze service classes in the application.

**Features:**
- List all service classes
- Show service dependencies (constructor injection)
- Display service methods
- View service bindings in container
- Test service instantiation
- Search services by name or namespace

**Implementation:**
- `ServiceDiscovery` scans `app/Services` directory
- Uses reflection to analyze dependencies
- Checks service provider bindings

**UI Components:**
- Service list with filtering
- Detail view with dependencies graph
- Method explorer
- Container binding viewer

### 3. Providers Explorer

**Purpose:** View and manage service providers.

**Features:**
- List all registered service providers
- Show provider boot/register methods
- Display provider bindings
- View provider configuration
- Enable/disable providers (with caution)
- Search providers

**Implementation:**
- `ProviderDiscovery` reads from `config/app.php`
- Uses reflection to analyze provider classes
- Extracts binding information

**UI Components:**
- Provider list with status
- Detail view with bindings
- Configuration editor (read-only recommended)

### 4. Routes Explorer

**Purpose:** Explore and test application routes.

**Features:**
- List all registered routes
- Filter by method, name, URI pattern
- View route parameters and constraints
- Show middleware stack
- Display controller/closure details
- Test route execution (GET only, with warnings)
- Generate route URLs
- Search routes

**Implementation:**
- `RouteDiscovery` uses `Route::getRoutes()`
- Extracts route metadata
- Provides safe route testing

**UI Components:**
- Route table with filters
- Route detail modal
- Route tester (with safety warnings)
- URL generator

### 5. Config Explorer

**Purpose:** Browse and view configuration files.

**Features:**
- List all config files
- View config values (with sensitive data redaction)
- Search across config files
- Show config hierarchy
- Compare config values
- Export config (sanitized)
- Edit config (with validation and backup)

**Implementation:**
- `ConfigDiscovery` scans `config/` directory
- Uses Laravel's config system
- Implements sensitive data redaction
- Creates backups before edits

**UI Components:**
- Config file browser
- Config value viewer/editor
- Search interface
- Diff viewer for changes

### 6. Middleware Explorer

**Purpose:** Discover and manage middleware.

**Features:**
- List all middleware (global, route, group)
- Show middleware parameters
- Display middleware stack for routes
- View middleware source code
- Test middleware execution
- Search middleware

**Implementation:**
- `MiddlewareDiscovery` reads from `app/Http/Kernel.php`
- Uses reflection to analyze middleware
- Maps middleware to routes

**UI Components:**
- Middleware list with categories
- Detail view with usage
- Stack visualizer
- Code viewer

### 7. Events/Listeners Explorer

**Purpose:** Explore event system and listeners.

**Features:**
- List all events and listeners
- Show event-listener mappings
- Display event payload structure
- View listener source code
- Test event dispatching (with warnings)
- Search events and listeners
- Show event history (if logged)

**Implementation:**
- `EventDiscovery` reads from `app/Providers/EventServiceProvider.php`
- Uses reflection to analyze events/listeners
- Provides safe event dispatching

**UI Components:**
- Event/listener list
- Mapping diagram
- Event tester
- Payload viewer

### 8. Notifications Explorer

**Purpose:** View and test notification classes.

**Features:**
- List all notification classes
- Show notification channels
- Display notification structure
- View notification templates
- Test notification sending
- Preview notification content
- Search notifications

**Implementation:**
- `NotificationDiscovery` scans for notification classes
- Uses reflection to analyze channels and content
- Provides test sending capability

**UI Components:**
- Notification list
- Detail view with channels
- Preview modal
- Test sender

### 9. Mail Explorer

**Purpose:** Browse mailables and test email sending.

**Features:**
- List all mailable classes
- Show email templates (Markdown/Blade)
- Display email structure and data
- Preview emails in browser
- Send test emails
- View email queue status
- Search mailables

**Implementation:**
- `MailDiscovery` scans for mailable classes
- Extracts template information
- Provides email preview and testing

**UI Components:**
- Mailable list
- Template viewer
- Email preview
- Test sender

### 10. Cache Manager

**Purpose:** Monitor and manage cache operations.

**Features:**
- View cache statistics
- List cache keys (with filtering)
- View cache values
- Delete cache keys
- Clear cache by tags
- Clear entire cache
- Search cache keys
- Monitor cache hits/misses

**Implementation:**
- `CacheService` uses Laravel Cache facade
- Provides safe cache operations
- Implements key filtering and search

**UI Components:**
- Cache dashboard with stats
- Key browser with search
- Value viewer
- Management actions

### 11. Session Manager

**Purpose:** View and manage session data.

**Features:**
- List active sessions
- View session data
- Search sessions
- Delete sessions
- Clear all sessions
- View session configuration
- Monitor session activity

**Implementation:**
- `SessionService` reads from session driver
- Provides safe session management
- Respects session configuration

**UI Components:**
- Session list
- Session data viewer
- Management actions
- Activity monitor

### 12. Facades Explorer

**Purpose:** Explore registered facades and their bindings.

**Features:**
- List all facades
- Show facade bindings
- Display facade methods
- View underlying class
- Test facade methods
- Search facades

**Implementation:**
- `FacadeDiscovery` uses Laravel's facade system
- Maps facades to their underlying classes
- Uses reflection for method discovery

**UI Components:**
- Facade list
- Binding viewer
- Method explorer
- Test interface

## Implementation Order

### Priority 1 (High Value, Low Complexity)
1. **Routes Explorer** - Most commonly needed, straightforward implementation
2. **Config Explorer** - Frequently accessed, clear use case
3. **Traits Explorer** - Simple discovery, high developer value

### Priority 2 (High Value, Medium Complexity)
4. **Services Explorer** - Important for understanding architecture
5. **Middleware Explorer** - Critical for debugging
6. **Events/Listeners Explorer** - Important for event-driven apps

### Priority 3 (Medium Value, Medium Complexity)
7. **Providers Explorer** - Useful for advanced users
8. **Facades Explorer** - Educational and debugging value
9. **Notifications Explorer** - Useful for testing

### Priority 4 (Specialized Use Cases)
10. **Mail Explorer** - Specific use case
11. **Cache Manager** - Management tool
12. **Session Manager** - Management tool

## Technical Considerations

### Security
- All routes must respect authentication middleware
- Sensitive data (config values, session data) must be redacted
- Write operations (config edits, cache clears) require confirmation
- Test operations (route testing, event dispatching) must have safety warnings

### Performance
- Implement caching for discovery operations
- Use pagination for large lists
- Lazy-load detailed views
- Optimize reflection operations

### User Experience
- Consistent UI patterns across all features
- Search and filtering on all list views
- Keyboard shortcuts for common actions
- Clear visual feedback for operations
- **Cross-Referencing and Drill-Down Navigation** - All features must support clickable cross-references to related objects, allowing seamless navigation between different explorer views

### Code Organization
- Follow existing patterns strictly
- Reuse common components where possible
- Maintain consistent API response structure
- Document all new services and methods

## Dependencies

### Existing Components to Reuse
- `JsonViewer.vue` - For displaying structured data
- `TerminalOutput.vue` - For displaying code/output
- Search/filter components from existing views
- Modal/dialog patterns from existing components

### New Dependencies (if needed)
- None anticipated - all features can use existing Laravel and Vue capabilities

## Testing Strategy

### Unit Tests
- Test each Discovery service
- Test controller methods
- Test error handling

### Integration Tests
- Test API endpoints
- Test Vue component rendering
- Test user interactions

### Manual Testing
- Test each feature in a real Laravel application
- Verify security measures
- Test performance with large datasets

## Documentation

### Code Documentation
- PHPDoc for all new services
- JSDoc for Vue components
- Inline comments for complex logic

### User Documentation
- Update README.md with new features
- Add help text in terminal
- Create feature-specific guides

## Cross-Reference System (Universal Requirement)

### Overview
**All features must implement cross-referencing and drill-down navigation.** When viewing any object, related objects should be clickable links that open the appropriate explorer tab with that object selected and highlighted.

### Implementation Requirements

**1. Unified Cross-Reference Component**
- Create `TerminalCrossReference.vue` reusable component
- Displays clickable references with type-specific icons
- Supports multiple reference types (controller, middleware, model, service, trait, provider, etc.)
- Visual indicators (badges, links, hover states)
- Consistent styling across all features

**2. Navigation System**
- Extend `openTab()` in `DeveloperTerminal.vue` to accept options: `openTab(tabId, { itemId, highlight, filter })`
- Add `handleNavigateToReference(type, identifier)` method
- Support navigation history for back/forward navigation
- Components accept `initialItem` prop for deep linking
- Child components emit `navigate-to` events

**3. Backend Cross-Reference Data**
- All discovery services must return `cross_references` metadata
- Include related objects with type, identifier, and label
- Extract relationships during discovery (e.g., route → controller, controller → models)

**4. Cross-Reference Examples**

**Routes Explorer:**
- Controller names → Opens Controllers tab with controller selected
- Middleware names → Opens Middleware tab with middleware selected
- Model names (from bindings) → Opens Models diagram with model selected
- Service names (from controller) → Opens Services tab with service selected

**Controllers Explorer:**
- Routes using controller → Opens Routes tab filtered to those routes
- Models used → Opens Models diagram with models selected
- Services injected → Opens Services tab with services selected
- Traits used → Opens Traits tab with traits selected

**Middleware Explorer:**
- Routes using middleware → Opens Routes tab filtered to those routes
- Controllers using middleware → Opens Controllers tab filtered

**Services Explorer:**
- Controllers using service → Opens Controllers tab filtered
- Other services (dependencies) → Opens Services tab with dependency selected
- Models used → Opens Models diagram

**Traits Explorer:**
- Classes using trait → Opens Classes tab filtered to those classes
- Controllers using trait → Opens Controllers tab filtered

**Events/Listeners Explorer:**
- Listeners for event → Opens Listeners view
- Events for listener → Opens Events view
- Classes dispatching event → Opens Classes tab

**And so on for all features...**

### Cross-Reference Data Structure

```php
'cross_references' => [
    'controller' => [
        'class' => 'App\Http\Controllers\UserController',
        'method' => 'show',
        'full_name' => 'App\Http\Controllers\UserController@show'
    ],
    'middleware' => [
        ['name' => 'auth', 'type' => 'middleware'],
        ['name' => 'api', 'type' => 'middleware']
    ],
    'models' => [
        ['name' => 'User', 'full_name' => 'App\Models\User', 'detected_from' => 'parameter']
    ],
    'services' => [
        ['name' => 'UserService', 'full_name' => 'App\Services\UserService']
    ],
    'traits' => [],
    'routes' => [],
    // ... other reference types
]
```

### Frontend Cross-Reference Component

```javascript
// TerminalCrossReference.vue usage
<CrossReference 
    :references="route.cross_references.controller"
    type="controller"
    @navigate="handleNavigate"
/>

// Emits navigate event:
emit('navigate', {
    type: 'controller',
    identifier: 'App\\Http\\Controllers\\UserController',
    method: 'show' // optional
})
```

## Future Enhancements

After initial implementation, consider:
- Advanced filtering and search
- Export capabilities (JSON, CSV)
- Comparison tools (diff views)
- History/audit logging
- Bulk operations
- Custom views/filters
- Integration with AI assistant
- Breadcrumb navigation trail
- Navigation history (back/forward buttons)

## Notes

- All features should follow the existing code style and patterns
- Maintain backward compatibility
- Consider performance implications of discovery operations
- Implement proper error handling and user feedback
- Ensure all operations are safe and reversible where possible

