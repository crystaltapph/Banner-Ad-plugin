<?php
defined( 'ABSPATH' ) || exit;

final class BAP_Admin {
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_bap_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_bap_delete', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_bap_settings', array( __CLASS__, 'save_settings' ) );
	}

	public static function menu(): void {
		add_menu_page( 'Banner Studio', 'Banner Studio', 'manage_options', 'banner-studio', array( __CLASS__, 'page' ), 'dashicons-format-image', 58 );
		foreach ( array( 'banner-studio' => 'Dashboard', 'bap-banners' => 'Banners', 'bap-placements' => 'Placements', 'bap-settings' => 'Settings' ) as $slug => $label ) {
			add_submenu_page( 'banner-studio', $label, $label, 'manage_options', $slug, array( __CLASS__, 'page' ) );
		}
	}

	public static function assets( string $hook ): void {
		if ( ! in_array( sanitize_key( $_GET['page'] ?? '' ), array( 'banner-studio', 'bap-banners', 'bap-placements', 'bap-settings' ), true ) ) { return; }
		wp_enqueue_media();
		wp_enqueue_style( 'bap-admin', BAP_URL . 'assets/admin.css', array(), BAP_VERSION );
		wp_enqueue_script( 'bap-admin', BAP_URL . 'assets/admin.js', array(), BAP_VERSION, true );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'You cannot manage banners.', '', array( 'response' => 403 ) ); }
		check_admin_referer( $action );
	}

	private static function redirect( string $page, string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'bap_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function ids( $value ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : explode( ',', (string) $value ) ) ) ) );
	}

	private static function bounded( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}

	public static function date( string $value ): string {
		if ( '' === $value ) { return ''; }
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );
		if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $value ) { wp_die( 'Invalid schedule date. Go back and correct it.', '', array( 'response' => 400 ) ); }
		return (string) $date->getTimestamp();
	}

	public static function sanitize( array $raw ): array {
		$d = BAP_Plugin::banner_defaults();
		foreach ( array( 'desktop', 'mobile', 'new_tab', 'sponsored', 'enabled', 'stretch' ) as $key ) { $d[ $key ] = empty( $raw[ $key ] ) ? 0 : 1; }
		foreach ( array( 'desktop_image', 'mobile_image' ) as $key ) {
			$d[ $key ] = absint( $raw[ $key ] ?? 0 );
			if ( $d[ $key ] && ! BAP_Plugin::image( $d[ $key ] ) ) { wp_die( 'Choose a JPEG, PNG, WebP, or AVIF image from the Media Library.', '', array( 'response' => 400 ) ); }
		}
		if ( ! $d['desktop_image'] && ! $d['mobile_image'] ) { wp_die( 'Choose at least one banner image. Go back to keep editing.', '', array( 'response' => 400 ) ); }
		foreach ( array( 'alt', 'label' ) as $key ) { $d[ $key ] = sanitize_text_field( $raw[ $key ] ?? '' ); }
		$url = trim( $raw['url'] ?? '' );
		if ( $url && ! preg_match( '#^https?://#i', $url ) ) { wp_die( 'Destination links must start with https:// or http://.', '', array( 'response' => 400 ) ); }
		$d['url'] = esc_url_raw( $url, array( 'https', 'http' ) );
		if ( $url && ( ! $d['url'] || ! wp_parse_url( $d['url'], PHP_URL_HOST ) ) ) { wp_die( 'Enter a valid destination URL.', '', array( 'response' => 400 ) ); }
		if ( $d['url'] && ! $d['alt'] ) { wp_die( 'Add alternative text describing the linked banner.', '', array( 'response' => 400 ) ); }
		foreach ( array( 'placement' => array( 'before', 'after', 'after_paragraph', 'after_heading', 'manual', 'sticky' ), 'align' => array( 'left', 'center', 'right' ) ) as $key => $allowed ) {
			$d[ $key ] = in_array( $raw[ $key ] ?? '', $allowed, true ) ? $raw[ $key ] : $d[ $key ];
		}
		foreach ( array( 'width' => array( 1, 2400 ), 'mobile_width' => array( 1, 767 ), 'margin' => array( 0, 120 ), 'padding' => array( 0, 60 ), 'position' => array( 1, 100 ), 'priority' => array( 1, 100 ) ) as $key => $range ) {
			$d[ $key ] = self::bounded( $raw[ $key ] ?? $d[ $key ], $range[0], $range[1] );
		}
		$d['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $raw['post_types'] ?? array() ) ), get_post_types( array( 'public' => true ) ) ) );
		$d['post_types'] = array_values( array_diff( $d['post_types'], array( 'attachment' ) ) );
		if ( ! $d['post_types'] || ( ! $d['desktop'] && ! $d['mobile'] ) ) { wp_die( 'Choose at least one content type and device.', '', array( 'response' => 400 ) ); }
		if ( 'sticky' === $d['placement'] && ! $d['mobile'] ) { wp_die( 'Sticky banners require mobile visibility.', '', array( 'response' => 400 ) ); }
		foreach ( array( 'categories', 'tags', 'exclude' ) as $key ) { $d[ $key ] = self::ids( $raw[ $key ] ?? '' ); }
		$d['start'] = self::date( sanitize_text_field( $raw['start'] ?? '' ) );
		$d['end'] = self::date( sanitize_text_field( $raw['end'] ?? '' ) );
		if ( $d['start'] && $d['end'] && (int) $d['start'] >= (int) $d['end'] ) { wp_die( 'The end must be later than the start.', '', array( 'response' => 400 ) ); }
		return $d;
	}

	public static function save(): void {
		self::guard( 'bap_save' );
		$id = absint( $_POST['banner_id'] ?? 0 );
		if ( $id && 'bap_banner' !== get_post_type( $id ) ) { wp_die( 'Banner not found.', '', array( 'response' => 404 ) ); }
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		if ( ! $title ) { wp_die( 'Give this banner a name.', '', array( 'response' => 400 ) ); }
		$data = self::sanitize( wp_unslash( (array) ( $_POST['banner'] ?? array() ) ) );
		$id = wp_insert_post( array( 'ID' => $id, 'post_type' => 'bap_banner', 'post_title' => $title, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $id ) ) { wp_die( esc_html( $id->get_error_message() ) ); }
		update_post_meta( $id, '_bap_data', $data );
		BAP_Plugin::reset_cache();
		self::redirect( 'bap-banners', 'saved' );
	}

	public static function delete(): void {
		$id = absint( $_POST['banner_id'] ?? 0 );
		self::guard( 'bap_delete_' . $id );
		if ( 'bap_banner' !== get_post_type( $id ) ) { wp_die( 'Banner not found.', '', array( 'response' => 404 ) ); }
		wp_delete_post( $id, true );
		self::redirect( 'bap-banners', 'deleted' );
	}

	public static function save_settings(): void {
		self::guard( 'bap_settings' );
		$raw = wp_unslash( (array) ( $_POST['settings'] ?? array() ) );
		$s = BAP_Plugin::defaults();
		foreach ( array( 'max' => array( 1, 10 ), 'gap' => array( 1, 20 ), 'min_words' => array( 0, 10000 ), 'sticky_offset' => array( 0, 300 ), 'dismiss_hours' => array( 0, 8760 ) ) as $key => $range ) {
			$s[ $key ] = self::bounded( $raw[ $key ] ?? $s[ $key ], $range[0], $range[1] );
		}
		$s['hide_admin'] = empty( $raw['hide_admin'] ) ? 0 : 1;
		$s['delete_data'] = empty( $raw['delete_data'] ) ? 0 : 1;
		update_option( 'bap_settings', $s );
		self::redirect( 'bap-settings', 'settings' );
	}

	public static function status( array $d ): string {
		if ( ! $d['enabled'] ) { return 'Paused'; }
		if ( $d['end'] && time() >= (int) $d['end'] ) { return 'Expired'; }
		if ( $d['start'] && time() < (int) $d['start'] ) { return 'Scheduled'; }
		return 'Active';
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$page = sanitize_key( $_GET['page'] ?? 'banner-studio' );
		$tabs = array( 'banner-studio' => 'Dashboard', 'bap-banners' => 'Banners', 'bap-placements' => 'Placements', 'bap-settings' => 'Settings' );
		?>
		<div class="wrap bap-app">
			<header class="bap-header"><div><span class="bap-eyebrow">YOUR SPACE. YOUR MESSAGE.</span><h1>Banner Studio <span>1.0</span></h1><p>Beautiful banners. Thoughtful placements. Fully yours.</p></div><a class="button button-primary bap-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=bap-banners&edit=new' ) ); ?>">+ Create banner</a></header>
			<nav class="bap-nav" aria-label="Banner Studio sections"><?php foreach ( $tabs as $slug => $label ) : ?><a class="<?php echo $page === $slug ? 'is-current' : ''; ?>" <?php echo $page === $slug ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav>
			<?php
			$notices = array( 'saved' => 'Banner saved. Clear your page cache to publish the change everywhere.', 'deleted' => 'Banner deleted. Clear your page cache to remove cached copies.', 'settings' => 'Settings saved. Clear your page cache to apply changes everywhere.' );
			$notice = sanitize_key( $_GET['bap_notice'] ?? '' );
			if ( isset( $notices[ $notice ] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notices[ $notice ] ) . '</p></div>'; }
			if ( 'bap-settings' === $page ) { self::settings_page(); }
			elseif ( 'bap-placements' === $page ) { self::placements(); }
			elseif ( 'bap-banners' === $page && isset( $_GET['edit'] ) ) { self::editor(); }
			elseif ( 'bap-banners' === $page ) { self::listing(); }
			else { self::dashboard(); }
			?>
			<footer class="bap-footer">Banner Studio · Self-managed images. No ad networks. No tracking.</footer>
		</div>
		<?php
	}

	private static function dashboard(): void {
		$all = BAP_Plugin::banners();
		$active = count( array_filter( $all, static fn( $p ) => 'Active' === self::status( BAP_Plugin::data( $p->ID ) ) ) );
		?>
		<div class="bap-stats"><div class="bap-card"><span>Total banners</span><strong><?php echo count( $all ); ?></strong><small>Your creative library</small></div><div class="bap-card"><span>Active banners</span><strong><?php echo (int) $active; ?></strong><small>Enabled and within schedule</small></div><div class="bap-card"><span>Display limit</span><strong><?php echo (int) BAP_Plugin::settings()['max']; ?><em>/ page</em></strong><small>Keep your content in focus</small></div></div>
		<section class="bap-card bap-welcome"><div><span class="bap-eyebrow">A LITTLE SPACE, A BIG IMPRESSION</span><h2>Your next campaign starts here.</h2><p>Upload your artwork, find the right place, and preview it on every screen.</p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=bap-banners&edit=new' ) ); ?>">Create your banner</a></div><div class="bap-art" aria-hidden="true"><div><span>YOUR NEXT<br>BIG THING.</span><i>Made for your audience ↗</i></div></div></section>
		<div class="bap-grid"><section class="bap-card"><h2>A simple workflow</h2><ol class="bap-steps"><li><b>Upload</b><span>Choose desktop and mobile artwork.</span></li><li><b>Place</b><span>Select content, devices, and schedule.</span></li><li><b>Preview & publish</b><span>Review your creative, then save.</span></li></ol></section><section class="bap-card"><h2>Designed with readers in mind</h2><p>Responsive artwork, reserved image space, labeled placements, and optional dismissible mobile banners.</p><p>Start with a 970 × 250 desktop banner and a 320 × 100 mobile banner. Optimize images before uploading; aim for under 200 KB.</p><p class="bap-muted">No ranking or Google approval guarantees. Review your final page on real devices.</p></section></div>
		<?php
	}

	private static function listing(): void {
		?>
		<section class="bap-card"><div class="bap-section-head"><div><h2>Your banners</h2><p>Manage your creative library and publishing schedule.</p></div><div class="bap-filters"><label class="screen-reader-text" for="bap-search">Search banners</label><input id="bap-search" type="search" placeholder="Search banners…"><label class="screen-reader-text" for="bap-status">Filter status</label><select id="bap-status"><option value="">All statuses</option><?php foreach ( array( 'Active', 'Scheduled', 'Paused', 'Expired' ) as $status ) { echo '<option>' . esc_html( $status ) . '</option>'; } ?></select></div></div>
		<?php if ( ! BAP_Plugin::banners() ) : ?><div class="bap-empty"><span class="dashicons dashicons-format-image"></span><h3>A fresh canvas.</h3><p>Create your first banner to bring this space to life.</p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=bap-banners&edit=new' ) ); ?>">Create banner</a></div><?php else : ?>
		<div class="bap-table-scroll"><table class="widefat bap-table"><thead><tr><th>Banner</th><th>Status</th><th>Placement / devices</th><th>Schedule</th><th>Actions</th></tr></thead><tbody>
		<?php foreach ( BAP_Plugin::banners() as $post ) : $d = BAP_Plugin::data( $post->ID ); $status = self::status( $d ); ?>
		<tr data-bap-row data-name="<?php echo esc_attr( strtolower( $post->post_title ) ); ?>" data-status="<?php echo esc_attr( $status ); ?>"><td><div class="bap-banner-name"><?php echo wp_get_attachment_image( $d['desktop_image'] ?: $d['mobile_image'], array( 96, 48 ) ); ?><div><a href="<?php echo esc_url( admin_url( 'admin.php?page=bap-banners&edit=' . $post->ID ) ); ?>"><b><?php echo esc_html( $post->post_title ); ?></b></a><code>[banner_studio id="<?php echo (int) $post->ID; ?>"]</code></div></div></td><td><span class="bap-badge bap-<?php echo esc_attr( strtolower( $status ) ); ?>"><?php echo esc_html( $status ); ?></span></td><td><?php echo esc_html( self::placement_labels()[ $d['placement'] ] ); ?><small><?php echo esc_html( implode( ' + ', array_filter( array( $d['desktop'] ? 'Desktop' : '', $d['mobile'] ? 'Mobile' : '' ) ) ) ?: 'No devices' ); ?></small></td><td><small><?php echo $d['start'] ? esc_html( wp_date( 'M j, Y H:i', (int) $d['start'] ) ) : 'Starts immediately'; ?><br><?php echo $d['end'] ? 'Until ' . esc_html( wp_date( 'M j, Y H:i', (int) $d['end'] ) ) : 'No end date'; ?></small></td><td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=bap-banners&edit=' . $post->ID ) ); ?>">Edit</a><form class="bap-delete" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'bap_delete_' . $post->ID ); ?><input type="hidden" name="action" value="bap_delete"><input type="hidden" name="banner_id" value="<?php echo (int) $post->ID; ?>"><button type="submit" class="button-link-delete">Delete</button></form></td></tr>
		<?php endforeach; ?>
		</tbody></table></div><p id="bap-no-results" hidden>No banners match your filters.</p><?php endif; ?></section>
		<?php
	}

	public static function placement_labels(): array {
		return array( 'before' => 'Before content', 'after' => 'After content', 'after_paragraph' => 'After paragraph', 'after_heading' => 'After heading', 'manual' => 'Manual / block / shortcode', 'sticky' => 'Mobile bottom sticky' );
	}

	private static function field( string $key, string $label, $value, string $type = 'text', string $help = '', array $attrs = array() ): void {
		$prefix = $attrs['prefix'] ?? 'banner'; unset( $attrs['prefix'] );
		?>
		<div class="bap-field">
			<label for="bap-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input id="bap-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $prefix . '[' . $key . ']' ); ?>" type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php if ( $help ) { echo 'aria-describedby="bap-help-' . esc_attr( $key ) . '" '; } ?><?php foreach ( $attrs as $name => $attr ) { echo esc_attr( $name ) . '="' . esc_attr( $attr ) . '" '; } ?>>
			<?php if ( $help ) : ?><small id="bap-help-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $help ); ?></small><?php endif; ?>
		</div>
		<?php
	}

	private static function toggle( string $key, string $label, $value, string $prefix = 'banner' ): void {
		echo '<label class="bap-check"><input type="checkbox" name="' . esc_attr( $prefix . '[' . $key . ']' ) . '" value="1" ' . checked( $value, 1, false ) . '> <span>' . esc_html( $label ) . '</span></label>';
	}

	private static function editor(): void {
		$id = absint( $_GET['edit'] ?? 0 );
		if ( $id && 'bap_banner' !== get_post_type( $id ) ) { echo '<p>Banner not found.</p>'; return; }
		$d = $id ? BAP_Plugin::data( $id ) : BAP_Plugin::banner_defaults();
		?>
		<form id="bap-editor" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'bap_save' ); ?><input type="hidden" name="action" value="bap_save"><input type="hidden" name="banner_id" value="<?php echo (int) $id; ?>">
		<div class="bap-section-head"><div><h2><?php echo $id ? 'Edit banner' : 'Create banner'; ?></h2><p>Make something worth noticing.</p></div><button class="button button-primary bap-primary" type="submit">Save banner</button></div>
		<div class="bap-editor-grid"><div class="bap-editor-fields">
		<section class="bap-card"><h3>01 · Creative</h3><label class="bap-field" for="bap-title"><span>Banner name</span><input id="bap-title" name="title" required maxlength="200" value="<?php echo esc_attr( $id ? get_the_title( $id ) : '' ); ?>" placeholder="e.g. Summer collection"></label>
		<div class="bap-grid">
		<?php foreach ( array( 'desktop_image' => 'Desktop artwork', 'mobile_image' => 'Mobile artwork' ) as $key => $label ) : $image = BAP_Plugin::image( (int) $d[ $key ] ); ?>
		<div class="bap-media"><span><?php echo esc_html( $label ); ?></span><div class="bap-media-preview"><?php if ( $image ) : ?><img src="<?php echo esc_url( $image['url'] ); ?>" alt=""><?php else : ?><span>No image selected</span><?php endif; ?></div><input id="bap-<?php echo esc_attr( $key ); ?>" type="hidden" name="banner[<?php echo esc_attr( $key ); ?>]" value="<?php echo (int) $d[ $key ]; ?>"><button class="button bap-select-image" type="button" data-field="<?php echo esc_attr( $key ); ?>">Choose image</button> <button class="button-link bap-remove-image" type="button" data-field="<?php echo esc_attr( $key ); ?>">Remove</button></div>
		<?php endforeach; ?></div><p class="bap-muted">JPEG, PNG, WebP, or AVIF. Aim for under 200 KB. If one image is missing, the other is used on both devices.</p>
		<?php self::field( 'alt', 'Alternative text', $d['alt'], 'text', 'Describe the message or destination. Required when the banner is linked.' ); self::field( 'url', 'Destination URL', $d['url'], 'url', 'Optional. Use a full https:// or http:// address.', array( 'placeholder' => 'https://example.com/offer' ) ); ?>
		<div class="bap-inline"><?php self::toggle( 'new_tab', 'Open link in a new tab', $d['new_tab'] ); self::toggle( 'sponsored', 'Paid / sponsored link', $d['sponsored'] ); ?></div></section>
		<section class="bap-card"><h3>02 · Placement & audience</h3><div class="bap-field"><label for="bap-placement">Placement</label><select id="bap-placement" name="banner[placement]"><?php foreach ( self::placement_labels() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $key, $d['placement'], false ) . '>' . esc_html( $label ) . '</option>'; } ?></select></div>
		<?php self::field( 'position', 'Paragraph / heading number', $d['position'], 'number', 'Counts top-level text paragraphs or H2–H6 headings. Nested layouts are left intact.', array( 'min' => 1, 'max' => 100 ) ); ?>
		<div class="bap-inline"><?php self::toggle( 'desktop', 'Show on desktop (768px+)', $d['desktop'] ); self::toggle( 'mobile', 'Show on mobile (up to 767px)', $d['mobile'] ); ?></div><p class="bap-muted">Sticky placement appears only on mobile and is capped at 100px of artwork height. Only one sticky banner is selected per page.</p>
		<fieldset><legend>Show on content types</legend><div class="bap-inline"><?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : if ( 'attachment' === $type->name ) { continue; } ?><label class="bap-check"><input type="checkbox" name="banner[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $d['post_types'], true ) ); ?>> <?php echo esc_html( $type->label ); ?></label><?php endforeach; ?></div></fieldset>
		<details><summary>Advanced targeting</summary><div class="bap-detail-body"><?php self::field( 'categories', 'Category IDs', implode( ',', $d['categories'] ), 'text', 'Comma-separated IDs. Empty means all categories. Any selected category matches.' ); self::field( 'tags', 'Tag IDs', implode( ',', $d['tags'] ), 'text', 'Comma-separated IDs. Category and tag filters must both match when set.' ); self::field( 'exclude', 'Excluded post / page IDs', implode( ',', $d['exclude'] ), 'text', 'Comma-separated IDs. Exclusions also apply to manual placements.' ); self::field( 'priority', 'Priority', $d['priority'], 'number', 'Lower numbers are considered first. Placement limits still apply.', array( 'min' => 1, 'max' => 100 ) ); ?></div></details></section>
		<section class="bap-card"><h3>03 · Appearance</h3><div class="bap-grid"><?php self::field( 'width', 'Desktop maximum width (px)', $d['width'], 'number', '', array( 'min' => 1, 'max' => 2400 ) ); self::field( 'mobile_width', 'Mobile maximum width (px)', $d['mobile_width'], 'number', '', array( 'min' => 1, 'max' => 767 ) ); ?></div>
		<?php self::toggle( 'stretch', 'Stretch across available width (overrides maximum widths)', $d['stretch'] ); self::field( 'label', 'Visible advertisement label', $d['label'], 'text', 'Keep a clear label so readers can distinguish advertising.' ); ?>
		<details><summary>Spacing & alignment</summary><div class="bap-detail-body"><div class="bap-field"><label for="bap-align">Alignment</label><select id="bap-align" name="banner[align]"><?php foreach ( array( 'left', 'center', 'right' ) as $align ) { echo '<option ' . selected( $align, $d['align'], false ) . '>' . esc_html( $align ) . '</option>'; } ?></select></div><div class="bap-grid"><?php self::field( 'margin', 'Vertical margin (px)', $d['margin'], 'number', '', array( 'min' => 0, 'max' => 120 ) ); self::field( 'padding', 'Inner padding (px)', $d['padding'], 'number', '', array( 'min' => 0, 'max' => 60 ) ); ?></div></div></details></section>
		<section class="bap-card"><h3>04 · Publishing</h3><?php self::toggle( 'enabled', 'Enable this banner', $d['enabled'] ); ?><div class="bap-grid"><?php foreach ( array( 'start' => 'Start date', 'end' => 'End date' ) as $key => $label ) { self::field( $key, $label, $d[ $key ] ? wp_date( 'Y-m-d\TH:i', (int) $d[ $key ] ) : '', 'datetime-local' ); } ?></div><p class="bap-muted">Timezone: <?php echo esc_html( wp_timezone_string() ); ?>. Empty dates mean no restriction. Clear page caches after changes and at scheduled start times. Cached banners are hidden after expiry in the browser.</p><?php if ( $id ) : ?><p>Manual placement: <code>[banner_studio id="<?php echo (int) $id; ?>"]</code></p><?php endif; ?></section>
		<div class="bap-section-head"><a href="<?php echo esc_url( admin_url( 'admin.php?page=bap-banners' ) ); ?>">Back to banners</a><button class="button button-primary bap-primary">Save banner</button></div>
		</div><aside class="bap-preview-panel bap-card"><div class="bap-section-head"><h3>Live creative preview</h3><span class="bap-badge">Preview</span></div><div class="bap-preview-tabs" role="group" aria-label="Preview device"><button type="button" class="is-current" data-preview="desktop" aria-pressed="true">Desktop</button><button type="button" data-preview="mobile" aria-pressed="false">Mobile</button></div><div id="bap-preview-device"><div class="bap-mock-heading"></div><div class="bap-mock-line"></div><div class="bap-mock-line short"></div><div id="bap-preview-banner"><span id="bap-preview-label">Advertisement</span><img id="bap-preview-image" alt="Banner preview" hidden><p id="bap-preview-empty">Your artwork will appear here.</p></div><div class="bap-mock-line"></div><div class="bap-mock-line"></div><div class="bap-mock-line short"></div></div><p id="bap-preview-description" class="bap-muted">Creative preview only. Test placement in your theme before publishing.</p><p id="bap-form-feedback" role="alert"></p></aside></div></form>
		<?php
	}

	private static function placements(): void {
		?>
		<section class="bap-card"><h2>A place for every message</h2><p>Choose a placement per banner. Use automatic placements for standard articles and manual placements for custom layouts.</p><div class="bap-placement-grid"><?php foreach ( self::placement_labels() as $key => $label ) : ?><article><div class="bap-placement-art <?php echo esc_attr( $key ); ?>" aria-hidden="true"><i></i><b></b><i></i></div><h3><?php echo esc_html( $label ); ?></h3><p><?php echo esc_html( array( 'before' => 'Immediately before the main article.', 'after' => 'Immediately after the main article.', 'after_paragraph' => 'After your chosen top-level text paragraph.', 'after_heading' => 'After your chosen H2–H6 heading.', 'manual' => 'Place exactly with the Banner Studio block or shortcode.', 'sticky' => 'A dismissible bottom banner on mobile, with space reserved below the page.' )[ $key ] ); ?></p></article><?php endforeach; ?></div></section>
		<section class="bap-card"><h2>Placement guardrails</h2><p>Automatic placements run only on the main content of individual posts and pages. Feeds, previews, search results, password-protected content, and REST responses are excluded. Paragraph placement requires PHP DOM.</p><p>Spacing applies between automatically inserted in-content banners. Before/after and manually positioned banners need editorial spacing. The page cap includes manual banners and the sticky slot. Nested groups, tables, code, and page-builder layouts are left intact; use the block or shortcode there.</p><p>Sticky banners appear after scrolling, hide when a form control is focused, and support an adjustable bottom offset. For cookie notices, your consent UI can set <code>document.documentElement.dataset.bapOverlay = 'true'</code> while open, then remove it when closed. Unknown third-party overlays cannot be detected reliably.</p></section>
		<?php
	}

	private static function settings_page(): void {
		$s = BAP_Plugin::settings();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'bap_settings' ); ?><input type="hidden" name="action" value="bap_settings"><div class="bap-grid"><section class="bap-card"><h2>Display guardrails</h2><?php self::field( 'max', 'Maximum banners per page', $s['max'], 'number', 'Includes manual and sticky banners.', array( 'prefix' => 'settings', 'min' => 1, 'max' => 10 ) ); self::field( 'gap', 'Minimum in-content spacing (elements)', $s['gap'], 'number', 'Top-level content elements between automatic in-content banners.', array( 'prefix' => 'settings', 'min' => 1, 'max' => 20 ) ); self::field( 'min_words', 'Minimum article word count', $s['min_words'], 'number', 'Applies to automatic content placements; manual and sticky placements are independent.', array( 'prefix' => 'settings', 'min' => 0, 'max' => 10000 ) ); self::toggle( 'hide_admin', 'Hide frontend banners for administrators', $s['hide_admin'], 'settings' ); ?><p class="bap-muted">With page caching, exclude logged-in users from cache to preserve administrator visibility preferences.</p></section><section class="bap-card"><h2>Mobile sticky banner</h2><?php self::field( 'sticky_offset', 'Bottom offset (px)', $s['sticky_offset'], 'number', 'Leave room for a fixed bottom navigation. Device safe-area space is added automatically.', array( 'prefix' => 'settings', 'min' => 0, 'max' => 300 ) ); self::field( 'dismiss_hours', 'Remember dismissal (hours)', $s['dismiss_hours'], 'number', 'Stored on this browser only. Zero remembers dismissal until the tab session ends.', array( 'prefix' => 'settings', 'min' => 0, 'max' => 8760 ) ); ?><p>The artwork is limited to 100px tall and 15% of viewport height. Sticky banners wait until the reader scrolls 160px and the page is at least two screens long.</p><h3>Data ownership</h3><?php self::toggle( 'delete_data', 'Delete plugin banners and settings on uninstall', $s['delete_data'], 'settings' ); ?><p class="bap-muted">Disabled by default. Uploaded images are always retained. No analytics, cookies, or third-party requests are added by this plugin; dismissal uses browser storage.</p></section></div><button class="button button-primary bap-primary">Save settings</button></form>
		<?php
	}
}
