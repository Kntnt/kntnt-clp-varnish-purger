<?php

/**
 * @wordpress-plugin
 * Plugin Name:       Kntnt CLP Varnish Cache Purger
 * Plugin URI:        https://github.com/Kntnt/kntnt-clp-varnish-purger
 * Description:       Automatically purges relevant pages from the CLP Varnish Cache when content is updated, using a precise, context-aware approach.
 * Version:           1.0.0
 * Author:            Thomas Barregren
 * Author URI:        https://www.kntnt.com/
 * License:           GPL-2.0-or-later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires PHP:      8.3
 */

declare( strict_types = 1 );

namespace Kntnt\Clp_Varnish_Purger;

defined( 'WPINC' ) && Plugin::init();

/**
 * Main plugin class implementing automatic Varnish cache purging.
 *
 * This plugin monitors WordPress content changes and intelligently purges
 * affected cache entries in CLP Varnish Cache. It uses a singleton pattern
 * to ensure only one instance runs per request.
 *
 * The plugin collects all URLs that need purging during a request and
 * executes the purge operations at shutdown to minimize performance impact.
 */
final class Plugin {

	/**
	 * Singleton instance of the plugin.
	 * Ensures only one instance exists throughout the WordPress request lifecycle.
	 */
	private static ?self $instance = null;

	/**
	 * Instance of the CLP Varnish Cache manager.
	 * This object handles the actual communication with the Varnish cache server.
	 */
	private ?\ClpVarnishCacheManager $varnish_manager = null;

	/**
	 * Flag indicating whether a full cache purge has been triggered.
	 *
	 * This prevents multiple full purges during a single request, which could
	 * happen if multiple events trigger full purge hooks. Once set to true,
	 * subsequent purge requests are ignored to avoid redundant operations.
	 */
	private bool $is_full_purge = false;

	/**
	 * Post types excluded from cache purging.
	 *
	 * These post types typically don't affect the public-facing site and
	 * therefore don't require cache invalidation when modified.
	 */
	private array $excluded_post_types = [ 'attachment' ];

	/**
	 * Post statuses considered public and requiring cache purging.
	 *
	 * Only posts with these statuses will trigger cache invalidation
	 * because they're the only ones visible to site visitors.
	 */
	private array $public_statuses = [ 'publish' ];

	/**
	 * WordPress hooks that trigger a complete cache flush.
	 *
	 * These events typically affect the entire site's appearance or structure,
	 * making selective cache purging impractical. Examples include theme changes,
	 * permalink structure updates, and global style modifications.
	 */
	private array $full_purge_hooks = [
		'customize_save_after',              // Theme Customizer settings saved
		'save_post_wp_global_styles',        // Global styles (colors, fonts) updated
		'save_post_wp_template',             // Template saved (e.g., page templates)
		'save_post_wp_template_part',        // Template part saved (header, footer, etc.)
		'switch_theme',                      // Theme activated or changed
		'update_option_blogdescription',     // Site tagline changed
		'update_option_blogname',            // Site title changed
		'update_option_date_format',         // Date format changed
		'update_option_permalink_structure', // Permalink structure modified
		'update_option_posts_per_page',      // Posts per page setting changed
		'update_option_sidebars_widgets',    // Widget configuration changed
		'update_option_time_format',         // Time format changed
		'update_option_timezone_string',     // Timezone setting changed
		'upgrader_process_complete',         // Core, theme, or plugin updated
		'wp_update_nav_menu',                // Navigation menu saved
	];

	/**
	 * User profile fields that trigger cache purging when modified.
	 *
	 * These fields appear publicly on author archives or in author boxes,
	 * so changes to them require cache invalidation for affected pages.
	 */
	private array $user_profile_fields = [
		'description',   // Biographical info shown in author boxes
		'display_name',  // Public display name for the author
		'first_name',    // Used in display name generation
		'last_name',     // Used in display name generation
		'nickname',      // Alternative display name option
		'user_email',    // Affects Gravatar image display
		'user_nicename', // URL slug for author archive pages
		'user_url',      // Website URL shown in author info
	];

