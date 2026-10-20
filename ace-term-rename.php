<?php
/**
 * Plugin Name: Ace Term Rename
 * Description: One-shot, reversible taxonomy term rename. Ships with an example job that retires the "propaganda" category in favour of "mischief"; add your own jobs with the filter. Preview, run and roll back from Tools or WP-CLI, then deactivate and delete the plugin.
 * Version: 1.0.0
 * Author: Shane Rounce
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 8.0
 *
 * WHY A RENAME RATHER THAN A NEW TERM
 * -----------------------------------
 * The obvious approach — create "mischief", move the posts, delete "propaganda" — silently
 * breaks things, because a term_id is a foreign key in more places than the taxonomy tables:
 *
 *   wp_bet_clicks.post_category_id        historic click analytics, keyed on the term id
 *   wp_bet_clicks_daily_agg.post_category_id  the aggregate the dashboards actually read
 *   wp_termmeta                           the ACF category fields (meta title, images, nav flags)
 *   nav_menu items                        stored as _menu_item_object_id
 *
 * Renaming in place keeps every one of those pointing at the right thing, moves no rows in
 * wp_term_relationships, and is reversible by writing two strings back. The only thing that
 * changes is the public URL, which is what the redirects in the theme are for.
 *
 * WHAT IT DOES
 * ------------
 *   1. Renames the term (name + slug), after checking the target slug is free.
 *   2. Rewrites the stale "Propaganda" label out of the category's SEO meta title.
 *   3. Relabels navigation entries pointing at the term. A block navigation stores its
 *      visible text separately from the URL, so without this the menu keeps saying
 *      "Propaganda" while linking to /mischief/.
 *   4. Optionally rewrites /propaganda/ internal links in article bodies. Off by default:
 *      the redirects make it unnecessary for correctness, and it touches thousands of rows.
 *
 * Every step snapshots what it changed before changing it, so rollback is exact.
 *
 * @package ace-term-rename
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ACE_TERM_RENAME_OPTION = 'ace_term_rename_state';

/**
 * The rename jobs this plugin knows about.
 *
 * Keyed by job id. Filterable so a second rename does not need a code change.
 *
 * @return array<string, array>
 */
function ace_term_rename_jobs(): array {
	return (array) apply_filters(
		'ace_term_rename_jobs',
		[
			'propaganda-to-mischief' => [
				'label'       => 'Propaganda → Mischief',
				'taxonomy'    => 'category',
				'from_slug'   => 'propaganda',
				'to_slug'     => 'mischief',
				'from_name'   => 'Propaganda',
				'to_name'     => 'Mischief',
				// Term meta whose value carries the old label as text.
				'meta_keys'   => [ 'category_meta_title', 'category_meta_description', 'category_h1' ],
				// Post meta holding article bodies that may contain /propaganda/ links.
				'content_keys' => [ 'main_content', 'pundit_page_furniture' ],
			],
		]
	);
}

function ace_term_rename_job( string $job_id ): ?array {
	$jobs = ace_term_rename_jobs();
	return $jobs[ $job_id ] ?? null;
}

/* -------------------------------------------------------------------------- *
 * State
 * -------------------------------------------------------------------------- */

function ace_term_rename_state(): array {
	return (array) get_option( ACE_TERM_RENAME_OPTION, [] );
}

function ace_term_rename_save_state( string $job_id, array $data ): void {
	$state             = ace_term_rename_state();
	$state[ $job_id ]  = $data;
	update_option( ACE_TERM_RENAME_OPTION, $state, false );
}

function ace_term_rename_clear_state( string $job_id ): void {
	$state = ace_term_rename_state();
	unset( $state[ $job_id ] );
	update_option( ACE_TERM_RENAME_OPTION, $state, false );
}

/**
 * Locate the term, whichever side of the rename it currently sits on.
 *
 * @return array{term: ?WP_Term, direction: string}
 */
