<?php

use WP_CLI\Utils;

/**
 * Imports files as attachments, regenerates thumbnails, replaces existing attachment files, or lists registered image sizes.
 *
 * ## EXAMPLES
 *
 *     # Re-generate all thumbnails, without confirmation.
 *     $ wp media regenerate --yes
 *     Found 3 images to regenerate.
 *     1/3 Regenerated thumbnails for "Sydney Harbor Bridge" (ID 760).
 *     2/3 Regenerated thumbnails for "Boardwalk" (ID 757).
 *     3/3 Regenerated thumbnails for "Sunburst Over River" (ID 756).
 *     Success: Regenerated 3 of 3 images.
 *
 *     # Import a local image and set it to be the featured image for a post.
 *     $ wp media import ~/Downloads/image.png --post_id=123 --title="A downloaded picture" --featured_image
 *     Imported file '/home/person/Downloads/image.png' as attachment ID 1753 and attached to post 123 as featured image.
 *     Success: Imported 1 of 1 images.
 *
 *     # List all registered image sizes
 *     $ wp media image-size
 *     +---------------------------+-------+--------+-------+
 *     | name                      | width | height | crop  |
 *     +---------------------------+-------+--------+-------+
 *     | full                      |       |        | N/A   |
 *     | twentyfourteen-full-width | 1038  | 576    | hard  |
 *     | large                     | 1024  | 1024   | soft  |
 *     | medium_large              | 768   | 0      | soft  |
 *     | medium                    | 300   | 300    | soft  |
 *     | thumbnail                 | 150   | 150    | hard  |
 *     +---------------------------+-------+--------+-------+
 *
 *     # Fix orientation for specific images.
 *     $ wp media fix-orientation 63
 *     1/1 Fixing orientation for "Portrait_6" (ID 63).
 *     Success: Fixed 1 of 1 images.
 *
 * @package wp-cli
 */
class Media_Command extends WP_CLI_Command {

	/**
	 * Clear the WP object cache after this many regenerations/imports.
	 *
	 * @var integer
	 */
	const WP_CLEAR_OBJECT_CACHE_INTERVAL = 500;

	/**
	 * @var string|null
	 */
	private $destination_dir;

	/**
	 * Regenerates thumbnails for one or more attachments.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment-id>...]
	 * : One or more IDs of the attachments to regenerate.
	 *
	 * [--image_size=<image_size>...]
	 * : Name of the image size to regenerate. Repeat the flag to specify multiple. Only thumbnails of specified image size(s) will be regenerated, thumbnails of other image sizes will not.
	 *
	 * [--skip-delete]
	 * : Skip deletion of the original thumbnails. If your thumbnails are linked from sources outside your control, it's likely best to leave them around. Defaults to false.
	 *
	 * [--only-missing]
	 * : Only generate thumbnails for images missing image sizes.
	 *
	 * [--delete-unknown]
	 * : Only delete thumbnails for old unregistered image sizes.
	 *
	 * [--yes]
	 * : Answer yes to the confirmation message. Confirmation only shows when no IDs passed as arguments.
	 *
	 * ## EXAMPLES
	 *
	 *     # Regenerate thumbnails for given attachment IDs.
	 *     $ wp media regenerate 123 124 125
	 *     Found 3 images to regenerate.
	 *     1/3 Regenerated thumbnails for "Vertical Image" (ID 123).
	 *     2/3 Regenerated thumbnails for "Horizontal Image" (ID 124).
	 *     3/3 Regenerated thumbnails for "Beautiful Picture" (ID 125).
	 *     Success: Regenerated 3 of 3 images.
	 *
	 *     # Regenerate all thumbnails, without confirmation.
	 *     $ wp media regenerate --yes
	 *     Found 3 images to regenerate.
	 *     1/3 Regenerated thumbnails for "Sydney Harbor Bridge" (ID 760).
	 *     2/3 Regenerated thumbnails for "Boardwalk" (ID 757).
	 *     3/3 Regenerated thumbnails for "Sunburst Over River" (ID 756).
	 *     Success: Regenerated 3 of 3 images.
	 *
	 *     # Re-generate all thumbnails that have IDs between 1000 and 2000.
	 *     $ seq 1000 2000 | xargs wp media regenerate
	 *     Found 4 images to regenerate.
	 *     1/4 Regenerated thumbnails for "Vertical Featured Image" (ID 1027).
	 *     2/4 Regenerated thumbnails for "Horizontal Featured Image" (ID 1022).
	 *     3/4 Regenerated thumbnails for "Unicorn Wallpaper" (ID 1045).
	 *     4/4 Regenerated thumbnails for "I Am Worth Loving Wallpaper" (ID 1023).
	 *     Success: Regenerated 4 of 4 images.
	 *
	 *     # Re-generate only the thumbnails of "large" image size for all images.
	 *     $ wp media regenerate --image_size=large
	 *     Do you really want to regenerate the "large" image size for all images? [y/n] y
	 *     Found 3 images to regenerate.
	 *     1/3 Regenerated "large" thumbnail for "Sydney Harbor Bridge" (ID 760).
	 *     2/3 No "large" thumbnail regeneration needed for "Boardwalk" (ID 757).
	 *     3/3 Regenerated "large" thumbnail for "Sunburst Over River" (ID 756).
	 *     Success: Regenerated 3 of 3 images.
	 *
	 *     # Re-generate only the thumbnails of "large" and "medium" image sizes for all images.
	 *     $ wp media regenerate --image_size=large --image_size=medium
	 *     Do you really want to regenerate the "large", "medium" image sizes for all images? [y/n] y
	 *     Found 3 images to regenerate.
	 *     1/3 Regenerated "large", "medium" thumbnails for "Sydney Harbor Bridge" (ID 760).
	 *     2/3 No "large", "medium" thumbnail regeneration needed for "Boardwalk" (ID 757).
	 *     3/3 Regenerated "large", "medium" thumbnails for "Sunburst Over River" (ID 756).
	 *     Success: Regenerated 3 of 3 images.
	 *
	 * @param string[] $args Positional arguments.
	 * @param array{image_size?: string|string[], 'skip-delete'?: bool, 'only-missing'?: bool, 'delete-unknown'?: bool, yes?: bool} $assoc_args Associative arguments.
	 * @return void
	 */
	public function regenerate( $args, $assoc_args = array() ) {
		// Extract image_size separately as it may be a string or an array of strings.
		$image_size_raw = $assoc_args['image_size'] ?? null;
		unset( $assoc_args['image_size'] );

		// Normalize to an array: with WP-CLI 3.x and the ellipsis syntax, repeated flags yield an array.
		// With earlier versions a single string is returned.
		$image_sizes = array();
		if ( null !== $image_size_raw ) {
			$image_sizes = is_array( $image_size_raw ) ? $image_size_raw : [ $image_size_raw ];
		}

		if ( $image_sizes ) {
			$registered_sizes = get_intermediate_image_sizes();
			foreach ( $image_sizes as $size ) {
				if ( ! in_array( $size, $registered_sizes, true ) ) {
					WP_CLI::error( sprintf( 'Unknown image size "%s".', $size ) );
				}
			}
		}

		if ( empty( $args ) ) {
			if ( $image_sizes ) {
				WP_CLI::confirm(
					sprintf(
						'Do you really want to regenerate the %s for all images?',
						$this->get_image_sizes_description( $image_sizes, 'image size' )
					),
					$assoc_args
				);
			} else {
				WP_CLI::confirm( 'Do you really want to regenerate all images?', $assoc_args );
			}
		}

		$skip_delete  = Utils\get_flag_value( $assoc_args, 'skip-delete' );
		$only_missing = Utils\get_flag_value( $assoc_args, 'only-missing' );
		if ( $only_missing ) {
			$skip_delete = true;
		}

		$delete_unknown = Utils\get_flag_value( $assoc_args, 'delete-unknown' );
		if ( $delete_unknown ) {
			$skip_delete = false;
		}

		$additional_mime_types = array();

		if ( Utils\wp_version_compare( '4.7', '>=' ) ) {
			$additional_mime_types[] = 'application/pdf';
		}

		$images = $this->get_images( $args, $additional_mime_types );
		$count  = $images->post_count;

		if ( ! $count ) {
			WP_CLI::warning( 'No images found.' );
			return;
		}

		WP_CLI::log(
			sprintf(
				'Found %1$d %2$s to regenerate.',
				$count,
				_n( 'image', 'images', $count )
			)
		);

		if ( $image_sizes ) {
			$image_size_filters = $this->add_image_size_filters( $image_sizes );
		}

		$number    = 0;
		$successes = 0;
		$errors    = 0;
		$skips     = 0;

		/**
		 * @var int $post_id
		 */
		foreach ( $images->posts as $post_id ) {
			++$number;
			if ( 0 === $number % self::WP_CLEAR_OBJECT_CACHE_INTERVAL ) {
				// @phpstan-ignore function.deprecated
				Utils\wp_clear_object_cache();
			}
			$this->process_regeneration( $post_id, $skip_delete, $only_missing, $delete_unknown, $image_sizes, $number . '/' . $count, $successes, $errors, $skips );
		}

		if ( isset( $image_size_filters ) ) {
			$this->remove_image_size_filters( $image_size_filters );
		}

		Utils\report_batch_operation_results( 'image', 'regenerate', $count, $successes, $errors, $skips );
	}

