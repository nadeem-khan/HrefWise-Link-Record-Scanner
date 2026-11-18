<?php
/**
 * Check content selection, list filters and CSV export on disposable data.
 *
 * @package HrefWise
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

/**
 * Require a disposable-site regression condition to hold.
 *
 * @param bool   $condition Required regression condition.
 * @param string $message Regression failure or validation message.
 * @throws RuntimeException If the regression expectation fails.
 * @return void
 */
function hrefwise_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}

/**
 * Require a failed native scan query to leave resumable state unchanged.
 *
 * @param string $scan_id Existing scan identifier, or an empty initial request.
 * @return void
 */
function hrefwise_check_failed_query( $scan_id = '' ) {
	global $wpdb;
	$saved_state          = get_option( 'hrefwise_scan_state' );
	$scan_request         = '';
	$capture_request      = function ( $request, $query ) use ( &$scan_request ) {
		if ( $query->get( 'hrefwise_scan' ) || $query->get( 'hrefwise_stale' ) || $query->get( 'hrefwise_orphans' ) ) {
			$scan_request = $request;
		}
		return $request;
	};
	$fail_query           = function ( $request ) use ( &$scan_request ) {
		return $request === $scan_request ? 'SELECT hrefwise_missing_column' : $request;
	};
	$previous_suppression = $wpdb->suppress_errors( true );
	add_filter( 'posts_request', $capture_request, 10, 2 );
	add_filter( 'query', $fail_query );
	try {
		$result = hrefwise_scan_batch( $scan_id );
		hrefwise_check( is_wp_error( $result ) && 'hrefwise_database' === $result->get_error_code(), 'Failed scan queries must return a database error.' );
		hrefwise_check( get_option( 'hrefwise_scan_state' ) === $saved_state, 'A failed query must not advance or clear scan state.' );
	} finally {
		remove_filter( 'posts_request', $capture_request );
		remove_filter( 'query', $fail_query );
		$wpdb->suppress_errors( $previous_suppression );
		$wpdb->last_error = '';
	}
}

$admin_users = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
wp_set_current_user( $admin_users[0]->ID );
register_post_type(
	'href_test_book',
	array(
		'public' => true,
		'labels' => array(
			'name'          => 'Test Books',
			'singular_name' => 'Test Book',
		),
	)
);
register_post_type( 'href_test_article', array( 'public' => true ) );
register_post_type(
	'href_test_no_editor',
	array(
		'public'   => true,
		'supports' => array( 'title' ),
	)
);
$saved_types = get_option( 'hrefwise_content_types', false );
$saved_state = get_option( 'hrefwise_scan_state', false );
$source_ids  = array();
hrefwise_check( add_option( 'hrefwise_scan_lock', time(), '', false ), 'Another scan is running; wait before verification.' );

