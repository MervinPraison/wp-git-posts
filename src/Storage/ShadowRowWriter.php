<?php
namespace PraisonPress\Storage;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ShadowRowWriter — Maintains a minimal `wp_posts` row + `wp_term_relationships`
 * entries for every file-based post.
 *
 * WHY THIS EXISTS
 * ---------------
 * The headless plugin serves content directly from Markdown files via
 * `posts_pre_query` and `get_post_metadata`, so the *content delivery* path
 * works without any database row. However, several WordPress subsystems and
 * popular plugins absolutely require a real row in `wp_posts`:
 *
 *   1. YARPP (Yet Another Related Posts Plugin)
 *      - Builds keyword index from `wp_posts.post_content`
 *      - Joins `wp_yarpp_related_cache.reference_ID` (BIGINT UNSIGNED) on
 *        `wp_posts.ID` — negative synthetic IDs would coerce to 0 and
 *        corrupt cache row 0.
 *      - Cache invalidation listens to `save_post` / `deleted_post`.
 *
 *   2. Theme taxonomy reads
 *      - `wp_get_post_terms($id, ['scale','pattern','style'])` in the chord
 *        single template requires `wp_term_relationships` rows.
 *      - `get_the_term_list()` for artist/album buttons.
 *
 *   3. Comments, sitemaps (Yoast), search (ElasticPress), WPGraphQL, etc.
 *      All key off `wp_posts.ID`.
 *
 * Rather than re-implement adapters for each subsystem, we keep a *minimal*
 * shadow row (status=publish, slug + post_type + post_date set, content
 * truncated). The headless plugin's `posts_pre_query` filter still overlays
 * the file-based content at render time, so the YAML remains the source of
 * truth for visible content.
 *
 * SAFETY
 * ------
 *   - Idempotent: `upsert()` matches by `post_name` + `post_type`.
 *   - Marked with `_praison_shadow=1` postmeta so rows are removable in bulk.
 *   - Calls `wp_insert_post` / `wp_update_post`, which natively fire
 *     `save_post` and `clean_post_cache`. That alone invalidates YARPP's
 *     cache (YARPP listens to those actions), satisfying G-C without us
 *     needing direct YARPP coupling.
 *   - All operations gated on WP being loaded.
 *
 * USAGE
 * -----
 *   ShadowRowWriter::upsert('lyrics', $indexEntry);
 *   ShadowRowWriter::trash('lyrics', 'old-slug');
 *
 * Index entry shape (from IndexManager): see IndexManager::$reserved + extras.
 *
 * EXTENSION
 * ---------
 *   apply_filters('praison_shadow_row_payload', $payload, $type, $entry)
 *     — modify wp_insert_post args before the write.
 *   apply_filters('praison_shadow_taxonomies', $taxMap, $type, $entry)
 *     — modify the taxonomy → terms mapping.
 *   apply_filters('praison_shadow_enabled', bool $enabled, string $type)
 *     — short-circuit (e.g. dry-run, post-type allowlist).
 */
class ShadowRowWriter {

	/** Postmeta sentinel marking a row as a plugin-managed shadow. */
	const SHADOW_FLAG_KEY = '_praison_shadow';

	/**
	 * Whether shadow-row writing is enabled site-wide.
	 *
	 * Disabled by default; opt-in via:
	 *   - wp_options: praisonpress_options['shadow_rows_enabled'] = 1
	 *   - or filter:  add_filter('praison_shadow_enabled', '__return_true');
	 */
	public static function isEnabled( string $type = '' ): bool {
		$opts = get_option( 'praisonpress_options', [] );
		$enabled = ! empty( $opts['shadow_rows_enabled'] );
		return (bool) apply_filters( 'praison_shadow_enabled', $enabled, $type );
	}

	/**
	 * Upsert a shadow row for the given index entry.
	 *
	 * @param string $type  Post type (directory name in /content/).
	 * @param array  $entry IndexManager entry (slug, title, status, taxonomies, …).
	 * @return int 0 on skip/failure, positive WP post ID on success.
	 */
	public static function upsert( string $type, array $entry ): int {
		if ( ! function_exists( 'wp_insert_post' ) ) {
			return 0;
		}
		if ( ! self::isEnabled( $type ) ) {
			return 0;
		}
		if ( empty( $entry['slug'] ) || empty( $entry['title'] ) ) {
			return 0;
		}

		$post_type = ( $type === 'posts' ) ? 'praison_post' : $type;
		$slug      = sanitize_title( $entry['slug'] );

		$existing_id = self::findExistingId( $post_type, $slug );

		// Truncate content to ~5KB for keyword indexing only — the real
		// content is overlaid at runtime by Bootstrap::injectFilePosts.
		$content_source = self::resolveContent( $entry );
		if ( strlen( $content_source ) > 5120 ) {
			$content_source = substr( $content_source, 0, 5120 );
		}

		$status = $entry['status'] ?? 'publish';
		// Whitelist statuses to avoid accidental 'auto-draft' etc.
		if ( ! in_array( $status, [ 'publish', 'draft', 'pending', 'private', 'future' ], true ) ) {
			$status = 'publish';
		}

		$payload = [
			'post_title'        => (string) $entry['title'],
			'post_name'         => $slug,
			'post_type'         => $post_type,
			'post_status'       => $status,
			'post_content'      => $content_source,
			'post_excerpt'      => (string) ( $entry['excerpt'] ?? '' ),
			'post_author'       => self::resolveAuthorId( $entry['author'] ?? 'admin' ),
			'post_date'         => self::normalizeDate( $entry['date'] ?? '' ),
			'post_date_gmt'     => self::normalizeDate( $entry['date'] ?? '', true ),
			'post_modified'     => self::normalizeDate( $entry['modified'] ?? ( $entry['date'] ?? '' ) ),
			'post_modified_gmt' => self::normalizeDate( $entry['modified'] ?? ( $entry['date'] ?? '' ), true ),
			'comment_status'    => 'open',
			'ping_status'       => 'closed',
			'meta_input'        => [
				self::SHADOW_FLAG_KEY => 1,
			],
		];

		if ( $existing_id > 0 ) {
			$payload['ID'] = $existing_id;
		}

		$payload = apply_filters( 'praison_shadow_row_payload', $payload, $type, $entry );

		$post_id = $existing_id > 0 ? wp_update_post( $payload, true ) : wp_insert_post( $payload, true );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		// Sync taxonomies (G-B): drives YARPP taxonomy weights and theme buttons.
		$taxMap = self::collectTaxonomies( $entry );
		$taxMap = apply_filters( 'praison_shadow_taxonomies', $taxMap, $type, $entry );
		foreach ( $taxMap as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			// Accept array of strings (names) — wp_set_object_terms creates missing terms.
			$terms = array_values( array_filter( array_map( 'strval', (array) $terms ) ) );
			wp_set_object_terms( (int) $post_id, $terms, $taxonomy, false );
		}

		return (int) $post_id;
	}

