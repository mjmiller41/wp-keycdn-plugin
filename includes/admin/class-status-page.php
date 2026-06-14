<?php
namespace KeyCDN\Offload\Admin;

use KeyCDN\Offload\Core\Manifest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StatusPage {

    private Manifest $manifest;

    public function __construct( Manifest $manifest ) {
        $this->manifest = $manifest;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $counts = $this->manifest->get_state_counts();
        include KEYCDN_OFFLOAD_PATH . 'templates/status-page.php';
    }
}
