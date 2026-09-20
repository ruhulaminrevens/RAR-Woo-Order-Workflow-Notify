<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
// Keep settings intentionally. Removing the plugin must never delete order history or workflow metadata.
