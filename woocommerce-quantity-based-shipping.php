<?php
/**
 * Plugin Name: WooCommerce Quantity Based Shipping
 * Description: Tiered shipping based on total cart quantity.
 * Version: 1.0.0
 * Author: ChatGPT
 * Text Domain: wc-quantity-based-shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'woocommerce_shipping_init', 'wc_quantity_based_shipping_init' );
add_action( 'admin_menu', 'wc_quantity_based_shipping_admin_menu' );
add_action( 'admin_init', 'wc_quantity_based_shipping_register_settings' );

function wc_quantity_based_shipping_init() {
    if ( ! class_exists( 'WC_Shipping_Method' ) ) {
        return;
    }

    class WC_Shipping_Quantity_Based extends WC_Shipping_Method {
        /**
         * Constructor.
         */
        public function __construct() {
            $this->id                 = 'wc_quantity_based_shipping';
            $this->method_title       = 'Quantity Based Shipping';
            $this->method_description = 'Calculate shipping based on total cart quantity.';
            $this->enabled            = 'yes';
            $this->title              = 'Shipping';

            $this->init();
        }

        /**
         * Initialize settings.
         */
        public function init() {
            $this->init_form_fields();
            $this->init_settings();

            $option_title = get_option( 'wc_qbs_shipping_name', '' );
            $this->title  = $option_title ? $option_title : $this->get_option( 'shipping_name', $this->title );

            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        /**
         * Form fields for the shipping method screen.
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled'        => array(
                    'title'       => 'Enable/Disable',
                    'type'        => 'checkbox',
                    'label'       => 'Enable this shipping method',
                    'default'     => 'yes',
                ),
                'shipping_name'  => array(
                    'title'       => 'Shipping label',
                    'type'        => 'text',
                    'description' => 'Label shown on cart and checkout.',
                    'default'     => 'Shipping',
                    'desc_tip'    => true,
                ),
                'rules'          => array(
                    'title'       => 'Tier rules',
                    'type'        => 'textarea',
                    'description' => "One rule per line in the format: min,max,cost. Example:\n1,10,5\n11,30,8",
                    'default'     => "1,10,5\n11,30,8\n31,50,12",
                    'desc_tip'    => false,
                ),
                'free_threshold' => array(
                    'title'       => 'Free shipping threshold',
                    'type'        => 'number',
                    'description' => 'Shipping cost is 0 when cart quantity is greater than or equal to this value. Use 0 to disable.',
                    'default'     => '0',
                    'desc_tip'    => true,
                    'custom_attributes' => array(
                        'min'  => '0',
                        'step' => '1',
                    ),
                ),
            );
        }

        /**
         * Calculate shipping.
         *
         * @param array $package Cart package.
         */
        public function calculate_shipping( $package = array() ) {
            $cart_quantity  = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
            $free_threshold = intval( get_option( 'wc_qbs_free_threshold', $this->get_option( 'free_threshold', 0 ) ) );

            // Free shipping threshold reached.
            if ( $free_threshold > 0 && $cart_quantity >= $free_threshold ) {
                $this->add_rate( array(
                    'id'    => $this->id,
                    'label' => $this->title,
                    'cost'  => 0,
                ) );
                return;
            }

            $rules_text = get_option( 'wc_qbs_rules', $this->get_option( 'rules', '' ) );
            $rules      = $this->parse_rules( $rules_text );
            $cost       = 0;

            foreach ( $rules as $rule ) {
                if ( $cart_quantity >= $rule['min'] && $cart_quantity <= $rule['max'] ) {
                    $cost = $rule['cost'];
                    break;
                }
            }

            $this->add_rate( array(
                'id'    => $this->id,
                'label' => $this->title,
                'cost'  => $cost,
            ) );
        }

        /**
         * Parse tier rules.
         *
         * @param string $rules_text Rules text.
         * @return array
         */
        private function parse_rules( $rules_text ) {
            $rules = array();
            $lines = preg_split( '/\r\n|\r|\n/', trim( $rules_text ) );

            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( '' === $line ) {
                    continue;
                }

                $parts = array_map( 'trim', explode( ',', $line ) );
                if ( count( $parts ) < 3 ) {
                    continue;
                }

                $min  = intval( $parts[0] );
                $max  = intval( $parts[1] );
                $cost = floatval( $parts[2] );

                if ( $min <= 0 || $max <= 0 || $max < $min ) {
                    continue;
                }

                $rules[] = array(
                    'min'  => $min,
                    'max'  => $max,
                    'cost' => $cost,
                );
            }

            return $rules;
        }
    }
}

