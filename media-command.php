<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$wpcli_media_autoloader = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $wpcli_media_autoloader ) ) {
	require_once $wpcli_media_autoloader;
}

WP_CLI::add_command(
	'media',
	'Media_Command',
	array(
		'before_invoke' => function () {
			if ( ! wp_image_editor_supports() ) {
				WP_CLI::error(
					'No support for generating images found. ' .
					'Please install the Imagick or GD PHP extensions.'
				);
			}
		},
	)
);