function ace_term_rename_locate( array $job ): array {
	$before = get_term_by( 'slug', $job['from_slug'], $job['taxonomy'] );
	if ( $before instanceof WP_Term ) {
		return [ 'term' => $before, 'direction' => 'pending' ];
	}

	$after = get_term_by( 'slug', $job['to_slug'], $job['taxonomy'] );
	if ( $after instanceof WP_Term ) {
		return [ 'term' => $after, 'direction' => 'done' ];
	}

	return [ 'term' => null, 'direction' => 'missing' ];
}

/* -------------------------------------------------------------------------- *
 * Navigation labels
 * -------------------------------------------------------------------------- */

/**
 * Find navigation entries pointing at a term, wherever they live.
 *
 * A block navigation stores the visible text in the block's own `label` attribute, quite
 * separately from the URL. Rewriting URLs therefore leaves the menu still reading
 * "Propaganda" while linking to /mischief/ — the stale label this plugin exists to
 * eliminate. Classic menus have the same problem via a nav_menu_item's post_title.
 *
 * Matching is by term id rather than by text, so a menu entry that legitimately contains the
 * word elsewhere is never touched.
 *
 * @return array<int, array{post_id:int, title:string, labels:string[]}>
 */
function ace_term_rename_find_nav_labels( int $term_id, string $taxonomy, string $old_name ): array {
	$hits = [];

	// ── Block navigations ────────────────────────────────────────────────────
	$navigations = get_posts(
		[
			'post_type'      => 'wp_navigation',
			'posts_per_page' => 50,
			'post_status'    => 'any',
			'no_found_rows'  => true,
		]
	);

	foreach ( $navigations as $nav ) {
		$labels = [];

		preg_replace_callback(
			'/<!--\s+wp:navigation-link\s+(\{.*?\})\s+\/-->/s',
			static function ( $m ) use ( $term_id, $taxonomy, &$labels ) {
				$attrs = json_decode( $m[1], true );
				if ( is_array( $attrs )
					&& (int) ( $attrs['id'] ?? 0 ) === $term_id
					&& ( $attrs['type'] ?? '' ) === $taxonomy
					&& isset( $attrs['label'] )
				) {
					$labels[] = (string) $attrs['label'];
				}
				return $m[0];
			},
			$nav->post_content
		);

		if ( $labels ) {
			$hits[] = [ 'post_id' => (int) $nav->ID, 'title' => $nav->post_title, 'labels' => $labels, 'kind' => 'block' ];
		}
	}

	// ── Classic menus ────────────────────────────────────────────────────────
	global $wpdb;
	$classic = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_title
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_menu_item_object_id'
			 WHERE p.post_type = 'nav_menu_item' AND pm.meta_value = %d AND p.post_title <> ''",
			$term_id
		),
		ARRAY_A
	) ?: [];

	foreach ( $classic as $row ) {
		$hits[] = [ 'post_id' => (int) $row['ID'], 'title' => $row['post_title'], 'labels' => [ $row['post_title'] ], 'kind' => 'classic' ];
	}

	return $hits;
}

/**
 * Rewrite the label on navigation entries pointing at this term.
 *
 * Only the matching block comment is re-encoded; the rest of the navigation content is left
 * byte-for-byte alone rather than round-tripped through the block serialiser.
 *
 * @return array{changed:int, snapshot:array<int,string>}
 */