	/**
	 * Creates attachments from local files or URLs.
	 *
	 * ## OPTIONS
	 *
	 * <file>...
	 * : Path to file or files to be imported. Supports the glob(3) capabilities of the current shell.
	 *     If file is recognized as a URL (for example, with a scheme of http or ftp), the file will be
	 *     downloaded to a temp file before being sideloaded.
	 *
	 * [--post_id=<post_id>]
	 * : ID of the post to attach the imported files to.
	 *
	 * [--post_name=<post_name>]
	 * : Name of the post to attach the imported files to.
	 *
	 * [--file_name=<name>]
	 * : Attachment name (post_name field).
	 *
	 * [--title=<title>]
	 * : Attachment title (post title field).
	 *
	 * [--caption=<caption>]
	 * : Caption for attachment (post excerpt field).
	 *
	 * [--alt=<alt_text>]
	 * : Alt text for image (saved as post meta).
	 *
	 * [--desc=<description>]
	 * : "Description" field (post content) of attachment post.
	 *
	 * [--skip-copy]
	 * : If set, media files (local only) are imported to the library but not moved on disk.
	 * File names will not be run through wp_unique_filename() with this set. When used, files
	 * will remain at their current location and will not be copied into any destination directory.
	 *
	 * [--destination-dir=<destination-dir>]
	 * : Path to the destination directory for uploaded imported files.
	 * Can be absolute or relative to ABSPATH. Ignored when used together with --skip-copy, as
	 * files are not moved on disk in that case.
	 *
	 * [--preserve-filetime]
	 * : Use the file modified time as the post published & modified dates.
	 * Remote files will always use the current time.
	 *
	 * [--featured_image]
	 * : If set, set the imported image as the Featured Image of the post it is attached to.
	 *
	 * [--porcelain[=<field>]]
	 * : Output a single field for each imported image. Defaults to attachment ID when used as flag.
	 * ---
	 * options:
	 *   - url
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Import all jpgs in the current user's "Pictures" directory, not attached to any post.
	 *     $ wp media import ~/Pictures/**\/*.jpg
	 *     Imported file '/home/person/Pictures/landscape-photo.jpg' as attachment ID 1751.
	 *     Imported file '/home/person/Pictures/fashion-icon.jpg' as attachment ID 1752.
	 *     Success: Imported 2 of 2 items.
	 *
	 *     # Import a local image and set it to be the post thumbnail for a post.
	 *     $ wp media import ~/Downloads/image.png --post_id=123 --title="A downloaded picture" --featured_image
	 *     Imported file '/home/person/Downloads/image.png' as attachment ID 1753 and attached to post 123 as featured image.
	 *     Success: Imported 1 of 1 images.
	 *
	 *     # Import a local image, but set it as the featured image for all posts.
	 *     # 1. Import the image and get its attachment ID.
	 *     # 2. Assign the attachment ID as the featured image for all posts.
	 *     $ ATTACHMENT_ID="$(wp media import ~/Downloads/image.png --porcelain)"
	 *     $ wp post list --post_type=post --format=ids | xargs -d ' ' -I % wp post meta add % _thumbnail_id $ATTACHMENT_ID
	 *     Success: Added custom field.
	 *     Success: Added custom field.
	 *
	 *     # Import an image from the web.
	 *     $ wp media import http://s.wordpress.org/style/images/wp-header-logo.png --title='The WordPress logo' --alt="Semantic personal publishing"
	 *     Imported file 'http://s.wordpress.org/style/images/wp-header-logo.png' as attachment ID 1755.
	 *     Success: Imported 1 of 1 images.
	 *
	 *     # Get the URL for an attachment after import.
	 *     $ wp media import http://s.wordpress.org/style/images/wp-header-logo.png --porcelain | xargs -I {} wp post list --post__in={} --field=url --post_type=attachment
	 *     http://wordpress-develop.dev/wp-header-logo/
	 *
	 * @param string[] $args Positional arguments.
	 * @param array{post_id?: string, post_name?: string, file_name?: string, title?: string, caption?: string, alt?: string, desc?: string, 'skip-copy'?: bool, 'destination-dir'?: string, 'preserve-filetime'?: bool, featured_image?: bool, porcelain?: bool|string} $assoc_args Associative arguments.
	 * @return void
	 */
	public function import( $args, $assoc_args = array() ) {
		$assoc_args = wp_parse_args(
			$assoc_args,
			array(
				'file_name'       => '',
				'title'           => '',
				'caption'         => '',
				'alt'             => '',
				'desc'            => '',
				'post_name'       => '',
				'destination-dir' => '',
			)
		);

		// Assume the most generic term
		$noun = 'item';

		// Current site's timezone offset.

		// @phpstan-ignore cast.double
		$gmt_offset = (float) get_option( 'gmt_offset' );

		// Use the noun `image` when sure the media file is an image
		if ( Utils\get_flag_value( $assoc_args, 'featured_image' ) || $assoc_args['alt'] ) {
			$noun = 'image';
		}

		$porcelain = Utils\get_flag_value( $assoc_args, 'porcelain' );
		if ( is_string( $porcelain ) && ! in_array( $porcelain, array( 'url' ), true ) ) {
			WP_CLI::error( sprintf( 'Invalid value for <porcelain>: %s. Expected flag or \'url\'.', $porcelain ) );
		}

		if ( isset( $assoc_args['post_id'] ) ) {
			if ( ! get_post( $assoc_args['post_id'] ) ) {
				WP_CLI::warning( 'Invalid --post_id' );
				$assoc_args['post_id'] = false;
			}
		} else {
			$assoc_args['post_id'] = false;
		}

		$destdir               = Utils\get_flag_value( $assoc_args, 'destination-dir' );
		$this->destination_dir = $destdir;
		$filter_upload_dir     = function ( $uploads ) {
			return $this->filter_upload_dir( $uploads );
		};

		$number    = 0;
		$successes = 0;
		$errors    = 0;
		foreach ( $args as $file ) {
			++$number;
			if ( 0 === $number % self::WP_CLEAR_OBJECT_CACHE_INTERVAL ) {
				// @phpstan-ignore function.deprecated
				Utils\wp_clear_object_cache();
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- parse_url will only be used in absence of wp_parse_url.
			$is_file_remote = function_exists( 'wp_parse_url' ) ? wp_parse_url( $file, PHP_URL_HOST ) : parse_url( $file, PHP_URL_HOST );
			$orig_filename  = $file;
			$file_time      = '';

			if ( empty( $is_file_remote ) ) {
				if ( ! file_exists( $file ) ) {
					WP_CLI::warning( "Unable to import file '$file'. Reason: File doesn't exist." );
					++$errors;
					continue;
				}
				if ( Utils\get_flag_value( $assoc_args, 'skip-copy' ) ) {
					$tempfile = $file;
				} else {
					$tempfile = $this->make_copy( $file );
				}
				$name = Utils\basename( $file );

				if ( Utils\get_flag_value( $assoc_args, 'preserve-filetime' ) ) {
					$file_time = @filemtime( $file );
				}
			} else {
				$tempfile = download_url( $file );
				if ( is_wp_error( $tempfile ) ) {
					WP_CLI::warning(
						sprintf(
							"Unable to import file '%s'. Reason: %s",
							$file,
							implode( ', ', $tempfile->get_error_messages() )
						)
					);
					++$errors;
					continue;
				}
				$name = (string) strtok( Utils\basename( $file ), '?' );
			}

			if ( ! empty( $assoc_args['file_name'] ) ) {
				$image_name = $this->get_image_name( $name, $assoc_args['file_name'] );
				$name       = ! empty( $image_name ) ? $image_name : $name;
			}

			$file_array = array(
				'tmp_name' => $tempfile,
				'name'     => $name,
			);

			$post_array = array(
				'post_title'   => $assoc_args['title'],
				'post_excerpt' => $assoc_args['caption'],
				'post_content' => $assoc_args['desc'],
				'post_name'    => $assoc_args['post_name'],
			);

			if ( ! empty( $file_time ) ) {
				$post_array['post_date']         = gmdate( 'Y-m-d H:i:s', (int) ( $file_time + ( $gmt_offset * HOUR_IN_SECONDS ) ) );
				$post_array['post_date_gmt']     = gmdate( 'Y-m-d H:i:s', $file_time );
				$post_array['post_modified']     = gmdate( 'Y-m-d H:i:s', (int) ( $file_time + ( $gmt_offset * HOUR_IN_SECONDS ) ) );
				$post_array['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s', $file_time );
			}

			$post_array = wp_slash( $post_array );

			// use image exif/iptc data for title and caption defaults if possible
			if ( empty( $post_array['post_title'] ) || empty( $post_array['post_excerpt'] ) ) {
				// @codingStandardsIgnoreStart
				$image_meta = @wp_read_image_metadata( $tempfile );
				// @codingStandardsIgnoreEnd
				if ( ! empty( $image_meta ) ) {
					if ( empty( $post_array['post_title'] ) && trim( $image_meta['title'] ) && ! is_numeric( sanitize_title( $image_meta['title'] ) ) ) {
						$post_array['post_title'] = $image_meta['title'];
					}

					if ( empty( $post_array['post_excerpt'] ) && trim( $image_meta['caption'] ) ) {
						$post_array['post_excerpt'] = $image_meta['caption'];
					}
				}
			}

			if ( empty( $post_array['post_title'] ) ) {
				$post_array['post_title'] = preg_replace( '/\.[^.]+$/', '', Utils\basename( $file ) );
			}

			if ( Utils\get_flag_value( $assoc_args, 'skip-copy' ) ) {
				$wp_filetype                  = wp_check_filetype( $file, null );
				$post_array['post_mime_type'] = $wp_filetype['type'];
				$post_array['post_status']    = 'inherit';

				$success = wp_insert_attachment( $post_array, $file, $assoc_args['post_id'] );
				if ( is_wp_error( $success ) ) {
					WP_CLI::warning(
						sprintf(
							"Unable to insert file '%s'. Reason: %s",
							$orig_filename,
							implode( ', ', $success->get_error_messages() )
						)
					);
					++$errors;
					continue;
				}
				wp_update_attachment_metadata( $success, wp_generate_attachment_metadata( $success, $file ) );
			} else {

				if ( ! empty( $destdir ) ) {
					add_filter( 'upload_dir', $filter_upload_dir, PHP_INT_MAX );
				}

				// Deletes the temporary file.

				$success = media_handle_sideload( $file_array, $assoc_args['post_id'], $assoc_args['title'], $post_array );
				if ( is_wp_error( $success ) ) {
					WP_CLI::warning(
						sprintf(
							"Unable to import file '%s'. Reason: %s",
							$orig_filename,
							implode( ', ', $success->get_error_messages() )
						)
					);
					++$errors;
					continue;
				}
			}

			// Set alt text
			if ( $assoc_args['alt'] ) {
				update_post_meta( $success, '_wp_attachment_image_alt', wp_slash( $assoc_args['alt'] ) );
			}

			// Set as featured image, if --post_id and --featured_image are set
			if ( $assoc_args['post_id'] && Utils\get_flag_value( $assoc_args, 'featured_image' ) ) {
				update_post_meta( $assoc_args['post_id'], '_thumbnail_id', $success );
			}

			$attachment_success_text = '';
			if ( $assoc_args['file_name'] ) {
				$attachment_success_text .= " with file name {$name}";
			}

			if ( $assoc_args['post_id'] ) {
				$attachment_success_text = " and attached to post {$assoc_args['post_id']}";
				if ( Utils\get_flag_value( $assoc_args, 'featured_image' ) ) {
					$attachment_success_text .= ' as featured image';
				}
			}

			if ( $porcelain ) {
				if ( 'url' === strtolower( $porcelain ) ) {
					$file_location = $this->get_real_attachment_url( $success );
					if ( $file_location ) {
						WP_CLI::line( $file_location );
					} else {
						// This should never happen.
						WP_CLI::error( 'Attachment URL not found' );
					}
				} else {
					WP_CLI::line( (string) $success );
				}
			} else {
				WP_CLI::log(
					sprintf(
						"Imported file '%s' as attachment ID %d%s.",
						$orig_filename,
						$success,
						$attachment_success_text
					)
				);
			}
			++$successes;
		}

		remove_filter( 'upload_dir', $filter_upload_dir, PHP_INT_MAX );

		// Report the result of the operation
		if ( ! Utils\get_flag_value( $assoc_args, 'porcelain' ) ) {
			Utils\report_batch_operation_results( $noun, 'import', count( $args ), $successes, $errors );
		} elseif ( $errors ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Replaces the file for an existing attachment while preserving its identity.
	 *
	 * ## OPTIONS
	 *
	 * <attachment-id>
	 * : ID of the attachment whose file is to be replaced.
	 *
	 * <file>
	 * : Path to the replacement file. Supports local paths and URLs.
	 *
	 * [--skip-delete]
	 * : Skip deletion of old thumbnail files after replacement.
	 *
	 * [--porcelain]
	 * : Output just the attachment ID after replacement.
	 *
	 * ## EXAMPLES
	 *
	 *     # Replace an attachment file with a local file.
	 *     $ wp media replace 123 ~/new-image.jpg
	 *     Replaced file for attachment ID 123 with '/home/user/new-image.jpg'.
	 *     Success: Replaced 1 of 1 images.
	 *
	 *     # Replace an attachment file with a file from a URL.
	 *     $ wp media replace 123 'http://example.com/image.jpg'
	 *     Replaced file for attachment ID 123 with 'http://example.com/image.jpg'.
	 *     Success: Replaced 1 of 1 images.
	 *
	 *     # Replace and output just the attachment ID.
	 *     $ wp media replace 123 ~/new-image.jpg --porcelain
	 *     123
	 *
	 * @param string[] $args Positional arguments.
	 * @param array{'skip-delete'?: bool, porcelain?: bool} $assoc_args Associative arguments.
	 * @return void
	 */
	public function replace( $args, $assoc_args = array() ) {
		$attachment_id = (int) $args[0];
		$file          = $args[1];

		// Validate attachment exists.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			WP_CLI::error( "Invalid attachment ID {$attachment_id}." );
		}

		// Handle remote vs local file (same pattern as import).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- parse_url will only be used in absence of wp_parse_url.
		$is_file_remote = function_exists( 'wp_parse_url' ) ? wp_parse_url( $file, PHP_URL_HOST ) : parse_url( $file, PHP_URL_HOST );
		$orig_filename  = $file;

		if ( empty( $is_file_remote ) ) {
			if ( ! file_exists( $file ) ) {
				WP_CLI::error( "Unable to replace attachment {$attachment_id} with file '{$file}'. Reason: File doesn't exist." );
			}
			$tempfile = $this->make_copy( $file );
			$name     = Utils\basename( $file );
		} else {
			$tempfile = download_url( $file );
			if ( is_wp_error( $tempfile ) ) {
				WP_CLI::error(
					sprintf(
						"Unable to replace attachment %d with file '%s'. Reason: %s",
						$attachment_id,
						$file,
						implode( ', ', $tempfile->get_error_messages() )
					)
				);
			}
			$name = (string) strtok( Utils\basename( $file ), '?' );
		}

		// Get old metadata before replacement for cleanup.
		$old_fullsizepath = $this->get_attached_file( $attachment_id );
		$old_metadata     = wp_get_attachment_metadata( $attachment_id );

		// Move the temp file into the uploads directory.
		$file_array = array(
			'name'     => $name,
			'tmp_name' => $tempfile,
		);

		$uploaded = wp_handle_sideload( $file_array, array( 'test_form' => false ) );

		if ( isset( $uploaded['error'] ) ) {
			if ( isset( $tempfile ) && is_string( $tempfile ) && file_exists( $tempfile ) ) {
				unlink( $tempfile );
			}
			WP_CLI::error( "Failed to process file '{$orig_filename}': {$uploaded['error']}" );
		}

		$new_file_path = $uploaded['file'];
		$new_mime_type = $uploaded['type'];

		// Delete old thumbnail files unless asked to skip.
		if ( ! Utils\get_flag_value( $assoc_args, 'skip-delete' )
			&& false !== $old_fullsizepath
			&& is_array( $old_metadata )
		) {
			$this->remove_old_images( $old_metadata, $old_fullsizepath, array() );
		}

		// Update the attachment's MIME type.
		$updated = wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => $new_mime_type,
			),
			true
		);
		if ( false === $updated || is_wp_error( $updated ) ) {
			$message = is_wp_error( $updated ) ? $updated->get_error_message() : 'Unknown error.';
			WP_CLI::warning(
				sprintf( 'Failed to update MIME type for attachment %d: %s', $attachment_id, $message )
			);
		}

		// Update the attached file path.
		update_attached_file( $attachment_id, $new_file_path );

		// Generate and update new attachment metadata.
		$new_metadata = wp_generate_attachment_metadata( $attachment_id, $new_file_path );
		wp_update_attachment_metadata( $attachment_id, $new_metadata );

		if ( Utils\get_flag_value( $assoc_args, 'porcelain' ) ) {
			WP_CLI::line( (string) $attachment_id );
		} else {
			WP_CLI::log(
				sprintf( "Replaced file for attachment ID %d with '%s'.", $attachment_id, $orig_filename )
			);
			Utils\report_batch_operation_results( 'image', 'replace', 1, 1, 0 );
		}
	}

