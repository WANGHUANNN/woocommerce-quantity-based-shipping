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
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_quantity_based_shipping_action_links' );

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

            $stored_rules = get_option( 'wc_qbs_rules', array() );
            $rules        = $this->parse_rules( $stored_rules );
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
         * @param array|string $rules_input Rules input.
         * @return array
         */
        private function parse_rules( $rules_input ) {
            $rules = array();
            $rows  = array();

            if ( is_array( $rules_input ) ) {
                $rows = $rules_input;
            } elseif ( is_string( $rules_input ) ) {
                $lines = preg_split( '/\r\n|\r|\n/', trim( $rules_input ) );
                foreach ( $lines as $line ) {
                    $line = trim( $line );
                    if ( '' === $line ) {
                        continue;
                    }

                    $parts = array_map( 'trim', explode( ',', $line ) );
                    if ( count( $parts ) < 3 ) {
                        continue;
                    }

                    $rows[] = array(
                        'min'  => $parts[0],
                        'max'  => $parts[1],
                        'cost' => $parts[2],
                    );
                }
            }

            foreach ( $rows as $row ) {
                $min  = isset( $row['min'] ) ? intval( $row['min'] ) : 0;
                $max  = isset( $row['max'] ) ? intval( $row['max'] ) : 0;
                $cost = isset( $row['cost'] ) ? floatval( $row['cost'] ) : 0;

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
            'type'              => 'array',
            'sanitize_callback' => 'wc_quantity_based_shipping_sanitize_rules',
            'default'           => array(
                array(
                    'min'  => 1,
                    'max'  => 10,
                    'cost' => 5,
                ),
                array(
                    'min'  => 11,
                    'max'  => 30,
                    'cost' => 8,
                ),
                array(
                    'min'  => 31,
                    'max'  => 50,
                    'cost' => 12,
                ),
            ),
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
 * @param array $value Raw rules.
 * @return array
 */
function wc_quantity_based_shipping_sanitize_rules( $value ) {
    $clean = array();
    $rows  = is_array( $value ) ? $value : array();

    foreach ( $rows as $row ) {
        $min  = isset( $row['min'] ) ? absint( $row['min'] ) : 0;
        $max  = isset( $row['max'] ) ? absint( $row['max'] ) : 0;
        $cost = isset( $row['cost'] ) ? floatval( $row['cost'] ) : 0;

        if ( $min <= 0 || $max <= 0 || $max < $min ) {
            continue;
        }

        $clean[] = array(
            'min'  => $min,
            'max'  => $max,
            'cost' => $cost,
        );
    }

    return $clean;
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
 * Add a settings link on the Plugins screen.
 *
 * @param array $links Action links.
 * @return array
 */
function wc_quantity_based_shipping_action_links( $links ) {
    $settings_url = admin_url( 'admin.php?page=wc-quantity-based-shipping' );
    $settings     = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'wc-quantity-based-shipping' ) . '</a>';
    array_unshift( $links, $settings );
    return $links;
}

/**
 * Render settings page.
 */
function wc_quantity_based_shipping_render_settings_page() {
    $default_rules = array(
        array(
            'min'  => 1,
            'max'  => 10,
            'cost' => 5,
        ),
        array(
            'min'  => 11,
            'max'  => 30,
            'cost' => 8,
        ),
        array(
            'min'  => 31,
            'max'  => 50,
            'cost' => 12,
        ),
    );
    $rules = get_option( 'wc_qbs_rules', $default_rules );
    if ( empty( $rules ) ) {
        $rules = $default_rules;
    }
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
                            <table class="widefat striped" id="wc-qbs-rules-table">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__( 'Min quantity', 'wc-quantity-based-shipping' ); ?></th>
                                        <th><?php echo esc_html__( 'Max quantity', 'wc-quantity-based-shipping' ); ?></th>
                                        <th><?php echo esc_html__( 'Shipping cost', 'wc-quantity-based-shipping' ); ?></th>
                                        <th><?php echo esc_html__( 'Actions', 'wc-quantity-based-shipping' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $rules as $index => $rule ) : ?>
                                        <tr>
                                            <td>
                                                <input type="number" min="1" step="1" name="wc_qbs_rules[<?php echo esc_attr( $index ); ?>][min]" value="<?php echo esc_attr( isset( $rule['min'] ) ? $rule['min'] : '' ); ?>">
                                            </td>
                                            <td>
                                                <input type="number" min="1" step="1" name="wc_qbs_rules[<?php echo esc_attr( $index ); ?>][max]" value="<?php echo esc_attr( isset( $rule['max'] ) ? $rule['max'] : '' ); ?>">
                                            </td>
                                            <td>
                                                <input type="number" min="0" step="0.01" name="wc_qbs_rules[<?php echo esc_attr( $index ); ?>][cost]" value="<?php echo esc_attr( isset( $rule['cost'] ) ? $rule['cost'] : '' ); ?>">
                                            </td>
                                            <td>
                                                <button type="button" class="button wc-qbs-remove-row"><?php echo esc_html__( 'Remove', 'wc-quantity-based-shipping' ); ?></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p>
                                <button type="button" class="button" id="wc-qbs-add-row"><?php echo esc_html__( 'Add rule', 'wc-quantity-based-shipping' ); ?></button>
                            </p>
                            <p class="description"><?php echo esc_html__( 'Add tier rules using the table above. Shipping costs use the store currency settings (WooCommerce > Settings > Currency).', 'wc-quantity-based-shipping' ); ?></p>
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
    <script>
        (function () {
            var table = document.getElementById('wc-qbs-rules-table');
            var addButton = document.getElementById('wc-qbs-add-row');
            if (!table || !addButton) {
                return;
            }

            var getNextIndex = function () {
                var rows = table.querySelectorAll('tbody tr');
                return rows.length;
            };

            addButton.addEventListener('click', function () {
                var index = getNextIndex();
                var row = document.createElement('tr');
                row.innerHTML =
                    '<td><input type="number" min="1" step="1" name="wc_qbs_rules[' + index + '][min]" value=""></td>' +
                    '<td><input type="number" min="1" step="1" name="wc_qbs_rules[' + index + '][max]" value=""></td>' +
                    '<td><input type="number" min="0" step="0.01" name="wc_qbs_rules[' + index + '][cost]" value=""></td>' +
                    '<td><button type="button" class="button wc-qbs-remove-row"><?php echo esc_html__( 'Remove', 'wc-quantity-based-shipping' ); ?></button></td>';
                table.querySelector('tbody').appendChild(row);
            });

            table.addEventListener('click', function (event) {
                if (event.target && event.target.classList.contains('wc-qbs-remove-row')) {
                    event.preventDefault();
                    var row = event.target.closest('tr');
                    if (row) {
                        row.remove();
                    }
                }
            });
        })();
    </script>
    <?php
}