try {
	hrefwise_check( hrefwise_sanitize_content_types( array( 'post', 'page', 'href_test_book', 'href_test_no_editor', 'hrefwise_ext_link', 'hrefwise_int_link', 'invalid' ) ) === array( 'post', 'page', 'href_test_book' ), 'Content selection must allow editor-supported custom types and reject types without an editor or generated records.' );
	update_option( 'hrefwise_content_types', array( 'href_test_book', 'href_test_article' ) );
	delete_option( 'hrefwise_scan_state' );
	for ( $index = 0; $index < 8; $index++ ) {
		$source_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 7 === $index ? 'href_test_article' : 'href_test_book',
					'post_status'  => 6 === $index ? 'inherit' : 'draft',
					'post_title'   => 'HrefWise CSV fixture, café',
					'post_content' => '<a href="https://csv.example.test/?index=' . $index . '" rel="nofollow">=1+1</a><a href="/csv-target/">Internal, café</a>',
				)
			),
			true
		);
		hrefwise_check( ! is_wp_error( $source_id ), 'Could not create a source fixture.' );
		$source_ids[] = $source_id;
	}
	hrefwise_check_failed_query();
	$state = hrefwise_scan_batch();
	hrefwise_check( ! is_wp_error( $state ) && 8 === $state['total'] && 5 === $state['processed'] && array( 'href_test_book', 'href_test_article' ) === $state['content_types'], 'A new scan must count and scan all selected custom types only.' );
	update_option( 'hrefwise_content_types', array( 'page' ) );
	hrefwise_check_failed_query( $state['scan_id'] );
	$state = hrefwise_scan_batch( $state['scan_id'] );
	hrefwise_check( 8 === $state['processed'] && 'cleanup' === $state['phase'] && 8 === $state['external'] && 8 === $state['internal'], 'Resuming must retain the original selection and include inherited content.' );
	$record_ids = get_posts(
		array(
			'post_type'       => array( 'hrefwise_ext_link', 'hrefwise_int_link' ),
			'post_parent__in' => $source_ids,
			'posts_per_page'  => -1,
			'fields'          => 'ids',
		)
	);
	hrefwise_check( count( $record_ids ) === 16, 'Every custom-source link must be saved.' );
	$stale_record_id = wp_insert_post(
		array(
			'post_type'   => 'hrefwise_ext_link',
			'post_status' => 'publish',
			'post_parent' => $source_ids[0],
			'meta_input'  => array( '_hrefwise_scan_id' => 'obsolete' ),
		),
		true
	);
	hrefwise_check( ! is_wp_error( $stale_record_id ), 'Could not create a stale cleanup fixture.' );
	hrefwise_check_failed_query( $state['scan_id'] );
	$state = hrefwise_scan_batch( $state['scan_id'] );
	hrefwise_check( ! is_wp_error( $state ) && 'done' === $state['phase'] && ! get_post( $stale_record_id ), 'Cleanup must retry a failed query, remove stale records and complete normally.' );

	foreach ( $record_ids as $record_id ) {
		hrefwise_check( get_post_meta( $record_id, '_hrefwise_source_type', true ) === get_post_type( wp_get_post_parent_id( $record_id ) ), 'Scanned records must retain their source type.' );
	}
	delete_post_meta( $record_ids[0], '_hrefwise_source_type' );
	hrefwise_check( hrefwise_source_type( $record_ids[0] ) === get_post_type( wp_get_post_parent_id( $record_ids[0] ) ), 'Existing records without type metadata must resolve their source type.' );
	$_GET = array(
		'hrefwise_source'       => 'HrefWise CSV fixture',
		'hrefwise_content_type' => 'href_test_book',
		'hrefwise_filter_nonce' => wp_create_nonce( 'hrefwise_filter' ),
	);
	foreach ( array( 'hrefwise_ext_link', 'hrefwise_int_link' ) as $fixture_post_type ) {
		set_current_screen( 'edit-' . $fixture_post_type );
		$listing_query = $GLOBALS['wp_the_query'];
		$listing_query->query(
			array(
				'post_type'      => $fixture_post_type,
				'posts_per_page' => 2,
			)
		);
		hrefwise_check( 7 === $listing_query->found_posts && count( $listing_query->posts ) === 2, 'Native listings must combine source and content-type filters.' );
		$stream = fopen( 'php://temp/maxmemory:1048576', 'w+' );
		hrefwise_write_csv( $stream, $fixture_post_type );
		rewind( $stream );
		$rows = array();
		$row  = fgetcsv( $stream, 0, ',', '"', '' );
		while ( false !== $row ) {
			$rows[] = $row;
			$row    = fgetcsv( $stream, 0, ',', '"', '' );
		}
		unset( $stream );
		hrefwise_check( count( $rows ) === 8 && count( $rows[0] ) === 5 && 'href_test_book' === $rows[1][4], 'CSV must export all matching records across listing pages, with Content Type.' );
		hrefwise_check( 'HrefWise CSV fixture, café' === $rows[1][3], 'CSV must preserve commas and UTF-8.' );
		hrefwise_check( 'hrefwise_ext_link' !== $fixture_post_type || "'=1+1" === $rows[1][1], 'CSV must neutralize spreadsheet formulas.' );
		$_GET['hrefwise_group'] = get_post_meta( $listing_query->posts[0]->ID, '_hrefwise_group', true );
		$listing_query->query( array( 'post_type' => $fixture_post_type ) );
		hrefwise_check( 7 === $listing_query->found_posts, 'Report target filters must return every matching occurrence.' );
		$_GET['hrefwise_group'] .= '-different';
		$listing_query->query( array( 'post_type' => $fixture_post_type ) );
		hrefwise_check( 0 === $listing_query->found_posts, 'Report target filters must not match partial domains or pages.' );
		$stream = fopen( 'php://temp/maxmemory:1048576', 'w+' );
		hrefwise_write_csv( $stream, $fixture_post_type );
		rewind( $stream );
		fgetcsv( $stream, 0, ',', '"', '' );
		hrefwise_check( fgetcsv( $stream, 0, ',', '"', '' ) === false, 'Exports must apply the report target filter.' );
		unset( $stream );
		unset( $_GET['hrefwise_group'] );
		$_GET['hrefwise_content_type'] = 'page';
		$listing_query->query( array( 'post_type' => $fixture_post_type ) );
		hrefwise_check( 0 === $listing_query->found_posts, 'Unmatched content types must return no records.' );
		$_GET['hrefwise_content_type'] = 'href_test_book';
		ob_start();
		hrefwise_list_filters( $fixture_post_type, 'top' );
		$filter_html = ob_get_clean();
		hrefwise_check( strpos( $filter_html, 'Export All' ) === false, 'Export All must be outside the left-aligned filters.' );
		ob_start();
		hrefwise_export_button( 'top' );
		$export_html = ob_get_clean();
		hrefwise_check( strpos( $export_html, 'class="button button-primary alignright hrefwise-export"' ) !== false && strpos( $export_html, 'Export All' ) !== false && strpos( $export_html, 'hrefwise_content_type=href_test_book' ) !== false && strpos( $export_html, '_wpnonce=' ) !== false, 'Each listing must show a distinct right-aligned nonce-protected export preserving active filters.' );
	}
	hrefwise_register_settings();
	hrefwise_check( array( 'post', 'page' ) === get_registered_settings()['hrefwise_content_types']['default'], 'Registered defaults must include Posts and Pages.' );
	ob_start();
	hrefwise_content_types_field();
	$settings_html = ob_get_clean();
	foreach ( get_post_types() as $content_type ) {
		hrefwise_check( ( strpos( $settings_html, 'value="' . $content_type . '"' ) !== false ) === isset( hrefwise_content_types()[ $content_type ] ), 'Settings must list editor-supported Posts, Pages and custom types only.' );
	}
	hrefwise_check( strpos( $settings_html, 'value="href_test_no_editor"' ) === false, 'Custom types without editor support must not appear in Settings.' );
	delete_option( 'hrefwise_content_types' );
	ob_start();
	hrefwise_content_types_field();
	$default_types_html = ob_get_clean();
	foreach ( array( 'post', 'page' ) as $content_type ) {
		hrefwise_check( strpos( $default_types_html, 'value="' . $content_type . '" checked=' ) !== false, 'Fresh settings must select Posts and Pages.' );
	}
	$die_handler = function () {
		return function ( $message, $title, $args ) {
			throw new RuntimeException( 'blocked:' . esc_html( $args['response'] ) );
		};
	};
	add_filter( 'wp_die_handler', $die_handler );
	foreach ( array(
		'forbidden' => 403,
		'nonce'     => 403,
		'type'      => 400,
	) as $case => $response_code ) {
		wp_set_current_user( 'forbidden' === $case ? 0 : $admin_users[0]->ID );
		$_GET     = array( 'post_type' => 'type' === $case ? 'post' : 'hrefwise_ext_link' );
		$_REQUEST = array( '_wpnonce' => 'nonce' === $case ? 'invalid' : wp_create_nonce( 'hrefwise_export' ) );
		try {
			hrefwise_export_csv();
			throw new RuntimeException( 'Export access validation did not block the request.' );
		} catch ( RuntimeException $error ) {
			hrefwise_check( $error->getMessage() === 'blocked:' . $response_code, 'Exports must reject missing permissions, invalid nonces and unsupported listings.' );
		}
	}
	remove_filter( 'wp_die_handler', $die_handler );
	wp_set_current_user( $admin_users[0]->ID );
	$_REQUEST = array();
	if ( ! in_array( '--skip-large-export', $argv, true ) ) {
		$large_content = '';
		for ( $index = 0; $index < 501; $index++ ) {
			$large_content .= '<a href="https://csv.example.test/batch/' . $index . '">Batch</a>';
		}
		wp_update_post(
			array(
				'ID'           => $source_ids[0],
				'post_content' => $large_content,
			)
		);
		hrefwise_scan_post( get_post( $source_ids[0] ), 'csv-batch-test', array() );
		$_GET   = array(
			'hrefwise_source'       => 'HrefWise CSV fixture',
			'hrefwise_content_type' => 'href_test_book',
			'hrefwise_filter_nonce' => wp_create_nonce( 'hrefwise_filter' ),
		);
		$stream = fopen( 'php://temp/maxmemory:1048576', 'w+' );
		hrefwise_write_csv( $stream, 'hrefwise_ext_link' );
		rewind( $stream );
		$row_count = 0;
		while ( fgetcsv( $stream, 0, ',', '"', '' ) !== false ) {
			++$row_count;
		}
		unset( $stream );
		hrefwise_check( 508 === $row_count, 'CSV must continue beyond its 500-record batch boundary.' );
	}
	update_option( 'hrefwise_content_types', array() );
	delete_option( 'hrefwise_scan_state' );
	hrefwise_check( is_wp_error( hrefwise_scan_batch() ) && ! get_option( 'hrefwise_scan_state' ), 'An empty selection must not start a scan or remove records.' );
	echo "Content selection, custom scans, resume snapshots, listing filters and CSV export checks passed.\n";
} finally {
	$_GET = array();
	foreach ( $source_ids as $source_id ) {
		foreach ( get_posts(
			array(
				'post_type'      => array( 'hrefwise_ext_link', 'hrefwise_int_link' ),
				'post_parent'    => $source_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		) as $record_id ) {
			wp_delete_post( $record_id, true );
		}
		wp_delete_post( $source_id, true );
	}
	false === $saved_state ? delete_option( 'hrefwise_scan_state' ) : update_option( 'hrefwise_scan_state', $saved_state, false );
	false === $saved_types ? delete_option( 'hrefwise_content_types' ) : update_option( 'hrefwise_content_types', $saved_types, false );
	delete_option( 'hrefwise_scan_lock' );
}