function ace_term_rename_apply_nav_labels( int $term_id, string $taxonomy, string $new_label, string $old_path = '', string $new_path = '' ): array {
	$changed  = 0;
	$snapshot = [];

	foreach ( ace_term_rename_find_nav_labels( $term_id, $taxonomy, '' ) as $hit ) {
		$post = get_post( $hit['post_id'] );
		if ( ! $post ) {
			continue;
		}

		if ( 'classic' === $hit['kind'] ) {
			$snapshot[ $hit['post_id'] ] = $post->post_title;
			wp_update_post( [ 'ID' => $hit['post_id'], 'post_title' => $new_label ] );
			$changed++;
			continue;
		}

		$snapshot[ $hit['post_id'] ] = $post->post_content;

		$updated = preg_replace_callback(
			'/<!--\s+wp:navigation-link\s+(\{.*?\})\s+\/-->/s',
			static function ( $m ) use ( $term_id, $taxonomy, $new_label, $old_path, $new_path ) {
				$attrs = json_decode( $m[1], true );
				if ( ! is_array( $attrs )
					|| (int) ( $attrs['id'] ?? 0 ) !== $term_id
					|| ( $attrs['type'] ?? '' ) !== $taxonomy
				) {
					return $m[0];
				}

				$attrs['label'] = $new_label;

				// Swap the path only. Using get_term_link() here would rewrite the stored
				// absolute URL to whichever domain this environment happens to be on — the
				// navigation may hold a different domain from this environment and must keep it.
				if ( '' !== $old_path && ! empty( $attrs['url'] ) ) {
					$attrs['url'] = str_replace( $old_path, $new_path, (string) $attrs['url'] );
				}

				return '<!-- wp:navigation-link ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' /-->';
			},
			$post->post_content
		);

		if ( $updated !== $post->post_content ) {
			wp_update_post( [ 'ID' => $hit['post_id'], 'post_content' => $updated ] );
			$changed++;
		}
	}

	return [ 'changed' => $changed, 'snapshot' => $snapshot ];
}

/* -------------------------------------------------------------------------- *
 * Preview
 * -------------------------------------------------------------------------- */

/**
 * Everything the run would touch, counted, without writing anything.
 */
function ace_term_rename_preview( string $job_id ): array {
	global $wpdb;

	$job = ace_term_rename_job( $job_id );
	if ( ! $job ) {
		return [ 'ok' => false, 'message' => 'Unknown job: ' . $job_id ];
	}

	$located = ace_term_rename_locate( $job );
	$term    = $located['term'];

	if ( ! $term ) {
		return [
			'ok'      => false,
			'message' => sprintf(
				'No "%s" or "%s" term found in %s — nothing to do.',
				$job['from_slug'],
				$job['to_slug'],
				$job['taxonomy']
			),
		];
	}

	// Refuse if the destination slug already belongs to a different term.
	$clash = get_term_by( 'slug', $job['to_slug'], $job['taxonomy'] );
	if ( $clash instanceof WP_Term && (int) $clash->term_id !== (int) $term->term_id ) {
		return [
			'ok'      => false,
			'message' => sprintf(
				'Cannot rename: "%s" already exists as term #%d. Merge it by hand first.',
				$job['to_slug'],
				$clash->term_id
			),
		];
	}

	$term_id = (int) $term->term_id;

	// Content in the term, by status.
	$counts = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.post_status, COUNT(*) AS n
			 FROM {$wpdb->term_relationships} tr
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			 WHERE tt.term_id = %d
			 GROUP BY p.post_status",
			$term_id
		),
		ARRAY_A
	) ?: [];

	// Term meta carrying the old label as visible text.
	$meta_hits = [];
	foreach ( $job['meta_keys'] as $key ) {
		$value = (string) get_term_meta( $term_id, $key, true );
		if ( '' !== $value && stripos( $value, $job['from_name'] ) !== false ) {
			$meta_hits[ $key ] = [
				'before' => $value,
				'after'  => str_ireplace( $job['from_name'], $job['to_name'], $value ),
			];
		}
	}

	// Article bodies holding /old-slug/ links.
	$from_path = '/' . $job['from_slug'] . '/';
	$content_hits = [];
	foreach ( $job['content_keys'] as $key ) {
		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				$key,
				'%' . $wpdb->esc_like( $from_path ) . '%'
			)
		);
		if ( $n > 0 ) {
			$content_hits[ $key ] = $n;
		}
	}

	$post_content_hits = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s",
			'%' . $wpdb->esc_like( $from_path ) . '%'
		)
	);

	// Analytics rows keyed on this term id — the reason we rename rather than recreate.
	$analytics = [];
	foreach ( [ 'bet_clicks', 'bet_clicks_daily_agg' ] as $table ) {
		$full = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full ) {
			$analytics[ $table ] = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$full} WHERE post_category_id = %d", $term_id ) // phpcs:ignore
			);
		}
	}

	// Navigation entries still showing the old label.
	$nav_hits = ace_term_rename_find_nav_labels( $term_id, $job['taxonomy'], $job['from_name'] );

	// Things the migration deliberately leaves alone, surfaced so nobody has to guess whether
	// they were missed. Media filenames are not labels, and renaming files breaks every other
	// reference to them.
	$untouched = [];
	$image_id  = (int) get_term_meta( $term_id, 'category_main_image', true );
	if ( $image_id ) {
		$file = (string) get_post_meta( $image_id, '_wp_attached_file', true );
		if ( '' !== $file && stripos( $file, $job['from_slug'] ) !== false ) {
			$untouched[] = 'Category image filename: ' . $file . ' (renaming media breaks other references — left as is)';
		}
	}
	$prose = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE meta_key = 'main_content' AND meta_value LIKE %s AND meta_value NOT LIKE %s",
			'%' . $wpdb->esc_like( $job['from_name'] ) . '%',
			'%' . $wpdb->esc_like( $from_path ) . '%'
		)
	);
	if ( $prose > 0 ) {
		$untouched[] = $prose . ' article bodies use the word "' . $job['from_name'] . '" in prose (editorial copy — left as is)';
	}

	return [
		'ok'            => true,
		'job_id'        => $job_id,
		'nav_hits'      => $nav_hits,
		'untouched'     => $untouched,
		'direction'     => $located['direction'],
		'term_id'       => $term_id,
		'current_name'  => $term->name,
		'current_slug'  => $term->slug,
		'target_name'   => $job['to_name'],
		'target_slug'   => $job['to_slug'],
		'post_counts'   => $counts,
		'relationships' => (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tt.term_id = %d",
				$term_id
			)
		),
		'meta_hits'         => $meta_hits,
		'content_hits'      => $content_hits,
		'post_content_hits' => $post_content_hits,
		'analytics'         => $analytics,
		'has_snapshot'      => isset( ace_term_rename_state()[ $job_id ] ),
	];
}

