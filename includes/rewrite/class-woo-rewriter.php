<?php
namespace KeyCDN\Offload\Rewrite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooRewriter {

    private UrlRewriter $rewriter;

    public function __construct( UrlRewriter $rewriter ) {
        $this->rewriter = $rewriter;
    }

    /**
     * Hook: woocommerce_single_product_image_thumbnail_html (priority 10, 2 args)
     */
    public function rewrite_product_thumbnail_html( string $html, int $post_id ): string {
        return $this->rewrite_img_tags( $html, $post_id );
    }

    /**
     * Hook: woocommerce_product_get_image (priority 10, 2 args)
     */
    public function rewrite_product_get_image( string $html, $product ): string {
        $id = is_object( $product ) && method_exists( $product, 'get_image_id' ) ? $product->get_image_id() : 0;
        return $this->rewrite_img_tags( $html, (int) $id );
    }

    private function rewrite_img_tags( string $html, int $attachment_id ): string {
        if ( '' === $html || ! $attachment_id ) {
            return $html;
        }
        // Apply the same wp_get_attachment_url filter so our UrlRewriter handles it.
        // WooCommerce builds its HTML from wp_get_attachment_image / wc_get_gallery_image_html,
        // both of which ultimately call wp_get_attachment_url — our filter already covers those.
        // This hook catches any edge-case where WC assembles HTML before our filters fire.
        $upload_base = wp_upload_dir()['baseurl'];
        $zone_url    = $this->rewriter->rewrite_attachment_url( $upload_base . '/', $attachment_id );
        if ( $zone_url !== $upload_base . '/' ) {
            $html = str_replace( $upload_base, rtrim( $zone_url, '/' ), $html );
        }
        return $html;
    }
}