	/**
	 * Maps post ID to status to handle multiple posts in same request.
	 *
	 * @var array<int, string>
	 */
	private array $post_status_before_change = [];

	/* private array $terms_before_update; */

	/**
	 * Maps a URL to a truth value that indicates whether or not the URL
	 * should be purged.
	 *
	 * @var array<string, true>
	 */
	private array $urls = [];

	/**
	 * Debug logging availability flag.
	 * Set based on WordPress debug configuration during construction.
	 */
	private readonly bool $debug_enabled;

	/**
	 * Private constructor enforces singleton pattern.
	 *
	 * Initializes debug flag based on WordPress configuration.
	 */
	private function __construct() {
		$this->debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	}

	/**
	 * Prevents cloning of the singleton instance.
	 */
	private function __clone() {}

	/**
	 * Prevents unserialization of the singleton instance.
	 *
	 * @throws \LogicException Always, to prevent unserialization
	 */
	public function __wakeup() {
		throw new \LogicException( 'Cannot unserialize singleton' );
	}

	/**
	 * Plugin initialization entry point.
	 *
	 * Registers the plugin to run only in admin context or during cron jobs
	 * since cache purging is only triggered by administrative actions.
	 */
	public static function init(): void {

		// This plugin does not perform any actions on the front end, so it
		// should only be run if we can be sure that we are on the back end
		// (self::is_frontend() returns false) or if it is not possible to
		// rule out that we are on the back end (self::is_frontend() returns
		// null). It should not be run until all plugins have loaded, as it
		// depends on the CLP Varnish Cache plugin.
		if ( self::is_frontend() !== true ) {
			add_action( 'plugins_loaded', [ self::instance(), 'run' ] );
		}

	}

	/**
	 * Returns the singleton instance of the plugin.
	 *
	 * Creates a new instance on first call, then returns the same
	 * instance on all subsequent calls within the request.
	 */
	public static function instance(): self {
		return self::$instance ??= new self;
	}

	/**
	 * Returns true or false to indicate whether it can be guaranteed to be
	 * called on the front or back end, respectively, and null if neither
	 * can be guaranteed. Back-end calls include cron, WP CLI, AJAX and REST.
	 *
	 * @return bool|null True iff called on front end. False iff called on back end. Null if it is not possible to determine whether it is frontend or backend.
	 */
	public static function is_frontend(): ?bool {

		if ( is_admin() ) {
			return false;
		}

		if ( wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$referer = wp_get_referer();
			if ( ! $referer ) {
				return null;
			}
			if ( str_starts_with( $referer, get_admin_url() ) ) {
				return false;
			}
			return true;
		}

		return null;

	}

