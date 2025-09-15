# Kntnt CLP Varnish Cache Purger
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Requires PHP: 8.3+](https://img.shields.io/badge/PHP-8.3+-blue.svg)](https://php.net)
[![Requires WordPress: 6.8+](https://img.shields.io/badge/WordPress-6.8+-blue.svg)](https://wordpress.org)

A WordPress plugin that automatically purges relevant pages from the [CLP Varnish Cache](https://wordpress.org/plugins/clp-varnish-cache/) when content is updated, using a precise, context-aware approach.

## Description

This plugin extends the CLP Varnish Cache plugin by providing intelligent, automatic cache purging. When content changes occur in WordPress, it precisely identifies and purges only the affected cached pages, ensuring visitors always see up-to-date content while maintaining optimal cache performance.

The plugin monitors various WordPress events (post updates, term changes, comment approvals, etc.) and builds a comprehensive list of URLs that need cache invalidation. It uses a singleton pattern to collect all URLs during a request and executes purge operations at shutdown for minimal performance impact.

### Key Features

* **Intelligent purging**: Only purges affected pages, not the entire cache (unless necessary)
* **Comprehensive coverage**: Handles posts, pages, custom post types, categories, tags, custom taxonomies, comments, user profiles, and events that affect the entire website.
* **Bulk operation support**: Correctly handles multiple post updates in a single request
* **Performance optimized**: Collects URLs during request and purges at shutdown
* **Extensible**: Provides multiple filters for customization
* **Debug logging**: Output debug information when WordPress debug logging is enabled

## Requirements

This plugin requires

* WordPress 6.8 or later
* PHP 8.3 or later

For the plugin to do anything, it’s also reqiuired that

* [CLP Varnish Cache](https://wordpress.org/plugins/clp-varnish-cache/) is installed and activated
* Varnish Cache is enabled through the CLP Varnish Cache-plugin

## Installation

### Install as a must use-plugin

The plugin is intended to be installed as a [must-use plugin](https://developer.wordpress.org/advanced-administration/plugins/mu-plugins/) as follows:

1. [Download the latest release ZIP file](https://github.com/Kntnt/kntnt-clp-varnish-purger/releases/latest/download/kntnt-clp-varnish-purger.zip) and unzip it.
2. Create the mu-plugins directory if it doesn't exist. By default, the mu-plugins directory is found in `wp-content/mu-plugins`. However, the directory can be changed by defining `WPMU_PLUGIN_DIR` and `WPMU_PLUGIN_URL` in [`wp-config.php`](https://wordpress.org/documentation/article/editing-wp-config-php/).
3. Upload the plugin file [kntnt-clp-varnish-purger.php](https://github.com/Kntnt/kntnt-clp-varnish-purger/blob/main/kntnt-clp-varnish-purger.php) to the mu-plugins directory.

### Install as a regular plugin

Alternatively, you can install the plugin as a regular plugin:

1. [Download the latest release ZIP file](https://github.com/Kntnt/kntnt-clp-varnish-purger/releases/latest/download/kntnt-clp-varnish-purger.zip).
2. In your WordPress admin panel, go to **Plugins → Add New**.
3. Click **Upload Plugin** and select the downloaded ZIP file.
4. Activate the plugin.

## Usage

This plugin is a companion to the [CLP Varnish Cache](https://wordpress.org/plugins/clp-varnish-cache/) plugin. It works automatically when the CLP Varnish Cache plugin is installed and activated and the Varnish cache is enabled. The plugin will not cause any harm if not all of these requirements are met, for example if Varnish is disabled, or if the CLP Varnish Cache plugin is not activated or installed.

### Selective purging

When a **post is published or updated**:
- The post's own URL
- Homepage
- Blog page (if different from homepage)  
- Author archive page
- Date archives (year, month, day)
- All category, tag, and taxonomy term pages the post belongs to

When a **post is deleted or unpublished**:
- All the same URLs as above

When a **term is updated or deleted**:
- The term's archive page
- All posts associated with that term (and their related URLs)

When a **comment is approved/unapproved/deleted**:
- The post where the comment appears (and all its related URLs)

When **user profile fields are changed**:
- The author's archive page
- All posts by that author (and their related URLs)

The following user profile fields trigger purging when modified:
- `description` - Biographical info shown in author boxes
- `display_name` - Public display name for the author
- `first_name` - Used in display name generation
- `last_name` - Used in display name generation
- `nickname` - Alternative display name option
- `user_email` - Affects Gravatar image display
- `user_nicename` - URL slug for author archive pages
- `user_url` - Website URL shown in author info

### Full cache purging

The following events trigger a **complete cache flush** because they affect the entire site:

- **Theme Customizer saved** (`customize_save_after`)
- **Global styles updated** (`save_post_wp_global_styles`) - Colors, fonts, etc.
- **Template saved** (`save_post_wp_template`) - Page templates
- **Template part saved** (`save_post_wp_template_part`) - Headers, footers, etc.
- **Theme switched** (`switch_theme`)
- **Site tagline changed** (`update_option_blogdescription`)
- **Site title changed** (`update_option_blogname`)
- **Date format changed** (`update_option_date_format`)
- **Permalink structure modified** (`update_option_permalink_structure`)
- **Posts per page setting changed** (`update_option_posts_per_page`)
- **Widget configuration changed** (`update_option_sidebars_widgets`)
- **Time format changed** (`update_option_time_format`)
- **Timezone changed** (`update_option_timezone_string`)
- **Core, theme, or plugin updated** (`upgrader_process_complete`)
- **Navigation menu saved** (`wp_update_nav_menu`)

## Developer documentation

### Available filters

The plugin provides the following filters to customize its behavior.

#### `kntnt-clp-varnish-cache-excluded-post-types`

Controls which post types are excluded from triggering cache purges.

**Default:** Excludes `attachment` and all non-public post types  
**Parameter:** `array $excluded_post_types` - Array of post type names to exclude  
**Return:** Modified array of excluded post types

```php
add_filter( 'kntnt-clp-varnish-cache-excluded-post-types', function( $excluded_post_types ) {
    // Also exclude testimonials from purging
    $excluded_post_types[] = 'testimonial';
    return $excluded_post_types;
} );
```

#### `kntnt-clp-varnish-cache-public-statuses`

Defines which post statuses are considered public and should trigger cache purging.

**Default:** `['publish']`  
**Parameter:** `array $public_statuses` - Array of status names  
**Return:** Modified array of public statuses

```php
add_filter( 'kntnt-clp-varnish-cache-public-statuses', function( $public_statuses ) {
    // Also purge cache for private posts (for logged-in user caching)
    $public_statuses[] = 'private';
    return $public_statuses;
} );
```

#### `kntnt-clp-varnish-cache-user-profile-fields`

Specifies which user profile fields trigger cache purging when changed.

**Default:** `['description', 'display_name', 'first_name', 'last_name', 'nickname', 'user_email', 'user_nicename', 'user_url']`  
**Parameter:** `array $user_profile_fields` - Array of field names  
**Return:** Modified array of fields

```php
add_filter( 'kntnt-clp-varnish-cache-user-profile-fields', function( $fields ) {
    // Also purge when custom author bio field changes
    $fields[] = 'author_bio_extended';
    return $fields;
} );
```

#### `kntnt-clp-varnish-cache-full-purge-hooks`

Controls which WordPress hooks trigger a complete cache flush.

**Default:** Includes hooks for theme changes, customizer saves, menu updates, widget changes, permalink changes, and more  
**Parameter:** `array $full_purge_hooks` - Array of hook names  
**Return:** Modified array of hooks

```php
add_filter( 'kntnt-clp-varnish-cache-full-purge-hooks', function( $hooks ) {
    // Also trigger full purge when site logo changes
    $hooks[] = 'update_option_site_logo';
    // Remove widget updates from triggering full purge
    $hooks = array_diff( $hooks, ['update_option_sidebars_widgets'] );
    return $hooks;
} );
```

#### `kntnt-clp-varnish-cache-post-purge-list`

Allows adding or removing URLs when a specific post changes. This filter runs for each post that needs purging.

**Parameters:** 
- `array $urls` - Associative array of URLs to purge (format: `['url' => true]`)
- `\WP_Post $post` - The post object being processed

**Return:** Modified array of URLs

```php
add_filter( 'kntnt-clp-varnish-cache-post-purge-list', function( $urls, $post ) {
    // Add custom archive page for a specific post type
    if ( 'product' === $post->post_type ) {
        $urls[ home_url( '/products/featured/' ) ] = true;
    }
    
    // Remove date archives for pages
    if ( 'page' === $post->post_type ) {
        $post_date = strtotime( $post->post_date );
        unset( $urls[ get_year_link( date( 'Y', $post_date ) ) ] );
        unset( $urls[ get_month_link( date( 'Y', $post_date ), date( 'm', $post_date ) ) ] );
        unset( $urls[ get_day_link( date( 'Y', $post_date ), date( 'm', $post_date ), date( 'd', $post_date ) ) ] );
    }
    
    return $urls;
}, 10, 2 );
```

#### `kntnt-clp-varnish-cache-purge-urls`

Final filter before URLs are purged. Allows last-minute modifications to the complete list of URLs.

**Parameter:** `array $urls` - Associative array of all URLs to purge (format: `['url' => true]`)  
**Return:** Modified array of URLs

```php
add_filter( 'kntnt-clp-varnish-cache-purge-urls', function( $urls ) {
    // Always include a critical page in every purge
    $urls[ home_url( '/critical-page/' ) ] = true;
    
    // Remove URLs from staging subdomain if accidentally included
    foreach ( $urls as $url => $purge ) {
        if ( str_contains( $url, 'staging.example.com' ) ) {
            unset( $urls[ $url ] );
        }
    }
    
    return $urls;
} );
```

### Debugging

Enable debug logging by setting these constants in `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

The plugin will log detailed information about its operations to `/wp-content/debug.log`, including:
- Which posts, terms, and comments trigger purging
- All URLs added to the purge queue
- Actual purge operations executed
- Any errors encountered

## Performance considerations

This plugin has been designed to be high-performance. For instance, it processes all purge actions upon shutdown in order to minimise the impact on response times.

However, for sites with terms or authors that have thousands of associated posts, performance issues may arise during purge operations, as the plugin will process all of them. For extremely large sites, consider using the filters to limit which content types trigger purging.

## Frequently Asked Questions

How does the plugin work internally?

It uses WordPress hooks to monitor changes to content. When a change is detected, it:

1. Determines whether a purge is necessary.
2. Determines whether to perform a selective or full purge, if necessary.
3. Collects all affected URLs in a deduplicated list during selective purge.
4. At shutdown, it sends purge requests to Varnish via the CLP Varnish Cache API.

### How can I verify the plugin is working?

To verify the plugin is working correctly:
1. Enable debug logging (see above)
2. Update a post and check the debug log for purge operations
3. Use browser developer tools or `curl -I` to check the `X-Cache` headers on your pages
4. Monitor your Varnish logs to confirm purge requests are received

### How can I get help or report a bug?

Please visit the plugin's [issue tracker on GitHub](https://github.com/kntnt/kntnt-clp-varnish-purger/issues) to ask questions, report bugs, or view existing discussions.

### How can I contribute?

Contributions are welcome! Please feel free to fork the repository and submit a pull request on GitHub.

## Changelog

### 1.0.0
* The initial release.
* A fully functional plugin.
