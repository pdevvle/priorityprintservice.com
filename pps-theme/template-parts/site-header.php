<?php
/**
 * Site header template-part.
 *
 * Pulls nav items from the WordPress "primary" menu location when available,
 * otherwise falls back to a hardcoded default drawn from the known PPS product
 * catalog so the header is never empty.
 *
 * The SEO-relevant business-info values (phone, address, CTA url) come from
 * the pps-seo plugin settings once that plugin is active. For now they read
 * from theme_mod / filtered defaults so the header renders on a fresh install.
 *
 * @package pps-theme
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$pps_phone_raw  = apply_filters( 'pps_business_phone_raw',  '+16025551234' );
$pps_phone_disp = apply_filters( 'pps_business_phone_disp', '(602) 555-1234' );
$pps_cta_url    = apply_filters( 'pps_primary_cta_url', home_url( '/quote/' ) );
$pps_cta_label  = apply_filters( 'pps_primary_cta_label', 'Start an order' );
$pps_email      = apply_filters( 'pps_business_email', get_option( 'admin_email' ) );
$pps_hours      = apply_filters( 'pps_business_hours', 'Mon–Fri 8a–5p' );
$pps_address_city = apply_filters( 'pps_business_city', 'Phoenix, AZ' );

/**
 * Default services list — used as a fallback when the "primary" menu slot
 * is empty. Each item: label, href, meta (supporting detail), number.
 */
$pps_default_services = array(
	array(
		'label' => 'Saddle stitch booklets',
		'href'  => home_url( '/product/saddle-stitch-booklet/' ),
		'meta'  => '8–200pg · stapled',
	),
	array(
		'label' => 'Perfect bound booklets',
		'href'  => home_url( '/product/perfect-bound/' ),
		'meta'  => '40–500pg · glued spine',
	),
	array(
		'label' => 'Brochures & flyers',
		'href'  => home_url( '/product/brochure/' ),
		'meta'  => '9 fold types · flats',
	),
	array(
		'label' => 'Coupon books',
		'href'  => home_url( '/product/coupon-book/' ),
		'meta'  => 'perforated · numbered',
	),
);
$pps_services = apply_filters( 'pps_services_menu', $pps_default_services );

$pps_default_about = array(
	array( 'label' => 'About',         'href' => home_url( '/about/' ) ),
	array( 'label' => 'Pricing guide', 'href' => home_url( '/pricing/' ) ),
	array( 'label' => 'FAQ',           'href' => home_url( '/faq/' ) ),
	array( 'label' => 'Contact',       'href' => home_url( '/contact/' ) ),
);
$pps_about = apply_filters( 'pps_about_menu', $pps_default_about );
?>
<a class="pps-skip" href="#pps-main">Skip to content</a>

<header class="pps-header" role="banner">
	<div class="pps-header__inner">
		<a class="pps-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Priority Print Service — home">
			<span class="pps-brand__mark" aria-hidden="true">P</span>
			<span class="pps-brand__text">Priority Print <em>Service</em></span>
		</a>

		<div class="pps-header__mid" aria-hidden="true"></div>

		<div class="pps-header__actions">
			<a class="pps-header__phone" href="tel:<?php echo esc_attr( $pps_phone_raw ); ?>">
				<span class="label">Call&nbsp;</span><?php echo esc_html( $pps_phone_disp ); ?>
			</a>
			<button
				class="pps-menu-toggle"
				type="button"
				aria-expanded="false"
				aria-controls="pps-overlay"
				aria-label="Open menu"
				data-pps-menu-toggle
			>
				<span class="pps-menu-toggle__bars" aria-hidden="true"></span>
			</button>
		</div>
	</div>
</header>

<div
	id="pps-overlay"
	class="pps-overlay"
	data-pps-overlay
	data-open="false"
	role="dialog"
	aria-modal="true"
	aria-labelledby="pps-overlay-title"
>
	<div class="pps-overlay__inner">
		<button
			class="pps-overlay__close"
			type="button"
			aria-label="Close menu"
			data-pps-menu-close
		>
			<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
				<path d="M6 6l12 12M18 6L6 18" />
			</svg>
		</button>

		<h2 id="pps-overlay-title" class="screen-reader-text" style="position:absolute;left:-9999px;">Menu</h2>

		<div class="pps-overlay__grid">
			<nav aria-label="Services">
				<span class="pps-nav__eyebrow">Print services</span>
				<ul class="pps-nav__list">
					<?php foreach ( $pps_services as $i => $svc ) : ?>
						<li class="pps-nav__item">
							<a class="pps-nav__link" href="<?php echo esc_url( $svc['href'] ); ?>">
								<span class="pps-nav__num" aria-hidden="true"><?php echo esc_html( str_pad( (string)( $i + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
								<span class="pps-nav__label"><?php echo esc_html( $svc['label'] ); ?></span>
								<?php if ( ! empty( $svc['meta'] ) ) : ?>
									<span class="pps-nav__meta"><?php echo esc_html( $svc['meta'] ); ?></span>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>

			<nav aria-label="About and support" class="pps-nav--secondary">
				<span class="pps-nav__eyebrow">The shop</span>
				<ul class="pps-nav__list">
					<?php foreach ( $pps_about as $i => $item ) : ?>
						<li class="pps-nav__item">
							<a class="pps-nav__link" href="<?php echo esc_url( $item['href'] ); ?>">
								<span class="pps-nav__label"><?php echo esc_html( $item['label'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>
		</div>

		<div class="pps-overlay__foot">
			<div class="pps-contact__row">
				<div class="pps-contact__group">
					<span class="pps-contact__label">Call</span>
					<a class="pps-contact__value" href="tel:<?php echo esc_attr( $pps_phone_raw ); ?>"><?php echo esc_html( $pps_phone_disp ); ?></a>
				</div>
				<div class="pps-contact__group">
					<span class="pps-contact__label">Email</span>
					<a class="pps-contact__value" href="mailto:<?php echo esc_attr( $pps_email ); ?>"><?php echo esc_html( $pps_email ); ?></a>
				</div>
				<div class="pps-contact__group">
					<span class="pps-contact__label">Hours</span>
					<span class="pps-contact__value"><?php echo esc_html( $pps_hours ); ?></span>
				</div>
			</div>
			<a class="pps-cta" href="<?php echo esc_url( $pps_cta_url ); ?>">
				<?php echo esc_html( $pps_cta_label ); ?>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
					<path d="M5 12h14M13 5l7 7-7 7" />
				</svg>
			</a>
		</div>
	</div>
</div>
