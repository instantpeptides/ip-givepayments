<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Logger {
    /**
     * Holds the logger instance.
     *
     * @var WC_Logger|null
     */
    protected static $logger = null;

    /**
     * Retrieves the logger instance.
     *
     * @return WC_Logger|null
     */
    public static function get_logger() {
        if ( is_null( self::$logger ) ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                self::$logger = wc_get_logger();
            }
        }
        return self::$logger;
    }

    /**
     * Logs a message.
     *
     * @param string $message The log message.
     * @param string $level   The log level (e.g., 'info', 'debug', 'error').
     */
    public static function log( $message, $level = 'info' ) {
        $logger = self::get_logger();

        if ( $logger ) {
            $logger->log( $level, $message, array( 'source' => 'givepayments' ) );
        } else if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            $log_message = sprintf( '[GivePayments %s] %s', $level, $message );
            if ( function_exists( 'wp_debug_log' ) ) {
                wp_debug_log( $log_message );
            }
        }
    }
}