<?php
namespace KeyCDN\Offload\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FileSanitizer {

    /**
     * Hook: wp_handle_upload_prefilter
     * Normalizes filename to NFC Unicode, sanitizes, and rejects zero-byte files.
     */
    public function prefilter_upload( array $file ): array {
        if ( isset( $file['size'] ) && 0 === (int) $file['size'] ) {
            $file['error'] = __( 'KeyCDN Offload: Zero-byte files cannot be uploaded.', 'wp-keycdn-offload' );
            return $file;
        }
        if ( isset( $file['name'] ) ) {
            $file['name'] = $this->sanitize( $file['name'] );
        }
        return $file;
    }

    /**
     * Normalize to NFC (fixes macOS Chrome/Firefox decomposed Unicode — WP Trac #55807)
     * then apply WordPress's canonical sanitize_file_name().
     */
    public function sanitize( string $filename ): string {
        if ( function_exists( 'normalizer_normalize' ) ) {
            $normalized = normalizer_normalize( $filename, \Normalizer::FORM_C );
            if ( false !== $normalized ) {
                $filename = $normalized;
            }
        }
        return sanitize_file_name( $filename );
    }
}
