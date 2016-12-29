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

		if (isset($polylang)) {
			add_action( 'current_screen', array( $this, 'register_bulk_actions' ) );
		}

	}

	/**
	 * Register "Translate to: $lang" -actions
	 */
	function register_bulk_actions() {

		$is_any_language_active = !empty( pll_current_language() );
		$currentScreen      = get_current_screen();
		$is_post_list       = $currentScreen->base === 'edit';
		$is_taxonomy_list   = $currentScreen->base === 'edit-tags';
		$post_type          = $currentScreen->post_type;
		$taxonomy           = $currentScreen->taxonomy;
		$type               = $is_post_list ? 'post' : 'term';
		// TODO: Add support for taxonomies
		$is_translatable    = $is_post_list && pll_is_translated_post_type( $post_type );

		$should_init =  $is_any_language_active && $is_translatable;

		if (!$should_init) {
			return;
		}

		$bulk_actions = new Seravo_Custom_Bulk_Action( array( 'post_type' => $post_type ) );

		// Register action for each language, except current
		foreach ( pll_languages_list() as $language ) {

			if ($language === pll_current_language()) {
				continue;
			}

			$bulk_actions->register_bulk_action( array(
				'menu_text' => 'Translate to: ' . strtoupper( $language) ,
				'admin_notice' => ( $type === 'post' ) ? '%s posts translated.' : '%s terms translated.',
				'callback' => function( $ids ) use ( $language, $post_type, $type ) {

					$posts = get_posts(['post__in' => $ids, 'post_type' => $post_type ]);
					$posts_sorted = $this->sort_by_hierarchy($posts);
					$ids_sorted = array_map(function ($post) { return $post->ID; }, $posts_sorted);

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

					foreach ( $ids as $id ) {
						if ( $type === 'post' ) {
							$this->translate_post( $id, $language );
						} else {
							// TODO: Add support for taxonomies
						}
					}

					return true;

				}
			) );

		}

		$bulk_actions->init();

	}

	/**
	 * Sort posts by hierarchy level
	 *
	 * @param array $posts Array of post-objects
	 *
	 */

	function sort_by_hierarchy($posts) {

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

		if ($has_translation) return;

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

add_action('plugins_loaded', create_function('', 'global $polylang_bulk_translate; $polylang_bulk_translate = new PolylangBulkTranslate();'));
