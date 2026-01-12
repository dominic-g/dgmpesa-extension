<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DG_Mpesa_Checkout_Logger
 *
 * Uses WooCommerce's WC_Logger for logging when WP_DEBUG is enabled.
 * Updated to use modern logging format.
 */
class DG_Mpesa_Checkout_Logger {

    private $logger;
    private $source = 'dg-mpesa'; // Define a source handle

    public function __construct() {
         // Get the WC_Logger instance only if the class exists
         if ( class_exists( 'WC_Logger' ) ) {
             $this->logger = wc_get_logger(); // Use the function to get the logger instance
         } else {
             $this->logger = null;
         }
    }

    public function log( $message, $level = 'debug' ) {
        // Only log if WP_DEBUG is true AND the logger was successfully initialized
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $this->logger ) {
            // Use the modern format: log($message, $level_array)
            // WC_Log_Levels constants could also be used if defined (e.g., WC_Log_Levels::DEBUG)
            $this->logger->log(
                 $level, // Standard level (debug, info, notice, warning, error, critical, alert, emergency)
                 $message,
                 array( 'source' => $this->source ) // Context array with source
            );
        }
    }
}
?>