	/**
	 * Main plugin execution method.
	 *
	 * Sets up the Varnish manager, applies configuration filters,
	 * and registers all necessary WordPress hooks for cache purging.
	 */
	public function run(): void {
		if ( $this->setup_varnish_manager() ) {

			/**
			 * Filter: kntnt-clp-varnish-cache-excluded-post-types
			 *
			 * Allows other plugins to modify which post types are excluded
			 * from cache purging. By default includes attachment and all
			 * non-public post types.
			 *
			 * @param array $excluded_post_types Array of post type names to exclude
			 */
			$this->excluded_post_types = apply_filters( 'kntnt-clp-varnish-cache-excluded-post-types', $this->excluded_post_types + $this->private_post_types() );

			/**
			 * Filter: kntnt-clp-varnish-cache-public-statuses
			 *
			 * Allows modification of which post statuses are considered public
			 * and should trigger cache purging.
			 *
			 * @param array $public_statuses Array of status names
			 */
			$this->public_statuses = apply_filters( 'kntnt-clp-varnish-cache-public-statuses', $this->public_statuses );

			/**
			 * Filter: kntnt-clp-varnish-cache-user-profile-fields
			 *
			 * Allows modification of which user profile fields trigger
			 * cache purging when changed.
			 *
			 * @param array $user_profile_fields Array of field names
			 */
			$this->user_profile_fields = apply_filters( 'kntnt-clp-varnish-cache-user-profile-fields', $this->user_profile_fields );

			/**
			 * Filter: kntnt-clp-varnish-cache-full-purge-hooks
			 *
			 * Allows modification of which WordPress hooks trigger
			 * a complete cache flush.
			 *
			 * @param array $full_purge_hooks Array of hook names
			 */
			$this->full_purge_hooks = apply_filters( 'kntnt-clp-varnish-cache-full-purge-hooks', $this->full_purge_hooks );

			// Register post lifecycle hooks
			add_action( 'pre_post_update', $this->handle_post_before_change( ... ), 10, 2 );
			add_action( 'save_post', $this->handle_post_after_change( ... ), 10, 3 );
			add_action( 'set_object_terms', $this->handle_post_term_changes( ... ), 10, 6 );
			add_action( 'before_delete_post', $this->handle_post_deletion( ... ), 10, 2 );

			// Register taxonomy lifecycle hooks
			add_action( 'edited_term', $this->handle_term_update( ... ), 10, 3 );
			add_action( 'delete_term', $this->handle_term_deletion( ... ), 10, 4 );

			// Register comment hooks
			add_action( 'transition_comment_status', $this->handle_comment_status_transition( ... ), 10, 3 );
			add_action( 'delete_comment', $this->handle_comment_deletion( ... ), 10, 1 );

			// Register user profile hook
			add_action( 'profile_update', $this->handle_profile_update( ... ), 10, 2 );

			// Register hooks for full cache purging
			foreach ( $this->full_purge_hooks as $hook ) {
				add_action( $hook, fn() => $this->is_full_purge = true );
			}

			// Execute all collected purges at request shutdown
			add_action( 'shutdown', $this->purge( ... ) );

		}
	}

	public function handle_post_before_change( int $post_id, array $data ) {

		$post = get_post( $post_id );

		if ( ! $this->allowed_post( $post ) ) {
			return;
		}

		$this->post_status_before_change[ $post_id ] = $post->post_status;

	}

	public function handle_post_after_change( int $post_id, \WP_Post $post_after, bool $update ): void {

		if ( ! $this->allowed_post( $post_after ) ) {
			return;
		}

		// A purge is required for any transition involving a public state. This ensures
		// that publishing a new post (draft -> publish) correctly purges stale archives
		// (e.g., homepage, categories) that must now list it.
		$new_status_is_published = in_array( $post_after->post_status, $this->public_statuses );
		$old_status_is_published = in_array( $this->post_status_before_change[ $post_id ] ?? '', $this->public_statuses );
		if ( ! $new_status_is_published && ! $old_status_is_published ) {
			return;
		}

		// Add the post and its core related URLs (author, date, etc.)
		$this->log_debug( "Handling post update for post ID: {$post_id}" );
		$this->add_post_to_purge_list( $post_after );

	}

