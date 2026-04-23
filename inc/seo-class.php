<?php
/**
 * PP_SEO — lightweight replacement for Yoast SEO on Priority Print Service.
 *
 * Scope (what this class owns):
 *   - <title> tag shape (WP's title-tag support + filtered separator/suffix)
 *   - <meta name="description">
 *   - Open Graph tags (og:title, og:description, og:image, og:type, og:url, og:site_name)
 *   - Canonical <link>
 *   - Site-wide JSON-LD: Organization + LocalBusiness on the homepage only
 *
 * Scope (what this class deliberately does NOT own):
 *   - Product / WebApp / FAQ JSON-LD on calculator pages. Those are emitted
 *     by the pps-calculators plugin (pps-calculators.php) because they depend
 *     on calculator configuration / WCPA pricing state that the theme has no
 *     visibility into. PP_SEO::is_calculator_page() detects those pages and
 *     suppresses the theme's overlapping schemas.
 *   - Sitemaps. WordPress core ships /wp-sitemap.xml out of the box; no need
 *     to replicate Yoast's sitemap generator.
 *
 * Meta description source order (see pp_meta_description() in functions.php):
 *   1. Custom field `_meta_description` on the post
 *   2. Post excerpt
 *   3. Site tagline (get_bloginfo('description'))
 *
 * No admin UI is included in v1; a future option panel can be added without
 * breaking this contract by simply filtering the functions below.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PP_SEO {

	const SITE_NAME  = 'Priority Print Service';
	const LOCATION   = 'Peoria, AZ';
	const PHONE      = '+1-623-977-8888';
	const FALLBACK_IMG = 'https://priorityprintservice.com/wp-content/uploads/2023/06/2021-Logo-full-16.png';

	/**
	 * Entry point. Called from functions.php via:
	 *   add_action( 'after_setup_theme', array( 'PP_SEO', 'init' ) );
	 */
	public static function init() {
		add_filter( 'document_title_separator', array( __CLASS__, 'title_separator' ) );
		add_filter( 'document_title_parts',     array( __CLASS__, 'title_parts' ) );

		// wp_head priority 2 — after charset/viewport, before most plugin output.
		add_action( 'wp_head', array( __CLASS__, 'emit_meta' ), 2 );
		add_action( 'wp_head', array( __CLASS__, 'emit_og' ),   3 );
		add_action( 'wp_head', array( __CLASS__, 'emit_canonical' ), 4 );
		add_action( 'wp_head', array( __CLASS__, 'emit_site_schema' ), 30 );
	}

	/* ================================================================
	 * TITLE
	 * ================================================================ */

	public static function title_separator( $sep ) {
		return '|';
	}

	public static function title_parts( $parts ) {
		// Homepage: "Priority Print Service | Commercial Printing Peoria AZ"
		if ( is_front_page() ) {
			$parts['title']   = self::SITE_NAME;
			$parts['tagline'] = __( 'Commercial Printing Peoria AZ', 'priority-print' );
			unset( $parts['site'] );
		} else {
			// Inner pages: "{Post Title} | Priority Print Service"
			unset( $parts['tagline'] );
			$parts['site'] = self::SITE_NAME;
		}
		return $parts;
	}

	/* ================================================================
	 * DETECTORS
	 * ================================================================ */

	/**
	 * True if the current page is a WooCommerce product whose template is
	 * a PPS calculator (saddle-stitch, perfect-bound, brochure, coupon book).
	 *
	 * Detection heuristic: the pps-calculators plugin sets a product meta key
	 * `_pps_calculator` with the calculator slug. If that plugin is not active,
	 * this returns false — PP_SEO then emits its own product schema. If/when
	 * the detection key changes upstream, update it here only.
	 */
	public static function is_calculator_page() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}
		$slug = get_post_meta( get_queried_object_id(), '_pps_calculator', true );
		return ! empty( $slug );
	}

	/* ================================================================
	 * META DESCRIPTION
	 * ================================================================ */

	public static function emit_meta() {
		$desc = function_exists( 'pp_meta_description' )
			? pp_meta_description()
			: wp_strip_all_tags( get_bloginfo( 'description' ) );

		if ( $desc ) {
			printf( "<meta name=\"description\" content=\"%s\">\n",
				esc_attr( wp_trim_words( $desc, 30, '' ) )
			);
		}
	}

	/* ================================================================
	 * OPEN GRAPH + TWITTER
	 * ================================================================ */

	public static function emit_og() {
		$title       = wp_get_document_title();
		$desc        = function_exists( 'pp_meta_description' )
			? pp_meta_description()
			: wp_strip_all_tags( get_bloginfo( 'description' ) );
		$url         = self::current_url();
		$img         = self::current_image();
		$type        = is_singular( 'post' ) ? 'article' : 'website';

		$tags = array(
			'og:site_name'   => self::SITE_NAME,
			'og:type'        => $type,
			'og:title'       => $title,
			'og:description' => wp_trim_words( $desc, 30, '' ),
			'og:url'         => $url,
			'og:image'       => $img,
		);

		foreach ( $tags as $prop => $val ) {
			if ( $val === '' || $val === null ) continue;
			printf( "<meta property=\"%s\" content=\"%s\">\n",
				esc_attr( $prop ),
				esc_attr( $val )
			);
		}

		// Twitter / X card shares the same info, different attribute names.
		printf( "<meta name=\"twitter:card\" content=\"summary_large_image\">\n" );
		printf( "<meta name=\"twitter:title\" content=\"%s\">\n", esc_attr( $title ) );
		printf( "<meta name=\"twitter:description\" content=\"%s\">\n", esc_attr( wp_trim_words( $desc, 30, '' ) ) );
		if ( $img ) {
			printf( "<meta name=\"twitter:image\" content=\"%s\">\n", esc_attr( $img ) );
		}
	}

	/* ================================================================
	 * CANONICAL
	 * ================================================================ */

	public static function emit_canonical() {
		$url = self::current_url();
		if ( $url ) {
			printf( "<link rel=\"canonical\" href=\"%s\">\n", esc_url( $url ) );
		}
	}

	/* ================================================================
	 * JSON-LD — Organization + LocalBusiness on homepage only
	 * On calculator pages the plugin emits richer Product/WebApp schemas,
	 * so we skip to avoid duplication.
	 * ================================================================ */

	public static function emit_site_schema() {
		if ( self::is_calculator_page() ) {
			return; // plugin owns this page's schema.
		}
		if ( ! is_front_page() ) {
			return; // only emit on the homepage to keep things tight.
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array(
					'@type'    => 'Organization',
					'@id'      => home_url( '/#organization' ),
					'name'     => self::SITE_NAME,
					'url'      => home_url( '/' ),
					'logo'     => self::FALLBACK_IMG,
					'telephone'=> self::PHONE,
					'sameAs'   => array(),
				),
				array(
					'@type'    => 'LocalBusiness',
					'@id'      => home_url( '/#localbusiness' ),
					'name'     => self::SITE_NAME,
					'url'      => home_url( '/' ),
					'image'    => self::FALLBACK_IMG,
					'telephone'=> self::PHONE,
					'priceRange' => '$$',
					'address'  => array(
						'@type'          => 'PostalAddress',
						'addressLocality'=> 'Peoria',
						'addressRegion'  => 'AZ',
						'addressCountry' => 'US',
					),
				),
				array(
					'@type'    => 'WebSite',
					'@id'      => home_url( '/#website' ),
					'url'      => home_url( '/' ),
					'name'     => self::SITE_NAME,
					'publisher'=> array( '@id' => home_url( '/#organization' ) ),
				),
			),
		);

		echo "<script type=\"application/ld+json\">" . wp_json_encode(
			$schema,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "</script>\n";
	}

	/* ================================================================
	 * HELPERS
	 * ================================================================ */

	private static function current_url() {
		if ( is_singular() ) return get_permalink();
		if ( is_category() || is_tag() || is_tax() ) return get_term_link( get_queried_object() );
		if ( is_post_type_archive() ) return get_post_type_archive_link( get_query_var( 'post_type' ) );
		if ( is_home() && ! is_front_page() ) {
			$blog_page = get_option( 'page_for_posts' );
			return $blog_page ? get_permalink( $blog_page ) : home_url( '/' );
		}
		return home_url( add_query_arg( null, null ) );
	}

	private static function current_image() {
		if ( is_singular() && has_post_thumbnail() ) {
			$src = wp_get_attachment_image_src( get_post_thumbnail_id(), 'large' );
			if ( $src && ! empty( $src[0] ) ) return $src[0];
		}
		return self::FALLBACK_IMG;
	}
}
