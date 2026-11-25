Feature: Replace WordPress attachment files

  Background:
    Given a WP install

  Scenario: Replace an attachment file with a local file
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg`
    Then STDOUT should contain:
      """
      Replaced file for attachment ID {ATTACHMENT_ID}
      """
    And STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

  Scenario: Replace an attachment file and keep its existing file name
    Given download:
      | path                        | url                                                     |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg           |
      | {CACHE_DIR}/replacement.jpg | http://wp-cli.github.io/behat-data/white-150-square.jpg |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then save STDOUT as {OLD_FILE}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/replacement.jpg`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      {OLD_FILE}
      """
    And the wp-content/uploads/{OLD_FILE} file should exist
    And the wp-content/uploads/replacement.jpg file should not exist

    # The file at the existing path must now hold the replacement's bytes.
    When I run `wp eval '$s = getimagesize( get_attached_file( {ATTACHMENT_ID} ) ); echo $s[0] . "x" . $s[1];'`
    Then STDOUT should be:
      """
      150x150
      """

  Scenario: Keep the file permissions when replacing an attachment file in place
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
      | {CACHE_DIR}/replacement.jpg | http://wp-cli.github.io/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp eval 'chmod( get_attached_file( {ATTACHMENT_ID} ), 0644 );'`
    And I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/replacement.jpg`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp eval 'clearstatcache(); echo decoct( fileperms( get_attached_file( {ATTACHMENT_ID} ) ) & 0777 );'`
    Then STDOUT should be:
      """
      644
      """

  Scenario: Replace an attachment file and keep its existing upload folder
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
      | {CACHE_DIR}/replacement.jpg | http://wp-cli.github.io/behat-data/canola.jpg      |
    And a move-attachment.php file:
      """
      <?php
      $attachment_id = (int) $args[0];
      $target_subdir = '2023/05';
      $uploads       = wp_get_upload_dir();
      $relative      = get_post_meta( $attachment_id, '_wp_attached_file', true );
      $source        = $uploads['basedir'] . '/' . $relative;
      $target_dir    = $uploads['basedir'] . '/' . $target_subdir;

      if ( ! is_dir( $target_dir ) ) {
          mkdir( $target_dir, 0777, true );
      }

      $stem = pathinfo( $relative, PATHINFO_FILENAME );
      foreach ( glob( dirname( $source ) . '/' . $stem . '*.*' ) as $file ) {
          rename( $file, $target_dir . '/' . wp_basename( $file ) );
      }

      $relative = $target_subdir . '/' . wp_basename( $relative );
      update_post_meta( $attachment_id, '_wp_attached_file', $relative );

      $metadata = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
      if ( is_array( $metadata ) ) {
          $metadata['file'] = $relative;
          update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );
      }

      echo $relative;
      """

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp eval-file move-attachment.php {ATTACHMENT_ID}`
    Then save STDOUT as {OLD_FILE}

    When I run `wp eval 'echo wp_get_attachment_url( {ATTACHMENT_ID} );'`
    Then save STDOUT as {OLD_URL}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/replacement.jpg`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      {OLD_FILE}
      """

    When I run `wp eval 'echo wp_get_attachment_url( {ATTACHMENT_ID} );'`
    Then STDOUT should be:
      """
      {OLD_URL}
      """
    And the wp-content/uploads/{OLD_FILE} file should exist

  @require-wp-5.3
  Scenario: Replace a scaled big image with another big image and keep its path
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then save STDOUT as {OLD_FILE}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/large-image.jpg`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      {OLD_FILE}
      """
    And the wp-content/uploads/{OLD_FILE} file should exist

    # The full-resolution original must survive the replacement.
    When I run `wp eval '$s = getimagesize( wp_get_original_image_path( {ATTACHMENT_ID} ) ); echo $s[0] . "x" . $s[1];'`
    Then STDOUT should be:
      """
      3264x2448
      """

  @require-wp-5.3
  Scenario: Replace a scaled big image with a smaller image and keep its path
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then save STDOUT as {OLD_FILE}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      {OLD_FILE}
      """
    And the wp-content/uploads/{OLD_FILE} file should exist

    # The stale big-image thumbnail and the pre-scaled master must be removed.
    And the wp-content/uploads/large-image-1024x768.jpg file should not exist
    And the wp-content/uploads/large-image.jpg file should not exist

  Scenario: Replace a small image with a big image and keep the small image's path
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then save STDOUT as {OLD_FILE}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/large-image.jpg`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      {OLD_FILE}
      """
    And the wp-content/uploads/{OLD_FILE} file should exist

    # The full-resolution replacement must be kept at the original path.
    When I run `wp eval '$s = getimagesize( get_attached_file( {ATTACHMENT_ID} ) ); echo $s[0] . "x" . $s[1];'`
    Then STDOUT should be:
      """
      3264x2448
      """

    # The recorded dimensions must match the file that is actually served.
    When I run `wp eval '$m = wp_get_attachment_metadata( {ATTACHMENT_ID} ); echo $m["width"] . "x" . $m["height"];'`
    Then STDOUT should be:
      """
      3264x2448
      """

  Scenario: Error when replacing an attachment with a different file type in place
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp eval 'imagepng( imagecreatetruecolor( 20, 20 ), "tiny.png" );'`
    And I run `wp media import tiny.png --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I try `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg`
    Then STDERR should contain:
      """
      --new-file-name flag
      """
    And the return code should be 1

    # The opt-out still allows a replacement with a different file type.
    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg --new-file-name`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

  Scenario: Reject a replacement with a file type WordPress does not allow
    Given a replacement.php file:
      """
      <?php
      echo 'not an image';
      """
    And download:
      | path                   | url                                          |
      | {CACHE_DIR}/canola.jpg | http://wp-cli.github.io/behat-data/canola.jpg |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I try `wp media replace {ATTACHMENT_ID} replacement.php`
    Then STDERR should contain:
      """
      not allowed to upload this file type
      """
    And the return code should be 1

  Scenario: Refuse an in-place replacement outside the uploads directory
    Given download:
      | path                   | url                                          |
      | {CACHE_DIR}/canola.jpg | http://wp-cli.github.io/behat-data/canola.jpg |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp post meta update {ATTACHMENT_ID} _wp_attached_file ../outside.jpg`

    And I try `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg`
    Then STDERR should contain:
      """
      The attachment path is outside the uploads directory.
      """
    And the return code should be 1

  Scenario: Refuse a replacement rejected by the sideload prefilter
    Given download:
      | path                   | url                                          |
      | {CACHE_DIR}/canola.jpg | http://wp-cli.github.io/behat-data/canola.jpg |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    Given a wp-content/mu-plugins/reject-uploads.php file:
      """
      <?php
      add_filter( 'wp_handle_sideload_prefilter', function ( $file ) {
          $file['error'] = 'Rejected by test filter.';
          return $file;
      } );
      """

    When I try `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg`
    Then STDERR should contain:
      """
      Rejected by test filter.
      """
    And the return code should be 1

  Scenario: Error when the replacement URL cannot be downloaded
    Given download:
      | path                   | url                                          |
      | {CACHE_DIR}/canola.jpg | http://wp-cli.github.io/behat-data/canola.jpg |
    And that HTTP requests to http://example.com/missing.jpg will respond with:
      """
      HTTP/1.1 404
      Content-Type: text/plain

      Not Found
      """
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then save STDOUT as {OLD_FILE}

    When I try `wp media replace {ATTACHMENT_ID} http://example.com/missing.jpg`
    Then STDERR should contain:
      """
      Unable to replace attachment
      """
    And the return code should be 1
    And the wp-content/uploads/{OLD_FILE} file should exist

  Scenario: Error when the attachment has no attached file
    Given download:
      | path                   | url                                          |
      | {CACHE_DIR}/canola.jpg | http://wp-cli.github.io/behat-data/canola.jpg |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp post meta delete {ATTACHMENT_ID} _wp_attached_file`

    And I try `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg`
    Then STDERR should contain:
      """
      Unable to determine the current file for attachment
      """
    And the return code should be 1

  Scenario: Repair an attachment without an attached file using a new file name
    Given download:
      | path                        | url                                                     |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg           |
      | {CACHE_DIR}/replacement.jpg | http://wp-cli.github.io/behat-data/white-150-square.jpg |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp post meta delete {ATTACHMENT_ID} _wp_attached_file`
    And I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/replacement.jpg --new-file-name`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      replacement.jpg
      """
    And the wp-content/uploads/replacement.jpg file should exist

  @require-wp-5.3
  Scenario: Replace a previously rotated image and keep its path
    Given download:
      | path                        | url                                                     |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg           |
      | {CACHE_DIR}/replacement.jpg | http://wp-cli.github.io/behat-data/white-150-square.jpg |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    # Mimic WordPress having auto-rotated the upload into a "-rotated" file.
    When I run `wp eval '$id = {ATTACHMENT_ID}; $file = get_attached_file( $id ); $rotated = str_replace( ".jpg", "-rotated.jpg", $file ); copy( $file, $rotated ); update_attached_file( $id, $rotated ); $m = wp_get_attachment_metadata( $id ); $m["original_image"] = wp_basename( $file ); $m["file"] = _wp_relative_upload_path( $rotated ); wp_update_attachment_metadata( $id, $m );'`
    And I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      canola-rotated.jpg
      """

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/replacement.jpg`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      canola-rotated.jpg
      """

    # The existing URL must serve the replacement.
    When I run `wp eval '$s = getimagesize( get_attached_file( {ATTACHMENT_ID} ) ); echo $s[0] . "x" . $s[1];'`
    Then STDOUT should be:
      """
      150x150
      """

  @require-wp-5.3
  Scenario: Record the displayed dimensions of a replacement with an EXIF orientation
    Given download:
      | path                   | url                                           |
      | {CACHE_DIR}/canola.jpg | http://wp-cli.github.io/behat-data/canola.jpg |
    And a add-orientation.php file:
      """
      <?php
      // Insert an EXIF segment with orientation 6 (rotate 90 degrees clockwise) after the JPEG SOI marker.
      $jpeg = file_get_contents( $args[0] );
      $exif = "Exif\0\0MM\0\x2a\0\0\0\x08\0\x01\x01\x12\0\x03\0\0\0\x01\0\x06\0\0\0\0\0\0";
      file_put_contents( $args[1], substr( $jpeg, 0, 2 ) . "\xff\xe1" . pack( 'n', strlen( $exif ) + 2 ) . $exif . substr( $jpeg, 2 ) );
      """
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp eval-file add-orientation.php {CACHE_DIR}/canola.jpg {RUN_DIR}/rotated.jpg`
    And I run `wp eval 'echo wp_read_image_metadata( "{RUN_DIR}/rotated.jpg" )["orientation"];'`
    Then STDOUT should be:
      """
      6
      """

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp media replace {ATTACHMENT_ID} {RUN_DIR}/rotated.jpg`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      canola.jpg
      """

    When I run `wp eval '$m = wp_get_attachment_metadata( {ATTACHMENT_ID} ); echo $m["width"] . "x" . $m["height"];'`
    Then STDOUT should be:
      """
      480x640
      """

  @require-wp-5.3
  Scenario: Keep the existing path and image sizes when only the filesize can be read
    Given download:
      | path                        | url                                                |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then save STDOUT as {OLD_FILE}

    Given a wp-content/mu-plugins/read-error.php file:
      """
      <?php
      add_filter( 'wp_generate_attachment_metadata', function ( $metadata, $id, $context ) {
          return 'create' === $context ? array( 'filesize' => 123 ) : $metadata;
      }, 10, 3 );
      """

    When I try `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg`
    Then STDERR should contain:
      """
      Failed to generate new attachment metadata
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      {OLD_FILE}
      """
    And the wp-content/uploads/{OLD_FILE} file should exist
    And the wp-content/uploads/large-image-1024x768.jpg file should exist

    # WordPress stored metadata for the replacement before the filter ran; the old metadata must be restored.
    When I run `wp eval '$m = wp_get_attachment_metadata( {ATTACHMENT_ID} ); echo $m["file"] . " " . $m["width"] . "x" . $m["height"];'`
    Then STDOUT should be:
      """
      {OLD_FILE} 2560x1920
      """

  @require-wp-5.3
  Scenario: Keep the existing path and metadata when generation fails after WordPress scaled the replacement
    Given download:
      | path                        | url                                                |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    Given a wp-content/mu-plugins/read-error.php file:
      """
      <?php
      add_filter( 'wp_generate_attachment_metadata', function ( $metadata, $id, $context ) {
          return 'create' === $context ? array( 'filesize' => 123 ) : $metadata;
      }, 10, 3 );
      """

    When I try `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/large-image.jpg`
    Then STDERR should contain:
      """
      Failed to generate new attachment metadata
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      canola.jpg
      """

    When I run `wp eval '$m = wp_get_attachment_metadata( {ATTACHMENT_ID} ); echo $m["file"] . " " . $m["width"] . "x" . $m["height"];'`
    Then STDOUT should be:
      """
      canola.jpg 640x480
      """

  Scenario: Replace an attachment file with the file returned by the sideload prefilter
    Given download:
      | path                   | url                                           |
      | {CACHE_DIR}/canola.jpg | http://wp-cli.github.io/behat-data/canola.jpg |

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    Given a wp-content/mu-plugins/shrink.php file:
      """
      <?php
      add_filter( 'wp_handle_sideload_prefilter', function ( $file ) {
          $editor = wp_get_image_editor( $file['tmp_name'] );
          $editor->resize( 100, 100 );
          $saved = $editor->save( get_temp_dir() . 'wp-cli-shrunk.jpg', 'image/jpeg' );
          $file['tmp_name'] = $saved['path'];
          return $file;
      } );
      """

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp eval '$s = getimagesize( get_attached_file( {ATTACHMENT_ID} ) ); echo $s[0] . "x" . $s[1];'`
    Then STDOUT should be:
      """
      100x75
      """

  @require-wp-5.8
  Scenario: Keep the file type of a scaled image path when WordPress converts the replacement
    Given download:
      | path                        | url                                                |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    Given a wp-content/mu-plugins/webp.php file:
      """
      <?php
      add_filter( 'image_editor_output_format', function ( $formats ) {
          $formats['image/jpeg'] = 'image/webp';
          return $formats;
      } );
      """

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      large-image-scaled.jpg
      """
    And the wp-content/uploads/large-image.webp file should not exist

    When I run `wp eval 'echo wp_get_image_mime( get_attached_file( {ATTACHMENT_ID} ) );'`
    Then STDOUT should be:
      """
      image/jpeg
      """

  Scenario: Replace an attachment file with a new file name when requested
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
      | {CACHE_DIR}/replacement.jpg | http://wp-cli.github.io/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/canola.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then save STDOUT as {OLD_FILE}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/replacement.jpg --new-file-name`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then save STDOUT as {NEW_FILE}

    When I run `wp eval 'echo get_post_meta( {ATTACHMENT_ID}, "_wp_attached_file", true ) === "{OLD_FILE}" ? "unchanged" : "changed";'`
    Then STDOUT should be:
      """
      changed
      """

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should match /replacement(-\d+)?\.jpg$/
    And the wp-content/uploads/{NEW_FILE} file should exist

  Scenario: Replace an attachment file from a URL
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then save STDOUT as {OLD_FILE}

    When I run `wp media replace {ATTACHMENT_ID} 'http://wp-cli.github.io/behat-data/canola.jpg'`
    Then STDOUT should contain:
      """
      Replaced file for attachment ID {ATTACHMENT_ID}
      """
    And STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    # A URL-sourced replacement must also be written in place.
    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then STDOUT should be:
      """
      {OLD_FILE}
      """
    And the wp-content/uploads/{OLD_FILE} file should exist

  Scenario: Replace an attachment file and output only the attachment ID in porcelain mode
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg --porcelain`
    Then STDOUT should be:
      """
      {ATTACHMENT_ID}
      """

  Scenario: Preserve attachment metadata after replacing the file
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --title="My Image Title" --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    When I run `wp post get {ATTACHMENT_ID} --field=post_title`
    Then STDOUT should be:
      """
      My Image Title
      """

  Scenario: Error when replacing with a non-existent local file
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I try `wp media replace {ATTACHMENT_ID} /tmp/nonexistent-file.jpg`
    Then STDERR should contain:
      """
      Error: Unable to replace attachment
      """
    And STDERR should contain:
      """
      File doesn't exist.
      """
    And the return code should be 1

  Scenario: Error when replacing with an invalid attachment ID
    When I try `wp media replace 999999 /tmp/fake.jpg`
    Then STDERR should contain:
      """
      Error: Invalid attachment ID 999999.
      """
    And the return code should be 1

  Scenario: Skip deletion of previous files and thumbnails when --skip-delete flag is used
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then save STDOUT as {OLD_FILE}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg --skip-delete`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 attachments.
      """

    And the wp-content/uploads/{OLD_FILE} file should exist
    And the wp-content/uploads/large-image-1024x768.jpg file should exist