	/**
	 * Lists image sizes registered with WordPress.
	 *
	 * ## OPTIONS
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields. Defaults to all fields.
	 *
	 * [--format=<format>]
	 * : Render output in a specific format
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## AVAILABLE FIELDS
	 *
	 * These fields will be displayed by default for each image size:
	 * * name
	 * * width
	 * * height
	 * * crop
	 * * ratio
	 *
	 * ## EXAMPLES
	 *
	 *     # List all registered image sizes
	 *     $ wp media image-size
	 *     +---------------------------+-------+--------+-------+-------+
	 *     | name                      | width | height | crop  | ratio |
	 *     +---------------------------+-------+--------+-------+-------+
	 *     | full                      |       |        | N/A   | N/A   |
	 *     | twentyfourteen-full-width | 1038  | 576    | hard  | 173:96|
	 *     | large                     | 1024  | 1024   | soft  | N/A   |
	 *     | medium_large              | 768   | 0      | soft  | N/A   |
	 *     | medium                    | 300   | 300    | soft  | N/A   |
	 *     | thumbnail                 | 150   | 150    | hard  | 1:1   |
	 *     +---------------------------+-------+--------+-------+-------+
	 *
	 * @subcommand image-size
	 *
	 * @param string[] $args Positional arguments. Unused.
	 * @param array{fields?: string, format: string} $assoc_args Associative arguments.
	 * @return void
	 */
	public function image_size( $args, $assoc_args ) {
		$assoc_args = array_merge(
			array(
				'fields' => 'name,width,height,crop,ratio',
			),
			$assoc_args
		);

		$sizes = $this->get_registered_image_sizes();

		usort(
			$sizes,
			function ( $a, $b ) {
				if ( $a['width'] === $b['width'] ) {
					return 0;
				}
				return ( $a['width'] < $b['width'] ) ? 1 : -1;
			}
		);
		array_unshift(
			$sizes,
			array(
				'name'   => 'full',
				'width'  => '',
				'height' => '',
				'crop'   => 'N/A',
				'ratio'  => 'N/A',
			)
		);
		WP_CLI\Utils\format_items( $assoc_args['format'], $sizes, explode( ',', $assoc_args['fields'] ) );
	}

