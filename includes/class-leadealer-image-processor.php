<?php
/**
 * Untrusted image validation and from-scratch re-encoding.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an arbitrary uploaded file into a safe, re-encoded JPEG/PNG/WebP,
 * or rejects it. The public server-side path deliberately uses GD only and
 * never invokes a HEIC/HEIF decoder. Every accepted byte is fully decoded and
 * then re-rendered from scratch; the original upload is never stored or reused.
 */
final class Leadealer_Image_Processor {

	/**
	 * Validate and re-encode an uploaded temp file.
	 *
	 * @param string $tmp_path        Path to the uploaded temp file.
	 * @param string $client_filename Client-supplied original filename (untrusted, display/extension-hint only).
	 * @return array{path:string,mime:string,extension:string,width:int,height:int,byte_size:int}|WP_Error
	 */
	public function process( $tmp_path, $client_filename ) {
		if ( ! $this->gd_is_available() ) {
			return new WP_Error( 'image_processing_unavailable', __( 'This image could not be read. Please try a different photo.', 'leadealer' ) );
		}

		if ( ! is_file( $tmp_path ) || ! is_readable( $tmp_path ) ) {
			return new WP_Error( 'undecodable_image', __( 'This image could not be read. Please try a different photo.', 'leadealer' ) );
		}

		$extension = $this->extension_from_filename( $client_filename );
		if ( '' === $extension || ! in_array( $extension, Leadealer_Upload_Repository::ALLOWED_EXTENSIONS, true ) ) {
			return new WP_Error( 'unsupported_type', __( 'This file type is not supported. Please use JPEG, PNG, or WEBP.', 'leadealer' ) );
		}

		$mime = $this->sniff_mime( $tmp_path );
		if ( ! in_array( $mime, Leadealer_Upload_Repository::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error( 'unsupported_type', __( 'This file type is not supported. Please use JPEG, PNG, or WEBP.', 'leadealer' ) );
		}

		if ( ! $this->extension_matches_mime( $extension, $mime ) ) {
			return new WP_Error( 'unsupported_type', __( 'This file type is not supported. Please use JPEG, PNG, or WEBP.', 'leadealer' ) );
		}

		if ( ! $this->gd_supports_mime( $mime ) ) {
			return new WP_Error( 'image_processing_unavailable', __( 'This image could not be read. Please try a different photo.', 'leadealer' ) );
		}

		if ( ! $this->magic_bytes_match( $tmp_path, $mime ) ) {
			return new WP_Error( 'unsupported_type', __( 'This file type is not supported. Please use JPEG, PNG, or WEBP.', 'leadealer' ) );
		}

		$size_info = @getimagesize( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- getimagesize() emits a warning on unreadable/corrupt images; the failure is handled explicitly below.
		if ( ! is_array( $size_info ) || empty( $size_info[0] ) || empty( $size_info[1] ) || $this->imagetype_for_mime( $mime ) !== $size_info[2] ) {
			return new WP_Error( 'undecodable_image', __( 'This image could not be read. Please try a different photo.', 'leadealer' ) );
		}

		$input_width  = (int) $size_info[0];
		$input_height = (int) $size_info[1];
		$pixel_limit_exceeded = $input_height <= 0 || $input_width > (int) floor( Leadealer_Upload_Repository::MAX_INPUT_PIXELS / max( 1, $input_height ) );

		if ( $input_width > Leadealer_Upload_Repository::MAX_INPUT_DIMENSION || $input_height > Leadealer_Upload_Repository::MAX_INPUT_DIMENSION || $pixel_limit_exceeded ) {
			return new WP_Error( 'undecodable_image', __( 'This image could not be read. Please try a different photo.', 'leadealer' ) );
		}

		$image = $this->load_image( $tmp_path, $mime );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		if ( 'image/jpeg' === $mime ) {
			$image = $this->auto_orient( $image, $tmp_path );
		}

		$image = $this->downscale( $image );

		$encoded = $this->encode_to_temp_file( $image, $mime );
		$width   = imagesx( $image );
		$height  = imagesy( $image );
		imagedestroy( $image );

		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}

		return array(
			'path'      => $encoded,
			'mime'      => $mime,
			'extension' => $this->extension_for_mime( $mime ),
			'width'     => (int) $width,
			'height'    => (int) $height,
			'byte_size' => (int) filesize( $encoded ),
		);
	}

	/**
	 * Extract a lowercase extension from a client-supplied filename.
	 *
	 * @param string $filename Client-supplied filename.
	 * @return string
	 */
	private function extension_from_filename( $filename ) {
		$extension = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );

		return preg_match( '/^[a-z0-9]{1,5}$/', $extension ) ? $extension : '';
	}

