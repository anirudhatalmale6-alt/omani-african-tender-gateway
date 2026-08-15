<?php
/**
 * One-shot demo setup.
 *
 * Activating the theme builds everything the Ministry walkthrough needs:
 * the static front page, the three content pages, pretty permalinks and the
 * primary menu. It is deliberately idempotent - nothing is recreated or
 * overwritten if it already exists, so re-activating never clobbers edits.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TGT_Setup {

	const FLAG = 'tgt_setup_done';

	public static function run() {
		$home_id = self::ensure_page( 'home', 'Home', '' );

		if ( $home_id ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $home_id );
		}

		$content_ids = array();
		foreach ( tgt_content_pages() as $slug => $title ) {
			$content_ids[ $slug ] = self::ensure_page( $slug, $title, self::content( $slug ) );
		}

		self::ensure_permalinks();
		self::ensure_menu( $home_id, $content_ids );

		// Only call the setup finished once the plugin's own pages exist. If the
		// theme was activated first they don't yet, and the retry below has to
		// run again to add the Tenders entry to the menu.
		if ( get_option( 'tg_pages' ) ) {
			update_option( self::FLAG, TGT_VERSION );
		}
	}

	/**
	 * @return int Page ID, existing or newly created.
	 */
	private static function ensure_page( $slug, $title, $content ) {
		$existing = get_page_by_path( $slug );
		if ( $existing ) {
			return (int) $existing->ID;
		}

		$id = wp_insert_post( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'post_title'     => $title,
			'post_name'      => $slug,
			'post_content'   => $content,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		) );

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	private static function ensure_permalinks() {
		if ( get_option( 'permalink_structure' ) ) {
			return;
		}

		global $wp_rewrite;

		update_option( 'permalink_structure', '/%postname%/' );

		if ( $wp_rewrite ) {
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
			$wp_rewrite->flush_rules();
		}
	}

	/**
	 * Additive and idempotent: adds any expected entry the menu is missing and
	 * leaves everything else, including the client's own additions, alone.
	 */
	private static function ensure_menu( $home_id, $content_ids ) {
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		$menu_id   = 0;

		if ( ! empty( $locations['primary'] ) && wp_get_nav_menu_object( $locations['primary'] ) ) {
			$menu_id = (int) $locations['primary'];
		} else {
			$menu    = wp_get_nav_menu_object( 'Primary' );
			$menu_id = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu( 'Primary' );
		}

		if ( ! $menu_id || is_wp_error( $menu_id ) ) {
			return;
		}

		$tg_pages = get_option( 'tg_pages', array() );

		$wanted = array(
			array( 'Home', $home_id ),
			array( 'Tenders', isset( $tg_pages['tenders'] ) ? $tg_pages['tenders'] : 0 ),
			array( 'How it works', isset( $content_ids['how-it-works'] ) ? $content_ids['how-it-works'] : 0 ),
			array( 'About', isset( $content_ids['about'] ) ? $content_ids['about'] : 0 ),
			array( 'Contact', isset( $content_ids['contact'] ) ? $content_ids['contact'] : 0 ),
		);

		$present = array();
		foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
			$present[] = (int) $item->object_id;
		}

		$order = array();

		foreach ( $wanted as $index => $entry ) {
			list( $label, $object_id ) = $entry;
			$object_id = (int) $object_id;

			if ( ! $object_id ) {
				continue;
			}

			$order[ $object_id ] = $index + 1;

			if ( in_array( $object_id, $present, true ) ) {
				continue;
			}

			wp_update_nav_menu_item( $menu_id, 0, array(
				'menu-item-title'     => $label,
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $object_id,
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
				'menu-item-position'  => $index + 1,
			) );
		}

		// An item added on the retry pass lands at the end, so restate the
		// intended order across the whole set rather than trusting append order.
		foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
			$object_id = (int) $item->object_id;

			if ( isset( $order[ $object_id ] ) && (int) $item->menu_order !== $order[ $object_id ] ) {
				wp_update_post( array(
					'ID'         => $item->ID,
					'menu_order' => $order[ $object_id ],
				) );
			}
		}

		$locations['primary'] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
	}

	/**
	 * Body copy for the static content pages.
	 */
	private static function content( $slug ) {
		$file = get_template_directory() . '/inc/content/' . $slug . '.html';

		return file_exists( $file ) ? file_get_contents( $file ) : '';
	}
}

add_action( 'after_switch_theme', array( 'TGT_Setup', 'run' ) );

/**
 * If the theme is activated before the plugin, the tender pages do not exist
 * yet and the menu would be missing its "Tenders" entry. Re-run once the
 * plugin has had its own activation hook.
 */
add_action( 'admin_init', function () {
	if ( get_option( TGT_Setup::FLAG ) === TGT_VERSION ) {
		return;
	}

	if ( get_option( 'tg_pages' ) ) {
		TGT_Setup::run();
	}
} );