/* -------------------------------------------------------------------------- *
 * Run
 * -------------------------------------------------------------------------- */

/**
 * Perform the rename.
 *
 * @param string $job_id
 * @param bool   $rewrite_content Also rewrite /old/ links inside article bodies.
 * @return array
 */
function ace_term_rename_run( string $job_id, bool $rewrite_content = false ): array {
	global $wpdb;

	$job = ace_term_rename_job( $job_id );
	if ( ! $job ) {
		return [ 'ok' => false, 'message' => 'Unknown job: ' . $job_id ];
	}

	$preview = ace_term_rename_preview( $job_id );
	if ( empty( $preview['ok'] ) ) {
		return $preview;
	}

	$term_id = (int) $preview['term_id'];
	$log     = [];

	// Snapshot before touching anything. Kept even after a successful run so rollback stays
	// possible until somebody explicitly discards it.
	$snapshot = ace_term_rename_state()[ $job_id ] ?? [
		'term_id'         => $term_id,
		'name'            => $preview['current_name'],
		'slug'            => $preview['current_slug'],
		'meta'            => [],
		'nav'             => [],
		'content_rewritten' => false,
		'ran_at'          => current_time( 'mysql' ),
	];

	foreach ( $preview['meta_hits'] as $key => $pair ) {
		if ( ! array_key_exists( $key, $snapshot['meta'] ) ) {
			$snapshot['meta'][ $key ] = $pair['before'];
		}
	}

	// ── 1. The term itself ───────────────────────────────────────────────────
	if ( 'pending' === $preview['direction'] ) {
		$result = wp_update_term(
			$term_id,
			$job['taxonomy'],
			[
				'name' => $job['to_name'],
				'slug' => $job['to_slug'],
			]
		);

		if ( is_wp_error( $result ) ) {
			return [ 'ok' => false, 'message' => 'Term rename failed: ' . $result->get_error_message() ];
		}
		$log[] = sprintf( 'Renamed term #%d to "%s" (%s).', $term_id, $job['to_name'], $job['to_slug'] );
	} else {
		$log[] = sprintf( 'Term #%d is already "%s" — left alone.', $term_id, $job['to_slug'] );
	}

	// ── 2. Stale label in the category's own SEO meta ────────────────────────
	foreach ( $preview['meta_hits'] as $key => $pair ) {
		update_term_meta( $term_id, $key, $pair['after'] );
		$log[] = sprintf( 'Updated term meta "%s".', $key );
	}

	// ── 3. Navigation labels ─────────────────────────────────────────────────
	// Part of the core migration, not the optional content rewrite: a menu reading
	// "Propaganda" but linking to /mischief/ is worse than either state on its own.
	$nav = ace_term_rename_apply_nav_labels(
		$term_id,
		$job['taxonomy'],
		$job['to_name'],
		'/' . $job['from_slug'] . '/',
		'/' . $job['to_slug'] . '/'
	);

	if ( $nav['changed'] > 0 ) {
		if ( empty( $snapshot['nav'] ) ) {
			$snapshot['nav'] = $nav['snapshot'];
		}
		$log[] = sprintf( 'Relabelled %d navigation entr%s.', $nav['changed'], 1 === $nav['changed'] ? 'y' : 'ies' );
	} else {
		$log[] = 'No navigation entries needed relabelling.';
	}

	// ── 4. Internal links in article bodies (opt-in) ─────────────────────────
	if ( $rewrite_content ) {
		$from_path = '/' . $job['from_slug'] . '/';
		$to_path   = '/' . $job['to_slug'] . '/';
		$rows      = 0;

		foreach ( $job['content_keys'] as $key ) {
			$rows += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta}
					 SET meta_value = REPLACE(meta_value, %s, %s)
					 WHERE meta_key = %s AND meta_value LIKE %s",
					$from_path,
					$to_path,
					$key,
					'%' . $wpdb->esc_like( $from_path ) . '%'
				)
			);
		}

		$rows += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts}
				 SET post_content = REPLACE(post_content, %s, %s)
				 WHERE post_content LIKE %s",
				$from_path,
				$to_path,
				'%' . $wpdb->esc_like( $from_path ) . '%'
			)
		);

		$snapshot['content_rewritten'] = true;
		$log[] = sprintf( 'Rewrote %d rows of article content from %s to %s.', $rows, $from_path, $to_path );
	} else {
		$log[] = 'Article content left untouched — the theme redirects handle old links.';
	}

	ace_term_rename_save_state( $job_id, $snapshot );
	ace_term_rename_finish( $log );

	return [ 'ok' => true, 'log' => $log, 'term_id' => $term_id ];
}