	/**
	 * Require the client filename extension to agree with independently sniffed content.
	 *
	 * @param string $extension Filename extension.
	 * @param string $mime      Sniffed MIME type.
	 * @return bool
	 */
	private function extension_matches_mime( $extension, $mime ) {
		if ( 'image/jpeg' === $mime ) {
			return in_array( $extension, array( 'jpg', 'jpeg' ), true );
		}
		if ( 'image/png' === $mime ) {
			return 'png' === $extension;
		}
		return 'image/webp' === $mime && 'webp' === $extension;
	}

	/**
	 * Sniff the real MIME type of a file, ignoring any client-supplied claim.
	 *
	 * @param string $path Absolute path to the temp file.
	 * @return string
	 */
	private function sniff_mime( $path ) {
		if ( ! function_exists( 'finfo_open' ) ) {
			return '';
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		if ( false === $finfo ) {
			return '';
		}

		$mime = finfo_file( $finfo, $path );
		finfo_close( $finfo );

		return is_string( $mime ) ? $mime : '';
	}

	/**
	 * Independently verify a file's leading signature bytes.
	 *
	 * @param string $path Absolute path to the temp file.
	 * @param string $mime Expected MIME type.
	 * @return bool
	 */
	private function magic_bytes_match( $path, $mime ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading the first bytes of a just-uploaded temp file for signature verification; WP_Filesystem is unnecessary overhead here.
		if ( false === $handle ) {
			return false;
		}
		$head = fread( $handle, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading a fixed small header for signature verification.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with fopen() above.

		if ( ! is_string( $head ) || strlen( $head ) < 12 ) {
			return false;
		}

		switch ( $mime ) {
			case 'image/jpeg':
				return "\xFF\xD8\xFF" === substr( $head, 0, 3 );
			case 'image/png':
				return "\x89PNG\x0D\x0A\x1A\x0A" === substr( $head, 0, 8 );
			case 'image/webp':
				return 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 );
			default:
				return false;
		}
	}

	/**
	 * Map an allowed MIME type to its getimagesize() IMAGETYPE_* constant.
	 *
	 * @param string $mime MIME type.
	 * @return int
	 */
	private function imagetype_for_mime( $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
				return IMAGETYPE_JPEG;
			case 'image/png':
				return IMAGETYPE_PNG;
			case 'image/webp':
				return IMAGETYPE_WEBP;
			default:
				return -1;
		}
	}

	/**
	 * Map an allowed MIME type to the extension used for the re-encoded file.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private function extension_for_mime( $mime ) {
		switch ( $mime ) {
			case 'image/png':
				return 'png';
			case 'image/webp':
				return 'webp';
			case 'image/jpeg':
			default:
				return 'jpg';
		}
	}


	/**
	 * Check that the GD functions used by the public upload path are available.
	 *
	 * @return bool
	 */
	private function gd_is_available() {
		$required = array( 'imagesx', 'imagesy', 'imagescale', 'imagedestroy' );

		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check that GD can both decode and re-encode the detected image format.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	private function gd_supports_mime( $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
				return function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagejpeg' );
			case 'image/png':
				return function_exists( 'imagecreatefrompng' ) && function_exists( 'imagepng' );
			case 'image/webp':
				return function_exists( 'imagecreatefromwebp' ) && function_exists( 'imagewebp' );
			default:
				return false;
		}
	}

	/**
	 * Accept GD's PHP 8 object and the resource returned on PHP 7.4.
	 *
	 * @param mixed $image Candidate GD image.
	 * @return bool
	 */
	private function is_gd_image( $image ) {
		if ( class_exists( 'GdImage', false ) && $image instanceof GdImage ) {
			return true;
		}

		return is_resource( $image ) && 'gd' === get_resource_type( $image );
	}

