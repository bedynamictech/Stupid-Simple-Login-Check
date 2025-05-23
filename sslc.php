<?php
/**
 * Plugin Name: Stupid Simple Login Check
 * Description: Adds a honeypot field, nonce check, and brute-force protection to the Login page.
 * Version: 1.2.6
 * Author: Dynamic Technologies
 * Author URI: https://bedynamic.tech
 * Plugin URI: https://github.com/bedynamictech/Stupid-Simple-Login-Check
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stupid-simple-login-check
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Ensure the script is not accessed directly
}

class Stupid_Simple_Login_Checker {

    private $max_attempts     = 5;
    private $lockout_duration = 300; // in seconds (5 minutes)
    private $option_key       = 'sslc_locked_ips';

    public function __construct() {
        // load translations if you include .mo files in /languages
        load_plugin_textdomain(
            'stupid-simple-login-check',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );

        add_action( 'login_form',      [ $this, 'add_honeypot_and_nonce' ] );
        add_filter( 'authenticate',    [ $this, 'check_login' ], 30, 3 );
        add_action( 'wp_login_failed', [ $this, 'track_failed_login' ] );
        add_action( 'admin_menu',      [ $this, 'setup_admin_menu' ] );
    }

    public function add_honeypot_and_nonce() {
        echo '<input type="hidden" name="sslc_honeypot" value="" id="sslc_honeypot" autocomplete="off" />';
        echo '<div id="sslc_honeypot_wrap" style="display:none;">';
        echo '<label for="sslc_honeypot_visual">Honeypot</label>';
        echo '<input type="text" id="sslc_honeypot_visual" name="sslc_honeypot_visual" autocomplete="off" tabindex="-1">';
        echo '</div>';
        wp_nonce_field( 'sslc_login_nonce', 'sslc_nonce' );
    }

    public function check_login( $user, $username, $password ) {
        $ip         = $this->get_user_ip();
        $locked_ips = get_option( $this->option_key, [] );

        if ( isset( $locked_ips[ $ip ] ) && time() < $locked_ips[ $ip ]['locked_until'] ) {
            return new WP_Error(
                'sslc_locked',
                __(
                    '<strong>ERROR</strong>: Too many failed login attempts. Try again later.',
                    'stupid-simple-login-check'
                )
            );
        }

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['log'] ) ) {
            if ( empty( $_POST['sslc_nonce'] ) || ! wp_verify_nonce( $_POST['sslc_nonce'], 'sslc_login_nonce' ) ) {
                return new WP_Error(
                    'sslc_error',
                    __(
                        '<strong>ERROR</strong>: Spam detected!',
                        'stupid-simple-login-check'
                    )
                );
            }

            if ( ! empty( $_POST['sslc_honeypot'] ) || ! empty( $_POST['sslc_honeypot_visual'] ) ) {
                return new WP_Error(
                    'sslc_error',
                    __(
                        '<strong>ERROR</strong>: Spam detected!',
                        'stupid-simple-login-check'
                    )
                );
            }
        }

        return $user;
    }

    public function track_failed_login( $username ) {
        $ip         = $this->get_user_ip();
        $locked_ips = get_option( $this->option_key, [] );

        if ( ! isset( $locked_ips[ $ip ] ) ) {
            $locked_ips[ $ip ] = [
                'attempts'     => 0,
                'locked_until' => 0,
            ];
        }

        $locked_ips[ $ip ]['attempts']++;

        if ( $locked_ips[ $ip ]['attempts'] >= $this->max_attempts ) {
            $locked_ips[ $ip ]['locked_until'] = time() + $this->lockout_duration;
            $locked_ips[ $ip ]['attempts']     = 0;
        }

        update_option( $this->option_key, $locked_ips );
    }

    private function get_user_ip() {
        return $_SERVER['REMOTE_ADDR'];
    }

    public function setup_admin_menu() {
        add_menu_page(
            'Stupid Simple',
            'Stupid Simple',
            'manage_options',
            'stupidsimple',
            [ $this, 'render_admin_page' ],
            'dashicons-hammer',
            99
        );

        add_submenu_page(
            'stupidsimple',
            'Login Check',
            'Login Check',
            'manage_options',
            'sslc-lockout-log',
            [ $this, 'render_admin_page' ]
        );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Handle unblock before any output
        if ( isset( $_GET['unblock_ip'] ) ) {
            check_admin_referer( 'unblock_ip_action', 'unblock_ip_nonce' );
            $unblock_ip = sanitize_text_field( wp_unslash( $_GET['unblock_ip'] ) );
            $locked_ips = get_option( $this->option_key, [] );
            unset( $locked_ips[ $unblock_ip ] );
            update_option( $this->option_key, $locked_ips );
            wp_safe_redirect( remove_query_arg( [ 'unblock_ip', 'unblock_ip_nonce' ] ) );
            exit;
        }

        $locked_ips = get_option( $this->option_key, [] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Login Check', 'stupid-simple-login-check' ); ?></h1>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'IP Address',   'stupid-simple-login-check' ); ?></th>
                        <th><?php esc_html_e( 'Locked Until', 'stupid-simple-login-check' ); ?></th>
                        <th><?php esc_html_e( 'Action',       'stupid-simple-login-check' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $locked_ips as $ip => $data ) : ?>
                        <?php if ( time() < $data['locked_until'] ) : ?>
                            <tr>
                                <td><?php echo esc_html( $ip ); ?></td>
                                <td><?php echo esc_html( date( 'Y-m-d H:i:s', $data['locked_until'] ) ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'unblock_ip', $ip ), 'unblock_ip_action', 'unblock_ip_nonce' ) ); ?>"
                                       class="button">
                                        <?php esc_html_e( 'Unlock', 'stupid-simple-login-check' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

new Stupid_Simple_Login_Checker();

// Add Settings link on Plugins page
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'sslc_action_links' );
function sslc_action_links( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=sslc-lockout-log' ) . '">Settings</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
