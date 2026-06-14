<?php
namespace KeyCDN\Offload\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ImportPage {

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        include KEYCDN_OFFLOAD_PATH . 'templates/import-page.php';
    }
}