/**
 * Reverse a previous run using its snapshot.
 */
function ace_term_rename_rollback( string $job_id ): array {
	global $wpdb;

	$job      = ace_term_rename_job( $job_id );
	$snapshot = ace_term_rename_state()[ $job_id ] ?? null;

	if ( ! $job || ! $snapshot ) {
		return [ 'ok' => false, 'message' => 'No snapshot recorded for ' . $job_id . ' — nothing to roll back.' ];
	}

	$term_id = (int) $snapshot['term_id'];
	$log     = [];

	$result = wp_update_term(
		$term_id,
		$job['taxonomy'],
		[
			'name' => $snapshot['name'],
			'slug' => $snapshot['slug'],
		]
	);

	if ( is_wp_error( $result ) ) {
		return [ 'ok' => false, 'message' => 'Rollback failed: ' . $result->get_error_message() ];
	}
	$log[] = sprintf( 'Restored term #%d to "%s" (%s).', $term_id, $snapshot['name'], $snapshot['slug'] );

	foreach ( (array) $snapshot['meta'] as $key => $value ) {
		update_term_meta( $term_id, $key, $value );
		$log[] = sprintf( 'Restored term meta "%s".', $key );
	}

	foreach ( (array) ( $snapshot['nav'] ?? [] ) as $post_id => $original ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			continue;
		}
		$field = ( 'nav_menu_item' === $post->post_type ) ? 'post_title' : 'post_content';
		wp_update_post( [ 'ID' => (int) $post_id, $field => $original ] );
		$log[] = sprintf( 'Restored navigation entry #%d.', (int) $post_id );
	}

	if ( ! empty( $snapshot['content_rewritten'] ) ) {
		$from_path = '/' . $job['to_slug'] . '/';
		$to_path   = '/' . $job['from_slug'] . '/';
		$rows      = 0;

		foreach ( $job['content_keys'] as $key ) {
			$rows += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)
					 WHERE meta_key = %s AND meta_value LIKE %s",
					$from_path,
					$to_path,
					$key,
					'%' . $wpdb->esc_like( $from_path ) . '%'
				)
			);
		}
		$rows += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)
				 WHERE post_content LIKE %s",
				$from_path,
				$to_path,
				'%' . $wpdb->esc_like( $from_path ) . '%'
			)
		);
		$log[] = sprintf( 'Reversed %d rows of article content.', $rows );
	}

	ace_term_rename_clear_state( $job_id );
	ace_term_rename_finish( $log );

	return [ 'ok' => true, 'log' => $log ];
}