	/**
	 * Move a shadow row to trash by slug. Use when a Markdown file is removed.
	 *
	 * @return bool True on success or no-op, false on failure.
	 */
	public static function trash( string $type, string $slug ): bool {
		if ( ! function_exists( 'wp_trash_post' ) ) {
			return false;
		}
		$post_type = ( $type === 'posts' ) ? 'praison_post' : $type;
		$id = self::findExistingId( $post_type, sanitize_title( $slug ) );
		if ( $id <= 0 ) {
			return true; // nothing to do
		}
		// Only trash rows we own — avoid clobbering pre-existing imports.
		if ( ! get_post_meta( $id, self::SHADOW_FLAG_KEY, true ) ) {
			return true;
		}
		return (bool) wp_trash_post( $id );
	}

	/**
	 * Find an existing post (any status except auto-draft/inherit/trash) by slug+type.
	 */
	public static function findExistingId( string $post_type, string $slug ): int {
		global $wpdb;
		if ( empty( $slug ) ) {
			return 0;
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} "
			. "WHERE post_type = %s AND post_name = %s "
			. "AND post_status NOT IN ('auto-draft','inherit','trash') "
			. 'LIMIT 1',
			$post_type,
			$slug
		) );
		return $id > 0 ? $id : 0;
	}

	/* ------------------------------------------------------------------
	 * Internal helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Pick the best content for keyword indexing.
	 * Priority: content body → custom_fields.ta_content → custom_fields.en_content.
	 */
	private static function resolveContent( array $entry ): string {
		// IndexManager doesn't carry the body, only metadata. Inspect custom_fields.
		$cf = $entry['custom_fields'] ?? [];
		if ( ! empty( $cf['ta_content'] ) ) {
			return wp_strip_all_tags( (string) $cf['ta_content'] );
		}
		if ( ! empty( $cf['en_content'] ) ) {
			return wp_strip_all_tags( (string) $cf['en_content'] );
		}
		if ( ! empty( $entry['excerpt'] ) ) {
			return wp_strip_all_tags( (string) $entry['excerpt'] );
		}
		return '';
	}

	private static function resolveAuthorId( $login ): int {
		if ( is_numeric( $login ) ) {
			return (int) $login;
		}
		$user = get_user_by( 'login', (string) $login );
		if ( $user ) {
			return (int) $user->ID;
		}
		// Fallback: first admin
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		return $admins ? (int) $admins[0] : 0;
	}

	private static function normalizeDate( $value, bool $gmt = false ): string {
		if ( empty( $value ) ) {
			return $gmt ? gmdate( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
		}
		$ts = strtotime( (string) $value );
		if ( ! $ts ) {
			return $gmt ? gmdate( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
		}
		return $gmt ? gmdate( 'Y-m-d H:i:s', $ts ) : date( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Collect taxonomies from an index entry. Supports two YAML shapes:
	 *   taxonomies:
	 *     artist: [Name1, Name2]
	 *     album:  [AlbumName]
	 * — and the legacy flat shape:
	 *   categories: [...]
	 *   tags: [...]
	 */
	private static function collectTaxonomies( array $entry ): array {
		$out = [];
		if ( ! empty( $entry['taxonomies'] ) && is_array( $entry['taxonomies'] ) ) {
			foreach ( $entry['taxonomies'] as $tax => $terms ) {
				if ( is_array( $terms ) && ! empty( $terms ) ) {
					$out[ (string) $tax ] = $terms;
				}
			}
		}
		if ( ! empty( $entry['categories'] ) && is_array( $entry['categories'] ) ) {
			$out['category'] = $entry['categories'];
		}
		if ( ! empty( $entry['tags'] ) && is_array( $entry['tags'] ) ) {
			$out['post_tag'] = $entry['tags'];
		}
		return $out;
	}
}