add_filter( 'woocommerce_shipping_methods', 'wc_quantity_based_shipping_add_method' );

function wc_quantity_based_shipping_add_method( $methods ) {
    $methods['wc_quantity_based_shipping'] = 'WC_Shipping_Quantity_Based';
    return $methods;
}

/**
 * Register settings for the dedicated settings page.
 */
function wc_quantity_based_shipping_register_settings() {
    register_setting(
        'wc_qbs_settings',
        'wc_qbs_rules',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'wc_quantity_based_shipping_sanitize_rules',
            'default'           => "1,10,5\n11,30,8\n31,50,12",
        )
    );

    register_setting(
        'wc_qbs_settings',
        'wc_qbs_free_threshold',
        array(
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        )
    );

    register_setting(
        'wc_qbs_settings',
        'wc_qbs_shipping_name',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'Shipping',
        )
    );
}

/**
 * Sanitize tier rules input.
 *
 * @param string $value Raw rules.
 * @return string
 */
function wc_quantity_based_shipping_sanitize_rules( $value ) {
    $lines = preg_split( '/\r\n|\r|\n/', (string) $value );
    $clean = array();

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( '' === $line ) {
            continue;
        }

        $parts = array_map( 'trim', explode( ',', $line ) );
        if ( count( $parts ) < 3 ) {
            continue;
        }

        $min  = absint( $parts[0] );
        $max  = absint( $parts[1] );
        $cost = floatval( $parts[2] );

        if ( $min <= 0 || $max <= 0 || $max < $min ) {
            continue;
        }

        $clean[] = $min . ',' . $max . ',' . $cost;
    }

    return implode( "\n", $clean );
}

/**
 * Add the settings page under WooCommerce.
 */
function wc_quantity_based_shipping_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'Quantity Based Shipping',
        'Quantity Shipping',
        'manage_woocommerce',
        'wc-quantity-based-shipping',
        'wc_quantity_based_shipping_render_settings_page'
    );
}

/**
 * Render settings page.
 */
function wc_quantity_based_shipping_render_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Quantity Based Shipping', 'wc-quantity-based-shipping' ); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'wc_qbs_settings' );
            do_settings_sections( 'wc_qbs_settings' );
            ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="wc_qbs_shipping_name"><?php echo esc_html__( 'Shipping label', 'wc-quantity-based-shipping' ); ?></label>
                        </th>
                        <td>
                            <input name="wc_qbs_shipping_name" id="wc_qbs_shipping_name" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'wc_qbs_shipping_name', 'Shipping' ) ); ?>">
                            <p class="description"><?php echo esc_html__( 'Label shown on cart and checkout.', 'wc-quantity-based-shipping' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wc_qbs_rules"><?php echo esc_html__( 'Tier rules', 'wc-quantity-based-shipping' ); ?></label>
                        </th>
                        <td>
                            <textarea name="wc_qbs_rules" id="wc_qbs_rules" class="large-text code" rows="6"><?php echo esc_textarea( get_option( 'wc_qbs_rules', "1,10,5\n11,30,8\n31,50,12" ) ); ?></textarea>
                            <p class="description"><?php echo esc_html__( 'One rule per line in the format: min,max,cost. Example: 1,10,5', 'wc-quantity-based-shipping' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wc_qbs_free_threshold"><?php echo esc_html__( 'Free shipping threshold', 'wc-quantity-based-shipping' ); ?></label>
                        </th>
                        <td>
                            <input name="wc_qbs_free_threshold" id="wc_qbs_free_threshold" type="number" min="0" step="1" value="<?php echo esc_attr( get_option( 'wc_qbs_free_threshold', 0 ) ); ?>">
                            <p class="description"><?php echo esc_html__( 'Shipping cost is 0 when cart quantity is greater than or equal to this value. Use 0 to disable.', 'wc-quantity-based-shipping' ); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