	/**
	 * Get aspect ratio.
	 *
	 * @param int $width
	 * @param int $height
	 * @return string
	 */
	private function get_ratio( $width, $height ) {
		if ( 0 === $height ) {
			return "0:{$width}";
		}

		if ( 0 === $width ) {
			return "{$height}:0";
		}

		$gcd          = $this->gcd( $width, $height );
		$width_ratio  = $width / $gcd;
		$height_ratio = $height / $gcd;

		return "{$width_ratio}:{$height_ratio}";
	}

	/**
	 * Get the greatest common divisor.
	 *
	 * @param int $num1
	 * @param int $num2
	 * @return int
	 */
	private function gcd( $num1, $num2 ) {
		while ( 0 !== $num2 ) {
			$t    = $num1 % $num2;
			$num1 = $num2;
			$num2 = $t;
		}
		return $num1;
	}

	/**
	 * Make a temporary file copy.
	 *
	 * {@see wp_tempnam()} inexplicably forces a .tmp extension, which spoils MIME type detection.
	 *
	 * @param string $path
	 * @return string
	 */
	private function make_copy( $path ) {
		$dir      = get_temp_dir();
		$filename = Utils\basename( $path );
		if ( empty( $filename ) ) {
			$filename = (string) time();
		}

		$filename = $dir . wp_unique_filename( $dir, $filename );
		if ( ! copy( $path, $filename ) ) {
			WP_CLI::error( "Could not create temporary file for $path." );
		}

		return $filename;
	}

	/**
	 * Returns a human-readable description for one or more image size names.
	 *
	 * @param string[] $sizes           The size names.
	 * @param string   $noun            Noun in singular form (e.g. 'thumbnail'); pluralized automatically.
	 * @param string   $default_if_empty String to return when $sizes is empty.
	 * @return string
	 */
	private function get_image_sizes_description( array $sizes, $noun, $default_if_empty = '' ) {
		if ( empty( $sizes ) ) {
			return $default_if_empty;
		}
		return sprintf( '"%s" %s', implode( '", "', $sizes ), Utils\pluralize( $noun, count( $sizes ) ) );
	}

