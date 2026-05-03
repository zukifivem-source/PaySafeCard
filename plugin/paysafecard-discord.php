<?php
/**
 * Plugin Name: Paysafecard Discord Gateway
 * Description: Paysafecard payment method for WooCommerce. Sends an order notification to Discord with Process / Cancel buttons.
 * Version:     1.0.0
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    class WC_Gateway_Paysafecard extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'paysafecard';
            $this->method_title       = 'Paysafecard';
            $this->method_description = 'Accept Paysafecard payments and receive Discord notifications with one-click Process / Cancel buttons.';
            $this->has_fields         = true;

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        }

        // ── Admin settings ────────────────────────────────────────────────────
        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title'   => 'Enable / Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable Paysafecard',
                    'default' => 'yes',
                ],
                'title' => [
                    'title'   => 'Title',
                    'type'    => 'text',
                    'default' => 'Paysafecard',
                ],
                'description' => [
                    'title'   => 'Description',
                    'type'    => 'text',
                    'default' => '',
                ],
                'discord_webhook' => [
                    'title'       => 'Discord Webhook URL',
                    'type'        => 'text',
                    'description' => 'Paste your Discord channel webhook URL here.',
                    'default'     => '',
                ],
            ];
        }

        // ── Checkout fields ───────────────────────────────────────────────────
        public function payment_fields() {
            if ( $this->description ) {
                echo '<p>' . esc_html( $this->description ) . '</p>';
            }
            ?>
            <div id="psc-fields" style="margin-top:10px">

                <p style="margin:0 0 6px;font-weight:600">
                    <?php esc_html_e( 'Enter your Paysafecard PIN(s):', 'woocommerce' ); ?>
                </p>

                <div id="psc-pin-tags" style="margin-bottom:6px"></div>

                <div style="display:flex;gap:8px;margin-bottom:12px">
                    <input type="text" id="psc-pin-input" placeholder="Paysafecard PIN"
                           style="flex:1;padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px" />
                    <button type="button" id="psc-add-pin"
                            style="padding:8px 16px;background:#22a559;color:#fff;border:none;border-radius:4px;font-weight:700;cursor:pointer;font-size:13px;white-space:nowrap">
                        + Add PIN
                    </button>
                </div>

                <input type="hidden" name="psc_pins" id="psc-pins-hidden" />

                <p style="margin:0 0 6px;font-weight:600">
                    <?php esc_html_e( 'Select country:', 'woocommerce' ); ?>
                </p>
                <select name="psc_country"
                        style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;appearance:auto">
                    <?php
                    $countries = [
                        'Germany', 'Austria', 'Switzerland', 'Netherlands',
                        'Belgium', 'France', 'United Kingdom', 'Spain',
                        'Italy', 'Poland', 'Other',
                    ];
                    foreach ( $countries as $c ) {
                        echo '<option value="' . esc_attr( $c ) . '">' . esc_html( $c ) . '</option>';
                    }
                    ?>
                </select>

            </div>

            <script>
            (function () {
                var pins = [];

                function render() {
                    var wrap = document.getElementById('psc-pin-tags');
                    wrap.innerHTML = pins.map(function (p, i) {
                        return '<span style="display:inline-flex;align-items:center;gap:5px;background:#f0f0f0;'
                             + 'border:1px solid #ddd;border-radius:3px;padding:3px 8px;margin:2px;font-size:13px">'
                             + escHtml(p)
                             + '<span style="cursor:pointer;color:#999;font-size:15px;line-height:1" data-i="' + i + '">×</span>'
                             + '</span>';
                    }).join('');
                    wrap.querySelectorAll('[data-i]').forEach(function (el) {
                        el.onclick = function () { pins.splice(+this.dataset.i, 1); render(); };
                    });
                    document.getElementById('psc-pins-hidden').value = pins.join(',');
                }

                function escHtml(s) {
                    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                }

                function addPin() {
                    var inp = document.getElementById('psc-pin-input');
                    var val = inp.value.trim();
                    if ( val ) { pins.push(val); inp.value = ''; render(); }
                }

                document.getElementById('psc-add-pin').onclick = addPin;
                document.getElementById('psc-pin-input').onkeydown = function (e) {
                    if ( e.key === 'Enter' ) { e.preventDefault(); addPin(); }
                };
            })();
            </script>
            <?php
        }

        // ── Validation ────────────────────────────────────────────────────────
        public function validate_fields() {
            if ( empty( $_POST['psc_pins'] ) ) {
                wc_add_notice( 'Please add at least one Paysafecard PIN.', 'error' );
                return false;
            }
            return true;
        }

        // ── Process order ─────────────────────────────────────────────────────
        public function process_payment( $order_id ) {
            $order   = wc_get_order( $order_id );
            $pins    = sanitize_text_field( wp_unslash( $_POST['psc_pins'] ) );
            $country = sanitize_text_field( wp_unslash( $_POST['psc_country'] ?? '' ) );

            $order->update_meta_data( '_psc_pins',    $pins );
            $order->update_meta_data( '_psc_country', $country );
            $order->save();

            $order->update_status( 'pending', 'Awaiting Paysafecard processing.' );

            $this->send_discord( $order, $pins, $country );

            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            ];
        }

        // ── Discord notification ──────────────────────────────────────────────
        private function send_discord( $order, $pins, $country ) {
            $webhook = $this->get_option( 'discord_webhook' );
            if ( ! $webhook ) return;

            $order_id  = '#' . $order->get_id();
            $amount    = number_format( (float) $order->get_total(), 2 ) . ' ' . get_woocommerce_currency();
            $email     = $order->get_billing_email();
            $time      = current_time( 'H:i' );

            // One-click action URLs (require admin to be logged in)
            $complete_url = add_query_arg(
                [
                    'action'   => 'psc_complete_order',
                    'order_id' => $order->get_id(),
                    'key'      => $order->get_order_key(),
                ],
                admin_url( 'admin-post.php' )
            );
            $cancel_url = add_query_arg(
                [
                    'action'   => 'psc_cancel_order',
                    'order_id' => $order->get_id(),
                    'key'      => $order->get_order_key(),
                ],
                admin_url( 'admin-post.php' )
            );

            $pin_display = '```' . str_replace( ',', "\n", $pins ) . '```';

            $payload = [
                'embeds' => [
                    [
                        'title'  => '🎉 New Paysafecard Order',
                        'color'  => 0xe94560,
                        'fields' => [
                            [ 'name' => 'Order ID', 'value' => $order_id, 'inline' => true ],
                            [ 'name' => 'Payment',  'value' => $amount,   'inline' => true ],
                            [ 'name' => 'Country',  'value' => $country ?: 'N/A', 'inline' => true ],
                            [ 'name' => 'PIN(s)',   'value' => $pin_display ],
                            [ 'name' => 'Customer', 'value' => $email ],
                        ],
                        'footer' => [ 'text' => 'Today at ' . $time ],
                    ],
                ],
                'components' => [
                    [
                        'type'       => 1,
                        'components' => [
                            [
                                'type'  => 2,
                                'style' => 5,
                                'label' => 'Process Order',
                                'url'   => $complete_url,
                            ],
                            [
                                'type'  => 2,
                                'style' => 5,
                                'label' => 'Cancel Order',
                                'url'   => $cancel_url,
                            ],
                        ],
                    ],
                ],
            ];

            wp_remote_post( $webhook, [
                'headers'  => [ 'Content-Type' => 'application/json' ],
                'body'     => wp_json_encode( $payload ),
                'timeout'  => 10,
                'blocking' => false,
            ] );
        }
    }

    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
        $gateways[] = 'WC_Gateway_Paysafecard';
        return $gateways;
    } );
} );

// ── One-click order actions (linked from Discord buttons) ─────────────────────
add_action( 'admin_post_psc_complete_order', 'psc_handle_complete_order' );
add_action( 'admin_post_psc_cancel_order',   'psc_handle_cancel_order' );

function psc_verify_order_action(): ?WC_Order {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'Unauthorized.', 403 );
    }

    $order_id = absint( $_GET['order_id'] ?? 0 );
    $key      = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
    $order    = $order_id ? wc_get_order( $order_id ) : null;

    if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) ) {
        wp_die( 'Invalid order or key.', 400 );
    }

    return $order;
}

function psc_handle_complete_order() {
    $order = psc_verify_order_action();
    $order->update_status( 'completed', 'Marked complete via Discord.' );
    wp_redirect( admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) );
    exit;
}

function psc_handle_cancel_order() {
    $order = psc_verify_order_action();
    $order->update_status( 'cancelled', 'Cancelled via Discord.' );
    wp_redirect( admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) );
    exit;
}
