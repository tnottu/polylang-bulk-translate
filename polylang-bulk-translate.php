<?php

/*
	Plugin Name: Polylang Bulk Translate
	Plugin URI:
	Version: 0.1.0
	Author: @tnottu, Mainonnan Työmaa MPM Oy
	Author URI: https://github.com/tyomaaoy
	Description: Translate multiple posts with bulk actions
	License: GPLv2 or later
	License URI: http://www.gnu.org/licenses/gpl-2.0.html
	Text Domain: polylang-bulk-translate
*/

class PolylangBulkTranslate {

	/**
	 * Constructor
	 */
	public function __construct() {

		// Check that Polylang is active
		global $polylang;

		$should_activate = isset( $polylang ) && isset( $_GET['lang'] ) && $_GET['lang'] !== 'all';

		if ( $should_activate ) {

			$translatable_post_types = array_filter( get_post_types(), function ( $post_type ) {
				return pll_is_translated_post_type($post_type);
			} );

			foreach ( $translatable_post_types as $post_type ) {
				add_filter( "bulk_actions-edit-{$post_type}",  array( $this, 'register_bulk_post_actions' ) );
				add_filter( "handle_bulk_actions-edit-{$post_type}",  array( $this, 'handle_bulk_post_action' ), 10, 3 );
			}

			// $translatable_taxonomies = array_filter(get_taxonomies(), function ($taxonomy) {
			// 	return pll_is_translated_taxonomy($taxonomy);
			// });

			// foreach ($translatable_taxonomies as $taxonomy) {
			// 	add_filter( "bulk_actions-edit-{$taxonomy}",  array( $this, 'register_bulk_tax_actions' ) );
			// 	add_filter( "handle_bulk_actions-edit-{$taxonomy}",  array( $this, 'handle_bulk_tax_action' ), 10, 3 );
			// }

			add_action( 'admin_notices', array( $this, 'show_admin_notice' ) );

		}

	}



	/**
	 * Register "Translate to: $lang" -actions
	 *
	 * @param array $bulk_actions
	 *
	 */

	function register_bulk_post_actions( $bulk_actions ) {

		// Register action for each language, except current
		foreach ( pll_languages_list() as $language ) {

			if ( $language === pll_current_language() ) {
				continue;
			}

			$bulk_actions["translate_to_{$language}"] = __( 'Translate to', 'pll_bulk_translate') . ': ' . strtoupper( $language );

		}

		return $bulk_actions;

	}


	/**
	 * Handle bulk post action
	 *
	 * @param string $redirect_to Redirect url
	 * @param string $action Action name
	 * @param array $post_ids Array of post ids
	 *
	 */

 	function handle_bulk_post_action( $redirect_to, $action, $post_ids ) {

		$action_parts = explode('translate_to_', $action);
		$language = $action_parts[1];
		$should_translate = in_array($language, pll_languages_list()) && $language !== pll_current_language();

 		if ( ! $should_translate ) {
			return $redirect_to;
		}

		$posts = get_posts( ['post__in' => $post_ids, 'post_type' => $post_type] );
		$posts_sorted = $this->sort_posts_by_hierarchy( $posts );
		$ids_sorted = array_map( function ( $post ) { return $post->ID; }, $posts_sorted );

		// Check that all parent posts are includes in the array,
		// or that they are already translated.
		foreach ( $ids_sorted as $post_id ) {
			$parent_id = wp_get_post_parent_id( $post_id );

			if ( $parent_id ) {
				$parent_is_included = in_array( $parent_id, $ids );
				$parent_is_translated = pll_get_post( $parent_id, $language );

				if ( !$parent_is_included && !$parent_is_translated ) {
					// TODO: Error!!! Parent page must exist or included in the array.
				}
			}
		}

		foreach ( $post_ids as $post_id ) {
			if ( $type === 'post' ) {
				$this->translate_post( $post_id, $language );
			} else {
				// TODO: Add support for taxonomies
			}
		}

		$redirect_to = add_query_arg( 'bulk_translated_posts', count( $post_ids ), $redirect_to );

 		return $redirect_to;
 	}


	// function register_bulk_tax_actions( $bulk_actions ) {
	//
	// }

	// function handle_bulk_tax_action( $redirect_to, $action, $tax_ids ) {
	//
	// }


	/**
	 * Show admin notice
	 */

	function show_admin_notice() {

		if ( ! empty( $_REQUEST['bulk_translated_posts'] ) ) {

			$translated_count = intval( $_REQUEST['bulk_translated_posts'] );

			printf( '<div id="message" class="updated fade">' .
				_n(
					'Translated %s post to: ' . $language,
					'Translated %s posts to: ' . $language,
					$translated_count,
					'pll_bulk_translate'
				) . '</div>', $translated_count );

		}

	}


	/**
	 * Sort posts by hierarchy level
	 *
	 * @param array $posts Array of post-objects
	 *
	 */

	function sort_posts_by_hierarchy( $posts ) {

		$posts_by_hierarchy = [];

		foreach ( $posts as $post ) {
			$level = count( get_post_ancestors( $post->ID ) );
			$posts_by_hierarchy[$level] = isset( $posts_by_hierarchy[$level] ) ? $posts_by_hierarchy[$level] : [];
			$posts_by_hierarchy[$level][] = $post;
		}

		$posts_by_hierarchy_flat = call_user_func_array('array_merge', $posts_by_hierarchy);

		return $posts_by_hierarchy_flat;

	}

	/**
	 * Translate a post
	 *
	 * Creates a copy of the post, with all possible content from the original and sets
	 * the new copy as a translation.
	 *
	 * @param int $post_id ID of the original post
	 * @param string $new_lang New language slug
	 *
	 */
	function translate_post( $post_id, $new_lang ) {

		$from_post = get_post( $post_id );
		$has_translation = pll_get_post( $post_id, $new_lang );

		if ( $has_translation ) {
			return;
		}

		$new_post = clone $from_post; // Copy the post

		/*
		 * Prepare post
		 */
		$new_post->ID = null;
		$new_post->post_status = 'draft';
		$new_post->post_title = "{$new_post->post_title} ({$new_lang} translation)";
		$new_post->post_parent = pll_get_post( $from_post->post_parent, $new_lang );

		$new_post_id = wp_insert_post( $new_post ); // Creates a new post thanks to ID being null

		/*
		 * Set languate & translation relation
		 */
		pll_set_post_language( $new_post_id, $new_lang );

		pll_save_post_translations( array(
			pll_get_post_language( $from_post->ID ) => $from_post->ID,
			$new_lang => $new_post_id
		) );

		/*
		 * Copy relevant extra data
		 */
		PLL()->sync->copy_taxonomies( $from_post->ID, $new_post_id, $new_lang );
		PLL()->sync->copy_post_metas( $from_post->ID, $new_post_id, $new_lang );

		wp_update_post( $new_post_id );

	}
}

add_action( 'wp_loaded', create_function( '', 'global $polylang_bulk_translate; $polylang_bulk_translate = new PolylangBulkTranslate();' ) );