	/**
	 * Fully decode an image with GD.
	 *
	 * @param string $path Absolute path to the temp file.
	 * @param string $mime MIME type.
	 * @return GdImage|WP_Error
	 */
	private function load_image( $path, $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
				$image = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed file is expected input here; the failure is handled explicitly below.
				break;
			case 'image/png':
				$image = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed file is expected input here; the failure is handled explicitly below.
				if ( $image ) {
					imagealphablending( $image, false );
					imagesavealpha( $image, true );
				}
				break;
			case 'image/webp':
				$image = @imagecreatefromwebp( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed file is expected input here; the failure is handled explicitly below.
				break;
			default:
				$image = false;
		}

		if ( ! $this->is_gd_image( $image ) ) {
			return new WP_Error( 'undecodable_image', __( 'This image could not be read. Please try a different photo.', 'leadealer' ) );
		}

		return $image;
	}

	/**
	 * Bake EXIF rotation/mirroring into the pixel data, then discard it.
	 *
	 * Metadata (including this EXIF orientation tag) is never carried into
	 * the re-encoded output, so orientation must be applied before encoding.
	 *
	 * @param GdImage $image Decoded image.
	 * @param string  $path  Original temp file path (for reading EXIF).
	 * @return GdImage
	 */
	private function auto_orient( $image, $path ) {
		if ( ! function_exists( 'exif_read_data' ) || ! function_exists( 'imagerotate' ) || ! function_exists( 'imageflip' ) ) {
			return $image;
		}

		$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing/invalid EXIF is expected and simply skips orientation correction.
		if ( ! is_array( $exif ) || empty( $exif['Orientation'] ) ) {
			return $image;
		}

		$orientation = (int) $exif['Orientation'];
		if ( in_array( $orientation, array( 2, 4, 5, 7 ), true ) ) {
			$flip_mode = in_array( $orientation, array( 2, 7 ), true ) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL;
			imageflip( $image, $flip_mode );
		}

		$angle = 0;
		switch ( $orientation ) {
			case 3:
				$angle = 180;
				break;
			case 5:
			case 6:
			case 7:
				$angle = -90;
				break;
			case 8:
				$angle = 90;
				break;
		}

		if ( 0 !== $angle ) {
			$rotated = imagerotate( $image, $angle, 0 );
			if ( $this->is_gd_image( $rotated ) ) {
				imagedestroy( $image );
				$image = $rotated;
			}
		}

		return $image;
	}

	/**
	 * Downscale an image to fit within the maximum output dimension.
	 *
	 * @param GdImage $image Decoded image.
	 * @return GdImage
	 */
	private function downscale( $image ) {
		$width   = imagesx( $image );
		$height  = imagesy( $image );
		$longest = max( $width, $height );

		if ( $longest <= Leadealer_Upload_Repository::MAX_OUTPUT_DIMENSION ) {
			return $image;
		}

		$scale      = Leadealer_Upload_Repository::MAX_OUTPUT_DIMENSION / $longest;
		$new_width  = max( 1, (int) round( $width * $scale ) );
		$new_height = max( 1, (int) round( $height * $scale ) );

		$resized = imagescale( $image, $new_width, $new_height, IMG_BICUBIC );
		if ( ! $this->is_gd_image( $resized ) ) {
			return $image;
		}

		imagedestroy( $image );

		return $resized;
	}

	/**
	 * Encode an image to a fresh temp file, discarding all metadata.
	 *
	 * @param GdImage $image Decoded (and re-oriented/downscaled) image.
	 * @param string  $mime  Target MIME type.
	 * @return string|WP_Error Absolute path to the encoded temp file.
	 */
	private function encode_to_temp_file( $image, $mime ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			// wp_tempnam() lives in an admin-only file that this public REST
			// context never autoloads.
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = wp_tempnam( 'leadealer-upload' );
		if ( ! $tmp ) {
			return new WP_Error( 'upload_store_failed', __( 'The photo could not be stored. Please try again.', 'leadealer' ) );
		}

		$written = false;
		switch ( $mime ) {
			case 'image/png':
				$written = imagepng( $image, $tmp, 6 );
				break;
			case 'image/webp':
				$written = imagewebp( $image, $tmp, 85 );
				break;
			case 'image/jpeg':
			default:
				$written = imagejpeg( $image, $tmp, 85 );
				break;
		}

		if ( ! $written ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'upload_store_failed', __( 'The photo could not be stored. Please try again.', 'leadealer' ) );
		}

		return $tmp;
	}
}
