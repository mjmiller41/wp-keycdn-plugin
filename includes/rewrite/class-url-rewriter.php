<?php
namespace KeyCDN\Offload\Rewrite;

use KeyCDN\Offload\Core\Credentials;
use KeyCDN\Offload\Core\Manifest;
use KeyCDN\Offload\Core\StateMachine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UrlRewriter {

    private Credentials $credentials;
    private Manifest    $manifest;

    /** Runtime cache: attachment_id → bool (has confirmed 'full' row). */
    private array $cache = [];

    public function __construct( Credentials $credentials, Manifest $manifest ) {
        $this->credentials = $credentials;
        $this->manifest    = $manifest;
    }

    /**
     * Hook: wp_get_attachment_url (priority 10, 2 args)
     */
    public function rewrite_attachment_url( string $url, int $post_id ): string {
        if ( ! $this->is_offloaded( $post_id, 'full' ) ) {
            return $url;
        }
        return $this->swap_domain( $url );
    }

    /**
     * Hook: wp_calculate_image_srcset (priority 10, 5 args)
     */
    public function rewrite_srcset( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {
        foreach ( $sources as $width => $source ) {
            // Determine the size slug from the src filename.
            $slug = $this->slug_from_src( $source['url'], $attachment_id );
            if ( $this->is_offloaded( $attachment_id, $slug ) ) {
                $sources[ $width ]['url'] = $this->swap_domain( $source['url'] );
            }
        }
        return $sources;
    }

    /**
     * Hook: wp_prepare_attachment_for_js (priority 10, 3 args)
     * Rewrites URLs in the media modal JS data object.
     */
    public function rewrite_attachment_for_js( array $response, \WP_Post $attachment, $meta ): array {
        if ( ! $this->is_offloaded( $attachment->ID, 'full' ) ) {
            return $response;
        }
        if ( ! empty( $response['url'] ) ) {
            $response['url'] = $this->swap_domain( $response['url'] );
        }
        if ( ! empty( $response['sizes'] ) ) {
            foreach ( $response['sizes'] as $size => &$data ) {
                if ( ! empty( $data['url'] ) && $this->is_offloaded( $attachment->ID, $size ) ) {
                    $data['url'] = $this->swap_domain( $data['url'] );
                }
            }
        }
        return $response;
    }

    private function is_offloaded( int $attachment_id, string $size_slug ): bool {
        $cache_key = "{$attachment_id}:{$size_slug}";
        if ( ! isset( $this->cache[ $cache_key ] ) ) {
            $rows = $this->manifest->get_by_attachment( $attachment_id );
            foreach ( $rows as $row ) {
                if ( $row['size_slug'] === $size_slug && $row['state'] === StateMachine::CONFIRMED ) {
                    $this->cache[ $cache_key ] = true;
                    return true;
                }
                // Also treat local_removed as confirmed for URL purposes.
                if ( $row['size_slug'] === $size_slug && $row['state'] === StateMachine::LOCAL_REMOVED ) {
                    $this->cache[ $cache_key ] = true;
                    return true;
                }
            }
            $this->cache[ $cache_key ] = false;
        }
        return $this->cache[ $cache_key ];
    }

    private function swap_domain( string $url ): string {
        $zone_url = $this->credentials->get_zone_url();
        if ( '' === $zone_url ) {
            return $url;
        }
        $upload_dir  = wp_upload_dir();
        $local_base  = $upload_dir['baseurl'];
        return str_replace( $local_base, $zone_url, $url );
    }

    private function slug_from_src( string $src_url, int $attachment_id ): string {
        $filename = basename( wp_parse_url( $src_url, PHP_URL_PATH ) );
        $meta     = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $slug => $data ) {
                if ( isset( $data['file'] ) && $data['file'] === $filename ) {
                    return $slug;
                }
            }
        }
        return 'full';
    }
}