	/**
	 * Adds term archives for removed terms to the purge list.
	 *
	 * This method complements add_post_to_purge_list(), which only handles
	 * the final state of the post. This function specifically catches
	 * terms that are no longer associated with the post after an update.
	 *
	 * @param int    $post_id    Object ID.
	 * @param array  $terms      An array of object terms.
	 * @param array  $tt_ids     An array of term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param bool   $append     Whether to append new terms to the old terms.
	 * @param array  $old_tt_ids An array of old term taxonomy IDs.
	 */
	public function handle_post_term_changes( int $post_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {

		// At this point, we can determine whether we are at the back end.
		// See the run() comment for an explanation.
		if ( self::is_frontend() !== false ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $this->allowed_post( $post ) || ! in_array( $post->post_status, $this->public_statuses ) ) {
			return;
		}

		$removed_tt_ids = array_diff( $old_tt_ids, $tt_ids );

		foreach ( $removed_tt_ids as $tt_id ) {
			if ( $term = get_term_by( 'term_taxonomy_id', $tt_id ) ) {
				$term_link = get_term_link( $term );
				if ( ! is_wp_error( $term_link ) ) {
					$this->urls[ $term_link ] = true;
					$this->log_debug( "Added term archive to purge list: {$term_link}" );
				}
			}
		}

	}

	/**
	 * Handles post deletion.
	 *
	 * Schedules cache purging for all pages affected by a post's deletion.
	 * Only processes public posts that would have been cached.
	 *
	 * @param int      $post_id ID of the post being deleted
	 * @param \WP_Post $post    The post object being deleted
	 */
	public function handle_post_deletion( int $post_id, \WP_Post $post ): void {

		if ( ! $this->allowed_post( $post ) || ! in_array( $post->post_status, $this->public_statuses ) ) {
			return;
		}

		$this->log_debug( "Handling deletion for post ID: {$post->ID}" );
		$this->add_post_to_purge_list( $post );

	}

	/**
	 * Handles taxonomy term updates.
	 *
	 * Schedules cache purging for term archives and all posts
	 * associated with the updated term.
	 *
	 * @param int    $term_id  Term ID
	 * @param int    $tt_id    Term taxonomy ID (unused but required by hook)
	 * @param string $taxonomy Taxonomy slug
	 */
	public function handle_term_update( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->log_debug( "Handling update for term ID: {$term_id} in taxonomy '{$taxonomy}'" );
		$this->add_term_to_purge_list( $term_id, $taxonomy );
	}

	/**
	 * Handles taxonomy term deletion.
	 *
	 * Schedules cache purging for all pages that referenced the deleted term.
	 *
	 * @param int      $term_id      Term ID
	 * @param int      $tt_id        Term taxonomy ID (unused but required by hook)
	 * @param string   $taxonomy     Taxonomy slug
	 * @param \WP_Term $deleted_term The deleted term object
	 */
	public function handle_term_deletion( int $term_id, int $tt_id, string $taxonomy, \WP_Term $deleted_term ): void {
		$this->log_debug( "Handling deletion for term '{$deleted_term->name}' (ID: {$term_id}) in taxonomy '{$taxonomy}'" );
		$this->add_term_to_purge_list( $term_id, $taxonomy );
	}

	/**
	 * Handles comment status transitions.
	 *
	 * Monitors when comments change visibility (approved, unapproved, spam, trash)
	 * and schedules cache purging for the affected post.
	 *
	 * @param string      $new_status New comment status
	 * @param string      $new_status New comment status
	 * @param string      $old_status Previous comment status
	 * @param \WP_Comment $comment    The comment object
	 */
	public function handle_comment_status_transition( string $new_status, string $old_status, \WP_Comment $comment ): void {
		// Only purge if comment visibility changes (to/from approved)
		if ( 'approve' === $new_status || 'approve' === $old_status ) {
			$this->log_debug( "Handling comment status change for comment ID: {$comment->comment_ID} ({$old_status} -> {$new_status})" );
			$this->add_comment_to_purge_list( $comment );
		}
	}

	/**
	 * Handles comment deletion.
	 *
	 * Schedules cache purging for the post associated with a deleted comment.
	 *
	 * @param int $comment_id ID of the deleted comment
	 */
	public function handle_comment_deletion( int $comment_id ): void {
		$this->log_debug( "Handling deletion for comment ID: {$comment_id}" );

		$comment = get_comment( $comment_id );
		if ( $comment instanceof \WP_Comment ) {
			$this->add_comment_to_purge_list( $comment );
		}
	}

	/**
	 * Handles user profile updates.
	 *
	 * Monitors changes to user fields that appear publicly (display name, bio, etc.)
	 * and schedules cache purging for the author's archive and posts.
	 *
	 * @param int      $user_id       ID of the updated user
	 * @param \WP_User $old_user_data User data before the update
	 */
	public function handle_profile_update( int $user_id, \WP_User $old_user_data ): void {
		$new_user_data = get_userdata( $user_id );
		if ( ! $new_user_data ) {
			return;
		}

		// Check if any public-facing fields changed
		foreach ( $this->user_profile_fields as $field ) {
			if ( $old_user_data->get( $field ) !== $new_user_data->get( $field ) ) {
				$this->log_debug( "Profile field '{$field}' changed for user ID: {$user_id}" );
				$this->add_author_to_purge_list( $user_id );
				return; // Only need to add once
			}
		}
	}

	/**
	 * Initializes the CLP Varnish Cache manager.
	 *
	 * Checks that the required plugin is active and loads its manager class.
	 * This method handles the dependency on the CLP Varnish Cache plugin.
	 *
	 * @return bool True if manager was successfully initialized and enabled, false otherwise
	 */
	private function setup_varnish_manager(): bool {

		// Load WordPress plugin API if running in cron context
		if ( wp_doing_cron() ) {
			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}

		// CLP Varnish Cache plugin file
		$clp_varnish_cache_plugin_file = WP_PLUGIN_DIR . '/clp-varnish-cache/class.varnish-cache-manager.php';

		// Verify plugin is active and class file exists
		if ( ! is_plugin_active( 'clp-varnish-cache/clp-varnish-cache.php' ) || ! file_exists( $clp_varnish_cache_plugin_file ) ) {
			error_log( "Either CLP Varnish Cache is not activated, or the plugin file cannot be found." );
			return false;
		}

		// Load the CLP Varnish Cache plugin and create an instance of its
		// ClpVarnishCacheManager class.
		require_once $clp_varnish_cache_plugin_file;
		$this->varnish_manager = new \ClpVarnishCacheManager;

		// Return whether the CLP varnish cache is enabled.
		return $this->varnish_manager->is_enabled();

	}

	/**
	 * Adds a post and all its related URLs to the purge queue.
	 *
	 * This includes the post itself, homepage, blog page, author archive,
	 * date archives, and all associated taxonomy term archives.
	 *
	 * @param \WP_Post $post The post to process
	 */
	private function add_post_to_purge_list( \WP_Post $post ): void {
		$urls = [];

		// Post permalink
		$urls[ get_permalink( $post ) ] = true;

		// Homepage (always affected by post changes)
		$urls[ home_url( '/' ) ] = true;

		// Blog page (if separate from homepage)
		if ( 'post' === $post->post_type && get_option( 'page_for_posts' ) ) {
			$urls[ get_permalink( get_option( 'page_for_posts' ) ) ] = true;
		}

		// Author archive
		$urls[ get_author_posts_url( $post->post_author ) ] = true;

		// Date archives (year, month, day)
		$post_date = strtotime( $post->post_date );
		$urls[ get_year_link( date( 'Y', $post_date ) ) ] = true;
		$urls[ get_month_link( date( 'Y', $post_date ), date( 'm', $post_date ) ) ] = true;
		$urls[ get_day_link( date( 'Y', $post_date ), date( 'm', $post_date ), date( 'd', $post_date ) ) ] = true;

		// All associated taxonomy term archives
		$taxonomies = get_object_taxonomies( $post, 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $post->ID, $taxonomy );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					$urls[ get_term_link( $term ) ] = true;
				}
			}
		}

		/**
		 * Filter: kntnt-clp-varnish-cache-post-purge-list
		 *
		 * Allows modification of URLs to purge when a post changes.
		 *
		 * @param array    $urls Array of URLs to purge
		 * @param \WP_Post $post The post being processed
		 */
		$this->urls += apply_filters( 'kntnt-clp-varnish-cache-post-purge-list', $urls, $post );
	}

	/**
	 * Adds a taxonomy term and all associated posts to the purge queue.
	 *
	 * @param int    $term_id  Term ID
	 * @param string $taxonomy Taxonomy slug
	 */
	private function add_term_to_purge_list( int $term_id, string $taxonomy ): void {

		// Add term archive URL
		$term_link = get_term_link( $term_id, $taxonomy );
		if ( ! is_wp_error( $term_link ) ) {
			$this->urls[ $term_link ] = true;
			$this->log_debug( "Added term archive to purge list: {$term_link}" );
		}

		// Find all posts with this term
		// Note: 'fields' => 'ids' reduces memory usage significantly,
		// but consider implementing pagination to handle terms with
		// many associated posts.
		$post_ids = get_posts( [
			'post_type' => get_post_types( [ 'public' => true ] ),
			'posts_per_page' => - 1,
			'fields' => 'ids',
			'tax_query' => [
				[
					'taxonomy' => $taxonomy,
					'field' => 'term_id',
					'terms' => $term_id,
				],
			],
		] );

		// Add each post and its related URLs
		// For very large sites, consider implementing batching here
		if ( ! empty( $post_ids ) ) {
			$this->log_debug( 'Found ' . count( $post_ids ) . " posts with term ID {$term_id}" );
			foreach ( $post_ids as $post_id ) {
				$post_object = get_post( $post_id );
				if ( $post_object instanceof \WP_Post ) {
					$this->add_post_to_purge_list( $post_object );
				}
			}
		}
	}

	/**
	 * Adds a comment's parent post to the purge queue.
	 *
	 * @param \WP_Comment $comment The comment object
	 */
	private function add_comment_to_purge_list( \WP_Comment $comment ): void {

		$post = get_post( $comment->comment_post_ID );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_status, $this->public_statuses ) ) {
			return;
		}

		$this->log_debug( "Comment change affects post ID: {$post->ID}" );
		$this->add_post_to_purge_list( $post );

	}

	/**
	 * Adds an author archive and all author's posts to the purge queue.
	 *
	 * @param int $user_id User ID of the author
	 */
	private function add_author_to_purge_list( int $user_id ): void {
		// Add author archive page
		$author_url = get_author_posts_url( $user_id );
		if ( $author_url ) {
			$this->urls[ $author_url ] = true;
			$this->log_debug( "Added author archive to purge list: {$author_url}" );
		}

		// Find all posts by this author
		// Note: 'fields' => 'ids' reduces memory usage significantly,
		// but consider implementing pagination to handle authors with
		// many associated posts.
		$post_ids = get_posts( [
			'post_type' => get_post_types( [ 'public' => true ] ),
			'author' => $user_id,
			'posts_per_page' => - 1,
			'fields' => 'ids',
		] );

		// Add each post and its related URLs
		// For very large sites with prolific authors, consider batching
		if ( ! empty( $post_ids ) ) {
			$this->log_debug( 'Found ' . count( $post_ids ) . " posts by user {$user_id}" );
			foreach ( $post_ids as $post_id ) {
				$post_object = get_post( $post_id );
				if ( $post_object instanceof \WP_Post ) {
					$this->add_post_to_purge_list( $post_object );
				}
			}
		}
	}


	/**
	 * Purges the cache if necessary.
	 *
	 * This method purges completely or partially if necessary,
	 * and not at all if not.
	 *
	 * @return void
	 */
	private function purge(): void {

		if ( $this->is_full_purge ) {

			// Purge the entire cache.
			$this->purge_entire_cache();

		}
		else {

			/**
			 * Filter: kntnt-clp-varnish-cache-purge-urls
			 *
			 * Final filter allowing modification of URLs before purging.
			 *
			 * @param array $urls Array of URLs scheduled for purging
			 */
			$this->urls = apply_filters( 'kntnt-clp-varnish-cache-purge-urls', $this->urls );

			// Validate and filter URLs
			$this->urls = array_filter( $this->urls, fn( bool $purge, string $url ): bool => $purge && filter_var( $url, FILTER_VALIDATE_URL ) !== false, ARRAY_FILTER_USE_BOTH );

			// Purge individual URLs.
			if ( ! empty( $this->urls ) ) {
				$this->purge_urls();
			}

		}

		// Reset state variables so that the same instance can be reused (e.g., for unit testing).
		$this->urls = [];
		$this->is_full_purge = false;
		$this->post_status_before_change = [];

	}

	/**
	 * Performs a complete cache flush.
	 *
	 * Purges all cached content by sending purge requests for the entire host
	 * and the configured cache tag prefix. This is triggered by major site
	 * changes that affect global elements.
	 *
	 * The method includes protection against multiple executions per request.
	 */
	private function purge_entire_cache(): void {
		try {
			// Get host from WordPress configuration (more secure than $_SERVER)
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( ! empty( $host ) ) {
				$this->varnish_manager->purge_host( $host );
				$this->log_debug( "Purged entire cache for host: {$host}" );
			}

			// Also purge by cache tag prefix if configured
			$cache_tag_prefix = $this->varnish_manager->get_cache_tag_prefix();
			if ( ! empty( $cache_tag_prefix ) ) {
				$this->varnish_manager->purge_tag( $cache_tag_prefix );
				$this->log_debug( "Purged cache tag: {$cache_tag_prefix}" );
			}

			// Set flag to prevent redundant purges
			$this->is_full_purge = true;

		} catch ( \Throwable $e ) {
			$this->log_error( 'Failed to perform full cache purge: ' . $e->getMessage() );
		}
	}

	/**
	 * Executes cache purging for all collected URLs.
	 *
	 * This method runs at shutdown, purging all URLs that were collected
	 * during the request. It includes deduplication and validation.
	 *
	 * For large-scale sites, consider implementing:
	 * - Batch processing to avoid timeouts
	 * - Asynchronous purging via wp-cron or external queue
	 * - Rate limiting to prevent overwhelming the cache server
	 */
	private function purge_urls(): void {
		foreach ( $this->urls as $url => $do_it ) {
			try {
				$this->varnish_manager->purge_url( $url );
				$this->log_debug( "Purged: {$url}" );
			} catch ( \Throwable $e ) {
				$this->log_error( "Failed to purge {$url}: " . $e->getMessage() );
			}
		}
	}

	/**
	 * Returns all non-public post types.
	 *
	 * @return string[] Array of post type names
	 */
	private function private_post_types(): array {
		return get_post_types( [ 'public' => false ], 'names' );
	}

	/**
	 * Logs error messages to the WordPress error log.
	 *
	 * @param string $message Error message to log
	 */

	/**
	 * Checks if a post should trigger cache operations.
	 *
	 * Excludes autosaves, revisions, and configured post types from cache
	 * purging operations. Note that post status is intentionally not checked.
	 *
	 * @param ?\WP_Post $post The post to check
	 *
	 * @return bool True if the post should be processed, false otherwise
	 */
	private function allowed_post( ?\WP_Post $post ): bool {
		return ( $post instanceof \WP_Post ) && ! in_array( $post->post_type, $this->excluded_post_types ) && ! wp_is_post_autosave( $post ) && ! wp_is_post_revision( $post );
	}

	private function log_error( string $message ): void {
		error_log( "[Kntnt Varnish Purger][ERROR] {$message}" );
	}

	/**
	 * Logs debug messages when WordPress debug logging is enabled.
	 *
	 * Messages are only logged if both WP_DEBUG and WP_DEBUG_LOG are true.
	 *
	 * @param string $message Debug message to log
	 */
	private function log_debug( string $message ): void {
		if ( $this->debug_enabled ) {
			error_log( "[Kntnt Varnish Purger][DEBUG] {$message}" );
		}
	}

}
