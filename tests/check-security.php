<?php
/**
 * Check permissions, nonces, output safety and CSV formula protection.
 *
 * @package HrefWise
 */

define( 'DOING_AJAX', true );
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

/**
 * Require a security regression condition to hold.
 *
 * @param bool   $condition Required regression condition.
 * @param string $message Regression failure or validation message.
 * @throws RuntimeException If the regression expectation fails.
 * @return void
 */
function hrefwise_security_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}

$die_handler = function () {
	return function ( $message, $title, $args ) {
		throw new RuntimeException( 'blocked', (int) ( $args['response'] ?? http_response_code() ) );
	};
};
foreach ( array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler' ) as $filter ) {
	add_filter( $filter, $die_handler );
}

/**
 * Check that an unauthorized callback stops with the expected status.
 *
 * @param callable $callback Callback under test.
 * @param int      $status Expected HTTP status.
 * @param bool     $json Whether to inspect a JSON response.
 * @throws RuntimeException If the regression expectation fails.
 * @return void
 */
function hrefwise_expect_denied( $callback, $status, $json = false ) {
	http_response_code( 200 );
	ob_start();
	try {
		$callback();
		throw new RuntimeException( 'Request was not stopped.' );
	} catch ( RuntimeException $error ) {
		$output = ob_get_clean();
		hrefwise_security_check( $error->getMessage() === 'blocked' && $error->getCode() === $status, 'Request must stop with its expected denial status.' );
		if ( $json ) {
			$response = json_decode( $output, true );
			hrefwise_security_check( isset( $response['success'] ) && ! $response['success'], 'Denied AJAX requests must not return a successful response.' );
		}
	}
}

$admin_users = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
$user_ids    = array( 0 );
$record_ids  = array();
try {
	foreach ( array( 'subscriber', 'contributor', 'author', 'editor' ) as $fixture_role ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'hrefscannertest' . $fixture_role . '-' . substr( wp_generate_uuid4(), 0, 8 ),
				'user_pass'  => wp_generate_password( 32, true, true ),
				'role'       => $fixture_role,
			)
		);
		hrefwise_security_check( ! is_wp_error( $user_id ), 'Could not create a permission fixture.' );
		$user_ids[] = $user_id;
	}
	foreach ( $user_ids as $user_id ) {
		wp_set_current_user( $user_id );
		$_REQUEST = array(
			'nonce'    => wp_create_nonce( 'hrefwise_scan' ),
			'_wpnonce' => wp_create_nonce( 'hrefwise_export' ),
		);
		$_POST    = array( 'mode' => 'complete' );
		$_GET     = array( 'post_type' => 'hrefwise_ext_link' );
		hrefwise_expect_denied( 'hrefwise_ajax_scan', 403, true );
		hrefwise_expect_denied( 'hrefwise_export_csv', 403 );
		hrefwise_expect_denied( 'hrefwise_report_page', 403 );
		hrefwise_expect_denied( 'hrefwise_settings_page', 403 );
		foreach ( array( 'hrefwise_ext_link', 'hrefwise_int_link' ) as $fixture_post_type ) {
			$type_object = get_post_type_object( $fixture_post_type );
			hrefwise_security_check( ! current_user_can( $type_object->cap->edit_posts ) && ! current_user_can( $type_object->cap->read_post ), 'Link records must remain administrator-only.' );
			hrefwise_security_check( ! $type_object->public && ! $type_object->publicly_queryable && ! $type_object->show_in_rest && $type_object->exclude_from_search, 'Private link records must not be exposed by public queries or REST.' );
		}
		hrefwise_security_check( ! get_option( 'hrefwise_scan_lock' ) && ! get_option( 'hrefwise_scan_state' ), 'Denied requests must not start or lock a scan.' );
	}
	wp_set_current_user( $admin_users[0]->ID );
	$_REQUEST = array(
		'nonce'    => 'invalid',
		'_wpnonce' => 'invalid',
	);
	hrefwise_expect_denied( 'hrefwise_ajax_scan', 403 );
	hrefwise_expect_denied( 'hrefwise_export_csv', 403 );
	$_REQUEST = array(
		'nonce'    => wp_create_nonce( 'hrefwise_scan' ),
		'_wpnonce' => wp_create_nonce( 'hrefwise_export' ),
	);
	$_POST    = array( 'mode' => 'invalid' );
	hrefwise_expect_denied( 'hrefwise_ajax_scan', 500, true );
	hrefwise_security_check( ! get_option( 'hrefwise_scan_lock' ), 'A failed scan must release its lock.' );
	$_GET = array( 'post_type' => 'post' );
	hrefwise_expect_denied( 'hrefwise_export_csv', 400 );
	$_GET = array();
	foreach ( array( '=1+1', '+1+1', '-1+1', '@SUM(1,1)', "\t=1", "\r=1", "\n=1", '  =1' ) as $formula ) {
		$record_id = wp_insert_post(
			array(
				'post_type'   => 'hrefwise_ext_link',
				'post_status' => 'publish',
				'post_title'  => 'Security fixture',
				'meta_input'  => array(
					'_hrefwise_url'          => 'javascript:alert(1)',
					'_hrefwise_anchor'       => $formula,
					'_hrefwise_source_title' => '<script>alert(1)</script>',
					'_hrefwise_source_type'  => 'post',
				),
			),
			true
		);
		hrefwise_security_check( ! is_wp_error( $record_id ), 'Could not create an output fixture.' );
		$record_ids[] = $record_id;
		ob_start();
		hrefwise_link_column( 'hrefwise_url', $record_id );
		hrefwise_link_column( 'hrefwise_source', $record_id );
		$output = ob_get_clean();
		hrefwise_security_check( strpos( $output, 'href="javascript:' ) === false && strpos( $output, '<script>' ) === false, 'Stored values must not create executable links or HTML.' );
	}
	$stream = fopen( 'php://temp/maxmemory:1048576', 'w+' );
	hrefwise_write_csv( $stream, 'hrefwise_ext_link' );
	rewind( $stream );
	fgetcsv( $stream, 0, ',', '"', '' );
	$formula_count = 0;
	$row           = fgetcsv( $stream, 0, ',', '"', '' );
	while ( false !== $row ) {
		if ( strpos( $row[3], 'alert(1)' ) !== false ) {
			hrefwise_security_check( substr( $row[1], 0, 1 ) === "'", 'CSV formula prefixes and leading control characters must be neutralized.' );
			++$formula_count;
		}
		$row = fgetcsv( $stream, 0, ',', '"', '' );
	}
	unset( $stream );
	hrefwise_security_check( 8 === $formula_count, 'Every CSV formula fixture must be checked.' );
	echo "Role authorization, nonce rejection, lock release, record privacy, stored output and CSV formula checks passed.\n";
} finally {
	foreach ( $record_ids as $record_id ) {
		wp_delete_post( $record_id, true );
	}
	foreach ( array_filter( $user_ids ) as $user_id ) {
		wp_delete_user( $user_id );
	}
	foreach ( array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler' ) as $filter ) {
		remove_filter( $filter, $die_handler );
	}
	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
}