/**
 * Clear the caches that would otherwise keep serving the old slug.
 */
function ace_term_rename_finish( array &$log ): void {
	// Best effort, and deliberately non-fatal. The database work has already committed by
	// this point; a cache backend that is unreachable (wp-cli without phpredis, for one)
	// must not turn a completed migration into an error the operator has to interpret.
	foreach (
		[
			'term cache'    => static fn() => clean_term_cache( [], 'category' ),
			'rewrite rules' => static fn() => flush_rewrite_rules( false ),
			'object cache'  => static fn() => wp_cache_flush(),
		] as $what => $fn
	) {
		try {
			$fn();
			$log[] = 'Flushed ' . $what . '.';
		} catch ( \Throwable $e ) {
			$log[] = 'Could not flush ' . $what . ' (' . $e->getMessage() . ') — clear it by hand.';
		}
	}

	$log[] = 'Page cache still needs purging separately — see the plugin notes.';
}

/* -------------------------------------------------------------------------- *
 * Admin screen
 * -------------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_management_page(
		'Term rename',
		'Term rename',
		'manage_options',
		'ace-term-rename',
		'ace_term_rename_admin_page'
	);
} );

function ace_term_rename_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to run migrations.', 'ace-term-rename' ) );
	}

	$notice = '';
	$action = isset( $_POST['ace_term_rename_action'] ) ? sanitize_key( wp_unslash( $_POST['ace_term_rename_action'] ) ) : '';
	$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

	if ( $action && check_admin_referer( 'ace_term_rename' ) ) {
		if ( 'run' === $action ) {
			$result = ace_term_rename_run( $job_id, ! empty( $_POST['rewrite_content'] ) );
		} elseif ( 'rollback' === $action ) {
			$result = ace_term_rename_rollback( $job_id );
		} else {
			$result = [ 'ok' => false, 'message' => 'Unknown action.' ];
		}

		$notice = empty( $result['ok'] )
			? '<div class="notice notice-error"><p>' . esc_html( $result['message'] ?? 'Failed.' ) . '</p></div>'
			: '<div class="notice notice-success"><p>' . implode( '<br>', array_map( 'esc_html', $result['log'] ) ) . '</p></div>';
	}

	echo '<div class="wrap"><h1>Term rename</h1>';
	echo '<p>One-shot taxonomy migrations. Preview first, then run. Every run records a snapshot so it can be reversed.</p>';
	// Notice HTML is assembled from esc_html'd parts above.
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	foreach ( ace_term_rename_jobs() as $id => $job ) {
		$preview = ace_term_rename_preview( $id );

		echo '<hr><h2>' . esc_html( $job['label'] ) . '</h2>';

		if ( empty( $preview['ok'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $preview['message'] ) . '</p></div>';
			continue;
		}

		$done = 'done' === $preview['direction'];

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		printf(
			'<tr><th style="width:230px">Status</th><td>%s</td></tr>',
			$done
				? '<strong style="color:#1a7f37">Already renamed</strong>'
				: '<strong style="color:#8a6d00">Not yet run</strong>'
		);
		printf( '<tr><th>Term</th><td>#%d &mdash; %s (<code>%s</code>)</td></tr>', (int) $preview['term_id'], esc_html( $preview['current_name'] ), esc_html( $preview['current_slug'] ) );
		printf( '<tr><th>Becomes</th><td>%s (<code>%s</code>)</td></tr>', esc_html( $preview['target_name'] ), esc_html( $preview['target_slug'] ) );

		$statuses = [];
		foreach ( $preview['post_counts'] as $row ) {
			$statuses[] = esc_html( $row['post_status'] ) . ': ' . (int) $row['n'];
		}
		printf(
			'<tr><th>Content</th><td>%s &mdash; %d term relationships, none of which move</td></tr>',
			$statuses ? implode( ', ', $statuses ) : 'none',
			(int) $preview['relationships']
		);

		foreach ( $preview['analytics'] as $table => $n ) {
			printf(
				'<tr><th>Analytics <code>%s</code></th><td>%s rows keyed on this term id &mdash; preserved by renaming in place</td></tr>',
				esc_html( $table ),
				esc_html( number_format_i18n( $n ) )
			);
		}

		if ( $preview['meta_hits'] ) {
			echo '<tr><th>Stale labels in term meta</th><td>';
			foreach ( $preview['meta_hits'] as $key => $pair ) {
				printf(
					'<code>%s</code><br><span style="color:#777">%s</span><br>&rarr; %s<br><br>',
					esc_html( $key ),
					esc_html( $pair['before'] ),
					esc_html( $pair['after'] )
				);
			}
			echo '</td></tr>';
		}

		if ( $preview['nav_hits'] ) {
			echo '<tr><th>Navigation labels</th><td>';
			foreach ( $preview['nav_hits'] as $hit ) {
				printf(
					'#%d &ldquo;%s&rdquo; (%s) &mdash; %s<br>',
					(int) $hit['post_id'],
					esc_html( $hit['title'] ),
					esc_html( $hit['kind'] ),
					esc_html( implode( ', ', $hit['labels'] ) )
				);
			}
			echo '</td></tr>';
		}

		if ( $preview['untouched'] ) {
			echo '<tr><th>Deliberately left alone</th><td>';
			foreach ( $preview['untouched'] as $note ) {
				echo esc_html( $note ) . '<br>';
			}
			echo '</td></tr>';
		}

		$total_content = array_sum( $preview['content_hits'] ) + (int) $preview['post_content_hits'];
		printf(
			'<tr><th>Internal links</th><td>%s rows contain <code>/%s/</code> links. Optional &mdash; the theme redirects cover these either way.</td></tr>',
			esc_html( number_format_i18n( $total_content ) ),
			esc_html( $preview['current_slug'] === $job['from_slug'] ? $job['from_slug'] : $job['to_slug'] )
		);
		echo '</tbody></table>';

		echo '<form method="post" style="margin-top:1em">';
		wp_nonce_field( 'ace_term_rename' );
		printf( '<input type="hidden" name="job_id" value="%s" />', esc_attr( $id ) );

		if ( ! $done ) {
			echo '<p><label><input type="checkbox" name="rewrite_content" value="1" /> ';
			echo 'Also rewrite internal links inside article bodies (slower, and reversible)</label></p>';
			echo '<p><button class="button button-primary" name="ace_term_rename_action" value="run" ';
			echo 'onclick="return confirm(\'Run this rename now?\')">Run migration</button></p>';
		}

		if ( ! empty( $preview['has_snapshot'] ) ) {
			echo '<p><button class="button" name="ace_term_rename_action" value="rollback" ';
			echo 'onclick="return confirm(\'Roll this migration back?\')">Roll back</button> ';
			echo '<span style="color:#777">A snapshot from the last run is stored.</span></p>';
		}

		echo '</form>';
	}

	echo '<hr><h2>Afterwards</h2><ol>';
	echo '<li>Purge the page cache — the plugin cannot reach it. Old category and article URLs stay cached otherwise.</li>';
	echo '<li>Check the redirects: an old URL should 301 to the new one.</li>';
	echo '<li>Once you are happy, deactivate and delete this plugin. The redirects live in the theme and stay.</li>';
	echo '</ol></div>';
}

/* -------------------------------------------------------------------------- *
 * WP-CLI
 * -------------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Rename a taxonomy term, reversibly.
	 */
	WP_CLI::add_command(
		'ace-term-rename',
		function ( $args, $assoc_args ) {
			$sub    = $args[0] ?? 'status';
			$job_id = $args[1] ?? 'propaganda-to-mischief';

			if ( 'jobs' === $sub ) {
				foreach ( ace_term_rename_jobs() as $id => $job ) {
					WP_CLI::log( $id . ' — ' . $job['label'] );
				}
				return;
			}

			if ( in_array( $sub, [ 'status', 'preview' ], true ) ) {
				$preview = ace_term_rename_preview( $job_id );
				if ( empty( $preview['ok'] ) ) {
					WP_CLI::error( $preview['message'] );
				}

				WP_CLI::log( 'Job:       ' . $job_id );
				WP_CLI::log( 'Status:    ' . ( 'done' === $preview['direction'] ? 'already renamed' : 'not yet run' ) );
				WP_CLI::log( sprintf( 'Term:      #%d %s (%s)', $preview['term_id'], $preview['current_name'], $preview['current_slug'] ) );
				WP_CLI::log( sprintf( 'Becomes:   %s (%s)', $preview['target_name'], $preview['target_slug'] ) );
				WP_CLI::log( 'Relations: ' . $preview['relationships'] . ' (none move)' );

				foreach ( $preview['post_counts'] as $row ) {
					WP_CLI::log( sprintf( '  %-10s %d', $row['post_status'], $row['n'] ) );
				}
				foreach ( $preview['analytics'] as $table => $n ) {
					WP_CLI::log( sprintf( 'Analytics: %-22s %s rows on this term id (preserved)', $table, number_format_i18n( $n ) ) );
				}
				foreach ( $preview['meta_hits'] as $key => $pair ) {
					WP_CLI::log( 'Term meta: ' . $key );
					WP_CLI::log( '    before: ' . $pair['before'] );
					WP_CLI::log( '    after:  ' . $pair['after'] );
				}
				foreach ( $preview['nav_hits'] as $hit ) {
					WP_CLI::log( sprintf(
						'Menu:      #%d "%s" (%s) label%s: %s',
						$hit['post_id'],
						$hit['title'],
						$hit['kind'],
						count( $hit['labels'] ) === 1 ? '' : 's',
						implode( ', ', $hit['labels'] )
					) );
				}
				$total = array_sum( $preview['content_hits'] ) + (int) $preview['post_content_hits'];
				WP_CLI::log( 'Internal links: ' . number_format_i18n( $total ) . ' rows (optional to rewrite)' );

				foreach ( $preview['untouched'] as $note ) {
					WP_CLI::log( 'Left alone: ' . $note );
				}

				WP_CLI::log( 'Snapshot stored: ' . ( $preview['has_snapshot'] ? 'yes — rollback available' : 'no' ) );
				return;
			}

			if ( 'run' === $sub ) {
				$result = ace_term_rename_run( $job_id, ! empty( $assoc_args['rewrite-content'] ) );
			} elseif ( 'rollback' === $sub ) {
				$result = ace_term_rename_rollback( $job_id );
			} else {
				WP_CLI::error( 'Unknown subcommand. Use: jobs | status | preview | run | rollback' );
				return;
			}

			if ( empty( $result['ok'] ) ) {
				WP_CLI::error( $result['message'] ?? 'Failed.' );
			}
			foreach ( $result['log'] as $line ) {
				WP_CLI::log( $line );
			}
			WP_CLI::success( ucfirst( $sub ) . ' complete.' );
		},
		[
			'shortdesc' => 'Reversibly rename a taxonomy term (jobs|status|preview|run|rollback).',
			'synopsis'  => [
				[ 'type' => 'positional', 'name' => 'subcommand', 'optional' => true ],
				[ 'type' => 'positional', 'name' => 'job', 'optional' => true ],
				[ 'type' => 'flag', 'name' => 'rewrite-content', 'optional' => true, 'description' => 'Also rewrite /old-slug/ links inside article bodies.' ],
			],
		]
	);
}