	/**
	 * Process media regeneration
	 *
	 * @param int $id Attachment ID.
	 * @param bool $skip_delete
	 * @param bool $only_missing
	 * @param bool $delete_unknown
	 * @param string[] $image_sizes
	 * @param string $progress
	 * @param int $successes
	 * @param int $errors
	 * @param int $skips
	 * @param-out int $successes
	 * @param-out int $errors
	 * @param-out int $skips
	 * @return void
	 */
	private function process_regeneration( $id, $skip_delete, $only_missing, $delete_unknown, $image_sizes, $progress, &$successes, &$errors, &$skips ) {

		$title = get_the_title( $id );
		if ( '' === $title ) {
			// If audio or video cover art then the id is the sub attachment id, which has no title.
			if ( metadata_exists( 'post', $id, '_cover_hash' ) ) {
				// Unfortunately the only way to get the attachment title would be to do a non-indexed query against the meta value of `_thumbnail_id`. So don't.
				$att_desc = sprintf( 'cover attachment (ID %d)', $id );
			} else {
				$att_desc = sprintf( '"(no title)" (ID %d)', $id );
			}
		} else {
			$att_desc = sprintf( '"%1$s" (ID %2$d)', $title, $id );
		}
		$thumbnail_desc = $this->get_image_sizes_description( $image_sizes, 'thumbnail', 'thumbnail' );

		$fullsizepath = $this->get_attached_file( $id );

		if ( false === $fullsizepath || ! file_exists( $fullsizepath ) ) {
			WP_CLI::warning( "Can't find $att_desc." );
			++$errors;
			return;
		}

		$is_pdf = 'application/pdf' === get_post_mime_type( $id );

		$original_meta = wp_get_attachment_metadata( $id );

		if ( $delete_unknown ) {
			$this->delete_unknown_image_sizes( $id, $fullsizepath );

			WP_CLI::log( "$progress Deleted unknown image sizes for $att_desc." );
			++$successes;
			return;
		}

		$needs_regeneration = $this->needs_regeneration( $id, $fullsizepath, $is_pdf, $image_sizes, $skip_delete, $skip_it );

		if ( $skip_it ) {
			WP_CLI::log( "$progress Skipped $thumbnail_desc regeneration for $att_desc." );
			++$skips;
			return;
		}

		if ( $only_missing && ! $needs_regeneration ) {
			WP_CLI::log( "$progress No $thumbnail_desc regeneration needed for $att_desc." );
			++$successes;
			return;
		}
		$site_icon_filter = $this->add_site_icon_filter( $id );

		// On WP 5.3+, for the --only-missing case (no specific image sizes, not a PDF, and not a
		// site-icon attachment), prefer wp_update_image_subsizes() which only generates sub-sizes
		// that are absent from the attachment metadata and saves metadata incrementally after each
		// sub-size, so partial progress is preserved if the server runs out of resources.
		$can_use_wp53_subsizes = $only_missing && ! $image_sizes && ! $is_pdf && ! $site_icon_filter
			&& function_exists( 'wp_get_missing_image_subsizes' ) && function_exists( 'wp_update_image_subsizes' );
		if ( $can_use_wp53_subsizes ) {
			$missing_sizes = wp_get_missing_image_subsizes( $id );
			if ( ! empty( $missing_sizes ) ) {
				$result = wp_update_image_subsizes( $id );
				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf( '%s (ID %d)', $result->get_error_message(), $id ) );
					WP_CLI::log( "$progress Couldn't regenerate thumbnails for $att_desc." );
					++$errors;
					return;
				}
				WP_CLI::log( "$progress Regenerated thumbnails for $att_desc." );
				++$successes;
				return;
			}
			// $missing_sizes is empty but needs_regeneration() returned true, which means some
			// thumbnail files are physically missing from disk even though the metadata is intact.
			// Fall through to the wp_generate_attachment_metadata() path below.
		}

		// When regenerating specific image size(s), use the file that WordPress normally
		// serves (the scaled version for big images), not the original pre-scaled file.
		// This prevents wp_generate_attachment_metadata() from re-creating the scaled
		// version or auto-rotating the original during specific-size regeneration.
		$generate_file = $fullsizepath;
		if ( $image_sizes && ! $is_pdf ) {
			$wp_attached_file = \get_attached_file( $id );
			if ( $wp_attached_file && file_exists( $wp_attached_file ) ) {
				$generate_file = $wp_attached_file;
			}
		}

		$metadata = wp_generate_attachment_metadata( $id, $generate_file );

		if ( $site_icon_filter ) {
			remove_filter( 'intermediate_image_sizes_advanced', $site_icon_filter );
		}

		// Note it's possible for no metadata to be generated for PDFs if restricted to specific image size(s).
		if ( empty( $metadata ) && ! ( $is_pdf && $image_sizes ) ) {
			WP_CLI::warning( sprintf( 'No metadata. (ID %d)', $id ) );
			WP_CLI::log( "$progress Couldn't regenerate thumbnails for $att_desc." );
			++$errors;
			return;
		}

		// On read error, we might only get the filesize returned and nothing else.
		if ( 1 === count( $metadata ) && array_key_exists( 'filesize', $metadata ) && ! ( $is_pdf && $image_sizes ) ) {
			WP_CLI::warning( sprintf( 'Read error while retrieving metadata. (ID %d)', $id ) );
			WP_CLI::log( "$progress Couldn't regenerate thumbnails for $att_desc." );
			++$errors;
			return;
		}

		if ( $image_sizes ) {
			$regenerated_sizes = $this->update_attachment_metadata_for_image_size( $id, $metadata, $image_sizes, $original_meta );
			if ( $regenerated_sizes ) {
				WP_CLI::log( "$progress Regenerated {$this->get_image_sizes_description( $regenerated_sizes, 'thumbnail' )} for $att_desc." );
			} else {
				WP_CLI::log( "$progress No $thumbnail_desc regeneration needed for $att_desc." );
			}
		} else {
			wp_update_attachment_metadata( $id, $metadata );

			WP_CLI::log( "$progress Regenerated thumbnails for $att_desc." );
		}
		++$successes;
	}

	/**
	 * Removes old images.
	 *
	 * @param array $metadata
	 * @param string $fullsizepath
	 * @param string[] $image_sizes
	 * @return void
	 */
	private function remove_old_images( $metadata, $fullsizepath, $image_sizes ) {

		if ( empty( $metadata['sizes'] ) ) {
			return;
		}

		if ( $image_sizes ) {
			$metadata['sizes'] = array_intersect_key( $metadata['sizes'], array_flip( $image_sizes ) );
			if ( empty( $metadata['sizes'] ) ) {
				return;
			}
		}

		$dir_path = dirname( $fullsizepath ) . '/';

		foreach ( $metadata['sizes'] as $size_info ) {
			$intermediate_path = $dir_path . $size_info['file'];

			if ( $intermediate_path === $fullsizepath ) {
				continue;
			}

			if ( file_exists( $intermediate_path ) ) {
				unlink( $intermediate_path );
			}
		}
	}

	/**
	 * Whether the attachment needs regeneration.
	 *
	 * @param int $att_id
	 * @param string $fullsizepath
	 * @param bool $is_pdf
	 * @param string[] $image_sizes
	 * @param bool $skip_delete
	 * @param bool $skip_it
	 * @param-out bool $skip_it
	 * @return bool
	 */
	private function needs_regeneration( $att_id, $fullsizepath, $is_pdf, $image_sizes, $skip_delete, &$skip_it ) {

		// Assume not skipping.
		$skip_it = false;

		// Note: zero-length string returned if no metadata, for instance if PDF or non-standard image (eg an SVG).
		$metadata = wp_get_attachment_metadata( $att_id );

		$attachment_sizes = $this->get_intermediate_image_sizes_for_attachment( $fullsizepath, $is_pdf, $metadata, $att_id );

		// First check if no applicable editor currently available (non-destructive - ie old thumbnails not removed).
		if ( is_wp_error( $attachment_sizes ) && 'image_no_editor' === $attachment_sizes->get_error_code() ) {
			// Warn unless PDF or non-standard image.
			if ( ! $is_pdf && is_array( $metadata ) && ! empty( $metadata['sizes'] ) ) {
				WP_CLI::warning( sprintf( '%s (ID %d)', $attachment_sizes->get_error_message(), $att_id ) );
			}
			$skip_it = true;
			return false;
		}

		// If uploaded when applicable image editor such as Imagick unavailable, the metadata or sizes metadata may not exist.
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}
		// If set `$metadata['sizes']` should be array but explicitly check as following code depends on it.
		if ( ! isset( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			$metadata['sizes'] = array();
		}

		// Remove any old thumbnails (so now destructive).
		if ( ! $skip_delete ) {
			$this->remove_old_images( $metadata, $fullsizepath, $image_sizes );
		}

		// Check for any other error (such as load error) apart from no editor available.
		if ( is_wp_error( $attachment_sizes ) ) {
			// Warn but assume it may be possible to regenerate and allow processing to continue and possibly fail.
			WP_CLI::warning( sprintf( '%s (ID %d)', $attachment_sizes->get_error_message(), $att_id ) );
			return true;
		}

		// Have sizes - check whether they're new ones or they've changed. Note that an attachment can have no sizes if it's on or below the thumbnail threshold.

		if ( $image_sizes ) {
			// Filter to only the requested sizes that are applicable to this attachment.
			$filtered_sizes = array_intersect_key( $attachment_sizes, array_flip( $image_sizes ) );

			if ( empty( $filtered_sizes ) ) {
				return false;
			}

			// Check if any applicable requested size is missing from metadata.
			foreach ( array_keys( $filtered_sizes ) as $size ) {
				if ( empty( $metadata['sizes'][ $size ] ) ) {
					return true;
				}
			}

			/**
			 * @var array{sizes: array<string, array<string, mixed>>} $metadata
			 */

			// Filter metadata and attachment_sizes to only the applicable requested sizes.
			$metadata['sizes'] = array_intersect_key( $metadata['sizes'], $filtered_sizes );
			$attachment_sizes  = $filtered_sizes;
		}

		if ( $this->image_sizes_differ( $attachment_sizes, $metadata['sizes'] ) ) {
			return true;
		}

		$dir_path = dirname( $fullsizepath ) . '/';

		// Check that the thumbnail files exist.

		/**
		 * @var array{file: string} $size_info
		 */
		foreach ( $metadata['sizes'] as $size_info ) {
			$intermediate_path = $dir_path . $size_info['file'];

			if ( $intermediate_path === $fullsizepath ) {
				continue;
			}

			if ( ! file_exists( $intermediate_path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether there's new image sizes or the width/height of existing image sizes have changed.
	 *
	 * @param array<string, array> $image_sizes
	 * @param array<string, array> $meta_sizes
	 * @return bool
	 */
	private function image_sizes_differ( $image_sizes, $meta_sizes ) {
		// Check if have new image size(s).
		if ( array_diff( array_keys( $image_sizes ), array_keys( $meta_sizes ) ) ) {
			return true;
		}
		// Check if image sizes have changed.
		foreach ( $image_sizes as $name => $image_size ) {
			if ( $image_size['width'] !== $meta_sizes[ $name ]['width'] || $image_size['height'] !== $meta_sizes[ $name ]['height'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns image sizes for a given attachment.
	 *
	 * Like WP's get_intermediate_image_sizes(), but removes sizes that won't be generated for a particular attachment due to it being on or below their thresholds,
	 * and returns associative array with size name => width/height entries, resolved to crop values if applicable.
	 *
	 * @param string                     $fullsizepath Filepath of the attachment
	 * @param bool                       $is_pdf       Whether it is a PDF.
	 * @param array<string, mixed>|false $metadata     Attachment metadata.
	 * @param int                        $att_id       Attachment ID.
	 *
	 * @return array|WP_Error Image sizes on success, WP_Error instance otherwise.
	 */
	private function get_intermediate_image_sizes_for_attachment( $fullsizepath, $is_pdf, $metadata, $att_id ) {

		// Need to get width, height of attachment for image_resize_dimensions().
		$editor = wp_get_image_editor( $fullsizepath );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$result = $editor->load();
		if ( is_wp_error( $result ) ) {
			unset( $editor );
			return $result;
		}
		list( $width, $height ) = array_values( $editor->get_size() );
		unset( $editor );

		$sizes = array();
		foreach ( $this->get_intermediate_sizes( $is_pdf, $metadata, $att_id ) as $name => $size ) {
			// Need to check destination and original width or height differ before calling image_resize_dimensions(), otherwise it will return non-false.
			$dims = image_resize_dimensions( $width, $height, $size['width'], $size['height'], $size['crop'] );
			if ( ( $width !== $size['width'] || $height !== $size['height'] ) && $dims ) {
				list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;
				$sizes[ $name ] = array(
					'width'  => $dst_w,
					'height' => $dst_h,
				);
			}
		}
		return $sizes;
	}

	/**
	 * Like WP's get_intermediate_image_sizes(), but returns associative array with name => size info entries (and caters for PDFs also).
	 *
	 * @param bool $is_pdf
	 * @param array<string, mixed>|false $metadata
	 * @param int $att_id
	 * @return array<string, array{width: int, height: int, crop: bool}>
	 */
	private function get_intermediate_sizes( $is_pdf, $metadata, $att_id ) {
		if ( $is_pdf ) {
			// Copied from wp_generate_attachment_metadata() in "wp-admin/includes/image.php".
			$fallback_sizes = array(
				'thumbnail',
				'medium',
				'large',
			);
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Calling native WordPress hook.
			$intermediate_image_sizes = apply_filters( 'fallback_intermediate_image_sizes', $fallback_sizes, $metadata );
		} else {
			$intermediate_image_sizes = get_intermediate_image_sizes();
		}

		// Adapted from wp_generate_attachment_metadata() in "wp-admin/includes/image.php".

		if ( function_exists( 'wp_get_additional_image_sizes' ) ) {
			$_wp_additional_image_sizes = wp_get_additional_image_sizes();
		} else {
			// For WP < 4.7.0.
			global $_wp_additional_image_sizes;
			if ( ! $_wp_additional_image_sizes ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Used as a fallback for WordPress version less than 4.7.0 as function wp_get_additional_image_sizes didn't exist then.
				$_wp_additional_image_sizes = array();
			}
		}

		$sizes = array();
		foreach ( $intermediate_image_sizes as $s ) {
			if ( isset( $_wp_additional_image_sizes[ $s ]['width'] ) ) {
				$sizes[ $s ]['width'] = (int) $_wp_additional_image_sizes[ $s ]['width'];
			} else {
				// @phpstan-ignore cast.int
				$sizes[ $s ]['width'] = (int) get_option( "{$s}_size_w" );
			}

			if ( isset( $_wp_additional_image_sizes[ $s ]['height'] ) ) {
				$sizes[ $s ]['height'] = (int) $_wp_additional_image_sizes[ $s ]['height'];
			} else {
				// @phpstan-ignore cast.int
				$sizes[ $s ]['height'] = (int) get_option( "{$s}_size_h" );
			}

			if ( isset( $_wp_additional_image_sizes[ $s ]['crop'] ) ) {
				$sizes[ $s ]['crop'] = (bool) $_wp_additional_image_sizes[ $s ]['crop'];
				// Force PDF thumbnails to be soft crops.
			} elseif ( $is_pdf && 'thumbnail' === $s ) {
				$sizes[ $s ]['crop'] = false;
			} else {
				$sizes[ $s ]['crop'] = (bool) get_option( "{$s}_crop" );
			}
		}

		// Check here that not PDF (as filter not applied in core if is) and `$metadata` is array (as may not be and filter only applied in core when is).
		if ( ! $is_pdf && is_array( $metadata ) ) {
			if ( Utils\wp_version_compare( '5.3', '>=' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Calling native WordPress hook.
				$sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes, $metadata, $att_id );
			} else {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Calling native WordPress hook.
				$sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes, $metadata );
			}
		}

		return $sizes;
	}

	/**
	 * Add filters to only process particular intermediate image sizes in wp_generate_attachment_metadata().
	 *
	 * @param string[] $image_sizes
	 * @return array<string, callable>
	 */
	private function add_image_size_filters( $image_sizes ) {
		$image_size_filters = array();

		// For images.
		$image_size_filters['intermediate_image_sizes_advanced'] = function ( $sizes ) use ( $image_sizes ) {
			// $sizes is associative array of name => size info entries.
			return array_intersect_key( $sizes, array_flip( $image_sizes ) );
		};

		// For PDF previews.
		$image_size_filters['fallback_intermediate_image_sizes'] = function ( $fallback_sizes ) use ( $image_sizes ) {
			// $fallback_sizes is indexed array of size names.
			return array_values( array_intersect( $fallback_sizes, $image_sizes ) );
		};

		foreach ( $image_size_filters as $name => $filter ) {
			add_filter( $name, $filter, PHP_INT_MAX );
		}

		return $image_size_filters;
	}

	/**
	 * Remove above intermediate image size filters.
	 *
	 * @param array<string, callable> $image_size_filters
	 * @return void
	 */
	private function remove_image_size_filters( $image_size_filters ) {
		foreach ( $image_size_filters as $name => $filter ) {
			remove_filter( $name, $filter, PHP_INT_MAX );
		}
	}

	/**
	 * Adds the WP_Site_Icon filter for site-icon attachments.
	 *
	 * @param int $id Attachment ID.
	 * @return callable|null The filter callback if added, null otherwise.
	 */
	private function add_site_icon_filter( $id ) {
		if ( 'site-icon' !== get_post_meta( $id, '_wp_attachment_context', true ) ) {
			return null;
		}

		if ( ! class_exists( 'WP_Site_Icon' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-icon.php';
		}

		$wp_site_icon = new WP_Site_Icon();
		$filter       = array( $wp_site_icon, 'additional_sizes' );
		add_filter( 'intermediate_image_sizes_advanced', $filter );

		return $filter;
	}

	/**
	 * Filters the uploads directory.
	 *
	 * @param array{path: string, url: string, subdir: string, basedir: string, baseurl: string, error: string|false} $uploads
	 * @return array{path: string, url: string, subdir: string, basedir: string, baseurl: string, error: string|false}
	 */
	private function filter_upload_dir( $uploads ) {
		if ( ! $this->destination_dir ) {
			return $uploads;
		}

		$upload_dir = $this->destination_dir;

		if ( 0 !== strpos( $this->destination_dir, ABSPATH ) ) {
			// $dir is absolute, $upload_dir is (maybe) relative to ABSPATH.
			$dir = path_join( ABSPATH, $this->destination_dir );
		} else {
			$dir = $this->destination_dir;
			// normalize $upload_dir.
			$upload_dir = substr( $this->destination_dir, strlen( ABSPATH ) );
		}

		// @phpstan-ignore cast.string
		$siteurl = (string) get_option( 'siteurl' );
		$url     = trailingslashit( $siteurl ) . $upload_dir;

		return [
			'path'    => $this->destination_dir,
			'url'     => $url,
			'subdir'  => '',
			'basedir' => $this->destination_dir,
			'baseurl' => $url,
			'error'   => false,
		];
	}

	/**
	 * Update attachment sizes metadata just for particular intermediate image sizes.
	 *
	 * @param int $id
	 * @param array $new_metadata
	 * @param string[] $image_sizes
	 * @param array{sizes: array<string, mixed>}|false $metadata
	 * @return string[] The sizes that were actually regenerated.
	 */
	private function update_attachment_metadata_for_image_size( $id, $new_metadata, $image_sizes, $metadata ) {

		if ( ! is_array( $metadata ) ) {
			return array();
		}

		$regenerated_sizes = array();
		$changed           = false;

		foreach ( $image_sizes as $image_size ) {
			// If have metadata for image_size.
			if ( ! empty( $new_metadata['sizes'][ $image_size ] ) ) {
				$metadata['sizes'][ $image_size ] = $new_metadata['sizes'][ $image_size ];
				$regenerated_sizes[]              = $image_size;
				$changed                          = true;
			} elseif ( ! empty( $metadata['sizes'][ $image_size ] ) ) {
				// Else remove unused metadata if any.
				unset( $metadata['sizes'][ $image_size ] );
				$changed = true;
				// Treat removing unused metadata as no change (don't add to $regenerated_sizes).
			}
		}

		if ( $changed ) {
			wp_update_attachment_metadata( $id, $metadata );
		}
		return $regenerated_sizes;
	}

	/**
	 * Get images from the installation.
	 *
	 * @param array $args                  The query arguments to use. Optional.
	 * @param array $additional_mime_types The additional mime types to search for. Optional.
	 *
	 * @return WP_Query The query result.
	 */
	private function get_images( $args = array(), $additional_mime_types = array() ) {
		$mime_types = array_merge( array( 'image' ), $additional_mime_types );

		$query_args = array(
			'post_type'              => 'attachment',
			'post__in'               => $args,
			'post_mime_type'         => $mime_types,
			'post_status'            => 'any',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		return new WP_Query( $query_args );
	}

	/**
	 * Get all the registered image sizes along with their dimensions.
	 *
	 * @return array $image_sizes The image sizes
	 */
	private function get_registered_image_sizes() {
		$image_sizes = array();

		$all_sizes = $this->wp_get_registered_image_subsizes();

		foreach ( $all_sizes as $size => $size_args ) {
			$crop = filter_var( $size_args['crop'], FILTER_VALIDATE_BOOLEAN );

			$image_sizes[] = array(
				'name'   => $size,
				'width'  => $size_args['width'],
				'height' => $size_args['height'],
				'crop'   => empty( $crop ) || is_array( $size_args['crop'] ) ? 'soft' : 'hard',
				'ratio'  => empty( $crop ) || is_array( $size_args['crop'] ) ? 'N/A' : $this->get_ratio( $size_args['width'], $size_args['height'] ),
			);
		}

		return $image_sizes;
	}

	/**
	* Returns a normalized list of all currently registered image sub-sizes.
	*
	* If exists, uses output of wp_get_registered_image_subsizes() function (introduced in WP 5.3).
	* Definition of this method is modified version of core function wp_get_registered_image_subsizes().
	*
	* @global array $_wp_additional_image_sizes
	*
	* @return array[] Associative array of arrays of image sub-size information, keyed by image size name.
	*/
	private function wp_get_registered_image_subsizes() {
		if ( Utils\wp_version_compare( '5.3', '>=' ) ) {
			return wp_get_registered_image_subsizes();
		}

		global $_wp_additional_image_sizes;

		$additional_sizes = $_wp_additional_image_sizes ? $_wp_additional_image_sizes : array();

		$all_sizes = array();

		foreach ( get_intermediate_image_sizes() as $size_name ) {
			$size_data = array(
				'width'  => 0,
				'height' => 0,
				'crop'   => false,
			);

			if ( isset( $additional_sizes[ $size_name ]['width'] ) ) {
				// For sizes added by plugins and themes.
				$size_data['width'] = (int) $additional_sizes[ $size_name ]['width'];
			} else {
				// For default sizes set in options.
				// @phpstan-ignore cast.int
				$size_data['width'] = (int) get_option( "{$size_name}_size_w" );
			}

			if ( isset( $additional_sizes[ $size_name ]['height'] ) ) {
				$size_data['height'] = (int) $additional_sizes[ $size_name ]['height'];
			} else {
				// @phpstan-ignore cast.int
				$size_data['height'] = (int) get_option( "{$size_name}_size_h" );
			}

			if ( empty( $size_data['width'] ) && empty( $size_data['height'] ) ) {
				// This size isn't set.
				continue;
			}

			if ( isset( $additional_sizes[ $size_name ]['crop'] ) ) {
				$size_data['crop'] = $additional_sizes[ $size_name ]['crop'];
			} else {
				$size_data['crop'] = get_option( "{$size_name}_crop" );
			}

			if ( ! is_array( $size_data['crop'] ) || empty( $size_data['crop'] ) ) {
				$size_data['crop'] = (bool) $size_data['crop'];
			}

			$all_sizes[ $size_name ] = $size_data;
		}

		return $all_sizes;
	}

	/**
	 * Fix image orientation for one or more attachments.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment-id>...]
	 * : One or more IDs of the attachments to regenerate.
	 *
	 * [--dry-run]
	 * : Check images needing orientation without performing the operation.
	 *
	 * ## EXAMPLES
	 *
	 *     # Fix orientation for all images.
	 *     $ wp media fix-orientation
	 *     1/3 Fixing orientation for "Landscape_4" (ID 62).
	 *     2/3 Fixing orientation for "Landscape_3" (ID 61).
	 *     3/3 Fixing orientation for "Landscape_2" (ID 60).
	 *     Success: Fixed 3 of 3 images.
	 *
	 *     # Fix orientation dry run.
	 *     $ wp media fix-orientation 63 --dry-run
	 *     1/1 "Portrait_6" (ID 63) will be affected.
	 *     Success: 1 of 1 image will be affected.
	 *
	 *     # Fix orientation for specific images.
	 *     $ wp media fix-orientation 63
	 *     1/1 Fixing orientation for "Portrait_6" (ID 63).
	 *     Success: Fixed 1 of 1 images.
	 *
	 * @subcommand fix-orientation
	 *
	 * @param string[] $args Positional arguments.
	 * @param array{'dry-run'?: bool} $assoc_args Associative arguments.
	 * @return void
	 */
	public function fix_orientation( $args, $assoc_args ) {

		// EXIF is required to read image metadata for orientation.
		if ( ! extension_loaded( 'exif' ) ) {
			WP_CLI::error( "'EXIF' extension is not loaded, it is required for this operation." );
		} elseif ( ! function_exists( 'exif_read_data' ) ) {
			WP_CLI::error( "Function 'exif_read_data' does not exist, it is required for this operation." );
		}

		$images  = $this->get_images( $args );
		$count   = $images->post_count;
		$dry_run = Utils\get_flag_value( $assoc_args, 'dry-run' );

		if ( ! $count ) {
			WP_CLI::error( 'No images found.' );
		}

		$number    = 0;
		$successes = 0;
		$errors    = 0;

		/**
		 * @var int $post_id
		 */
		foreach ( $images->posts as $post_id ) {
			++$number;
			if ( 0 === $number % self::WP_CLEAR_OBJECT_CACHE_INTERVAL ) {
				// @phpstan-ignore function.deprecated
				Utils\wp_clear_object_cache();
			}
			$this->process_orientation_fix( $post_id, "{$number}/{$count}", $successes, $errors, $dry_run );
		}

		if ( Utils\get_flag_value( $assoc_args, 'dry-run' ) ) {
			WP_CLI::success( sprintf( '%s of %s %s will be affected.', $successes, $count, Utils\pluralize( 'image', $count ) ) );
		} else {
			Utils\report_batch_operation_results( 'image', 'fix', $count, $successes, $errors );
		}
	}

	/**
	 * Perform orientation fix on attachments.
	 *
	 * @param int    $id        Attachment Id.
	 * @param string $progress  Current progress string.
	 * @param int    $successes Count of success in current operation.
	 * @param int    $errors    Count of errors in current operation.
	 * @param bool   $dry_run   Is this a dry run?
	 * @return void
	 */
	private function process_orientation_fix( $id, $progress, &$successes, &$errors, $dry_run ) {
		$title = get_the_title( $id );
		if ( '' === $title ) {
			// If audio or video cover art then the id is the sub attachment id, which has no title.
			if ( metadata_exists( 'post', $id, '_cover_hash' ) ) {
				// Unfortunately the only way to get the attachment title would be to do a non-indexed query against the meta value of `_thumbnail_id`. So don't.
				$att_desc = sprintf( 'cover attachment (ID %d)', $id );
			} else {
				$att_desc = sprintf( '"(no title)" (ID %d)', $id );
			}
		} else {
			$att_desc = sprintf( '"%1$s" (ID %2$d)', $title, $id );
		}

		$full_size_path = $this->get_attached_file( $id );

		if ( false === $full_size_path || ! file_exists( $full_size_path ) ) {
			WP_CLI::warning( "Can't find {$att_desc}." );
			++$errors;
			return;
		}

		// Get current metadata of the attachment from the database.
		$metadata   = wp_get_attachment_metadata( $id );
		$image_meta = is_array( $metadata ) && ! empty( $metadata['image_meta'] ) ? $metadata['image_meta'] : [];

		// Determine orientation from DB metadata first.
		$orientation = isset( $image_meta['orientation'] ) ? absint( $image_meta['orientation'] ) : 0;

		if ( $orientation > 1 ) {
			// DB shows orientation > 1, but WP 5.3+ may have already auto-rotated the image
			// on import (via wp_maybe_exif_rotate()), storing the original EXIF value before
			// rotating. On WP < 5.3 this behavior does not occur, so skip the extra EXIF read.
			if ( Utils\wp_version_compare( '5.3', '>=' ) ) {
				// Verify against the file's current EXIF: if it is <= 1 the image is already
				// correctly oriented and no fix is needed.
				$file_image_meta = wp_read_image_metadata( $full_size_path );
				if ( is_array( $file_image_meta ) && isset( $file_image_meta['orientation'] ) ) {
					$raw_orientation  = $file_image_meta['orientation'];
					$file_orientation = is_scalar( $raw_orientation ) ? absint( $raw_orientation ) : 0;
					if ( $file_orientation <= 1 ) {
						$orientation = $file_orientation;
					}
				}
			}
		} elseif ( empty( $image_meta ) || ! isset( $image_meta['orientation'] ) ) {
			// DB has no orientation data at all (stale/absent metadata). Fall back to reading
			// from the file's EXIF so the command still works for such attachments.
			$file_image_meta = wp_read_image_metadata( $full_size_path );
			if ( is_array( $file_image_meta ) && isset( $file_image_meta['orientation'] ) ) {
				$raw_orientation  = $file_image_meta['orientation'];
				$file_orientation = is_scalar( $raw_orientation ) ? absint( $raw_orientation ) : 0;
				if ( $file_orientation > 1 ) {
					// Merge file-based metadata so flip_rotate_image() has the orientation.
					$image_meta  = array_merge( $image_meta, $file_image_meta );
					$orientation = $file_orientation;
				}
			}
		}

		if ( $orientation > 1 ) {
			if ( ! $dry_run ) {
				WP_CLI::log( "{$progress} Fixing orientation for {$att_desc}." );
				if ( false !== $this->flip_rotate_image( $id, $image_meta, $full_size_path ) ) {
					++$successes;
				} else {
					++$errors;
					WP_CLI::log( "Couldn't fix orientation for {$att_desc}." );
				}
			} else {
				WP_CLI::log( "{$progress} {$att_desc} will be affected." );
				++$successes;
			}
		} else {
			WP_CLI::log( "{$progress} No orientation fix required for {$att_desc}." );
		}
	}

	/**
	 * Perform image rotate operations on the image.
	 *
	 * @param int    $id             Attachment Id.
	 * @param array  $image_meta     `image_meta` information for the attachment.
	 * @param string $full_size_path Path to original image.
	 *
	 * @return bool Whether the image rotation operation succeeded.
	 */
	private function flip_rotate_image( $id, $image_meta, $full_size_path ) {
		$editor = wp_get_image_editor( $full_size_path );

		if ( ! is_wp_error( $editor ) ) {
			$operations = $this->calculate_transformation( (int) $image_meta['orientation'] );

			// Rotate image if required.
			if ( true === $operations['rotate'] ) {
				$editor->rotate( $operations['degree'] );
			}

			// Flip image if required.
			if ( false !== $operations['flip'] ) {
				$editor->flip( $operations['flip'][0], $operations['flip'][1] );
			}

			$saved = $editor->save( $full_size_path );

			if ( is_wp_error( $saved ) ) {
				return false;
			}

			// Regenerate attachment metadata after the corrected image is saved.
			$metadata = wp_generate_attachment_metadata( $id, $full_size_path );

			if ( empty( $metadata ) ) {
				return false;
			}

			// Normalize the stored orientation to prevent re-detection on subsequent runs.
			// WP_Image_Editor_Imagick::flip() does not reset the EXIF orientation tag in the
			// file, so the file may still report a non-normal orientation even though the pixels
			// have been corrected. Forcing orientation to 0 in the stored metadata ensures the
			// next run reports "No orientation fix required".
			if ( isset( $metadata['image_meta']['orientation'] ) ) {
				$metadata['image_meta']['orientation'] = 0;
			}

			wp_update_attachment_metadata( $id, $metadata );

			return true;
		}

		return false;
	}

	/**
	 * Return array of operations to be done for provided orientation value.
	 *
	 * @param int $orientation EXIF orientation value.
	 *
	 * @return array
	 */
	private function calculate_transformation( $orientation ) {
		$rotate = false;
		$flip   = false;
		$degree = 0;
		switch ( $orientation ) {
			case 2:
				$flip = [ false, true ]; // $flip image along given axis [ horizontal, vertical ]
				break;
			case 3:
				$flip = [ true, true ];
				break;
			case 4:
				$flip = [ true, false ];
				break;
			case 5:
				$degree = -90;
				$rotate = true;
				$flip   = [ false, true ];
				break;
			case 6:
				$degree = -90;
				$rotate = true;
				break;
			case 7:
				$degree = 90;
				$rotate = true;
				$flip   = [ false, true ];
				break;
			case 8:
				$degree = 90;
				$rotate = true;
				break;
			default:
				$degree = 0;
				$rotate = true;
				break;
		}

		return [
			'flip'   => $flip,
			'degree' => $degree,
			'rotate' => $rotate,
		];
	}

	/**
	 * Add compatibility indirection to get_attached_file().
	 *
	 * In WordPress 5.3, behavior changed to account for automatic resizing of
	 * big image files.
	 *
	 * @see https://core.trac.wordpress.org/ticket/47873
	 *
	 * @param int $attachment_id ID of the attachment to get the filepath for.
	 * @return string|false Filepath of the attachment, or false if not found.
	 */
	private function get_attached_file( $attachment_id ) {
		// If the image has been edited by the user, use the edited file (tracked
		// via _wp_attachment_backup_sizes) rather than the original pre-scaled image.
		$backup_sizes = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		if ( empty( $backup_sizes ) && function_exists( 'wp_get_original_image_path' ) ) {
			$filepath = wp_get_original_image_path( $attachment_id );

			if ( false !== $filepath ) {
				return $filepath;
			}
		}

		return get_attached_file( $attachment_id );
	}

	/**
	 * Image-friendly alternative to wp_get_attachment_url(). Will return the full size URL of an image instead of the `-scaled` version.
	 *
	 * In WordPress 5.3, behavior changed to account for automatic resizing of
	 * big image files.
	 *
	 * @see https://core.trac.wordpress.org/ticket/47873
	 *
	 * @param int $attachment_id ID of the attachment to get the URL for.
	 * @return string|false URL of the attachment, or false if not found.
	 */
	private function get_real_attachment_url( $attachment_id ) {
		if ( function_exists( 'wp_get_original_image_url' ) ) {
			$url = wp_get_original_image_url( $attachment_id );

			if ( false !== $url ) {
				return $url;
			}
		}

		return wp_get_attachment_url( $attachment_id );
	}

	/**
	 * Create image slug based on user input slug.
	 * Add basename extension to slug.
	 *
	 * @param string $basename Default slu of image.
	 * @param string $slug User input slug.
	 *
	 * @return string Image slug with extension.
	 */
	private function get_image_name( $basename, $slug ) {

		$extension = pathinfo( $basename, PATHINFO_EXTENSION );

		return $slug . '.' . $extension;
	}

	/**
	 * Removes files for unknown/unregistered image sizes.
	 *
	 * Similar to {@see self::remove_old_images} but also updates metadata afterwards.
	 *
	 * @param int    $id           Attachment ID.
	 * @param string $fullsizepath Filepath of the attachment.
	 *
	 * @return void
	 */
	private function delete_unknown_image_sizes( $id, $fullsizepath ) {
		$original_meta = wp_get_attachment_metadata( $id );

		$image_sizes = wp_list_pluck( $this->get_registered_image_sizes(), 'name' );

		$dir_path = dirname( $fullsizepath ) . '/';

		$sizes_to_delete = array();

		if ( isset( $original_meta['sizes'] ) ) {
			foreach ( $original_meta['sizes'] as $size_name => $size_meta ) {
				if ( 'full' === $size_name ) {
					continue;
				}

				if ( ! in_array( $size_name, $image_sizes, true ) ) {
					$intermediate_path = $dir_path . $size_meta['file'];
					if ( $intermediate_path === $fullsizepath ) {
						continue;
					}

					if ( file_exists( $intermediate_path ) ) {
						unlink( $intermediate_path );
					}

					$sizes_to_delete[] = $size_name;
				}
			}

			foreach ( $sizes_to_delete as $size_name ) {
				unset( $original_meta['sizes'][ $size_name ] );
			}
		}

		// @phpstan-ignore argument.type
		wp_update_attachment_metadata( $id, $original_meta );
	}
}
