<?php
/**
 * Plugin Name: WooCommerce Quantity Based Shipping
 * Description: Tiered shipping based on total cart quantity.
 * Version: 1.0.0
 * Author: ChatGPT
 * Text Domain: wc-quantity-based-shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Prevent direct access to the file for security.
    exit;
}

/**
 * Hook into WooCommerce shipping initialization to register the method.
 */
add_action( 'woocommerce_shipping_init', 'wc_quantity_based_shipping_init' );
/**
 * Add a Settings link in the Plugins list for quick access.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_quantity_based_shipping_action_links' );

/**
 * Shipping method bootstrap.
 *
 * Defines and registers the shipping method class used by WooCommerce.
 */
function wc_quantity_based_shipping_init() {
    if ( ! class_exists( 'WC_Shipping_Method' ) ) {
        // Abort if WooCommerce shipping base class is not available.
        return;
    }

    /**
     * Shipping method: Quantity based tiers.
     */
    class WC_Shipping_Quantity_Based extends WC_Shipping_Method {
        /**
         * Constructor.
         */
        public function __construct() {
            // Unique method ID used internally by WooCommerce.
            $this->id                 = 'wc_quantity_based_shipping';
            // Display name in the shipping methods list.
            $this->method_title       = 'Quantity Based Shipping';
            // Short description displayed in the admin.
            $this->method_description = 'Calculate shipping based on total cart quantity.';
            // Default status.
            $this->enabled            = 'yes';
            // Default label shown to customers at checkout.
            $this->title              = 'Shipping';

            $this->init();
        }

        /**
         * Initialize settings.
         */
        public function init() {
            // Define settings fields.
            $this->init_form_fields();
            // Load saved settings.
            $this->init_settings();

            // Prefer the dedicated shipping-method settings label if set.
            $option_title = get_option( 'wc_qbs_shipping_name', '' );
            $this->title  = $option_title ? $option_title : $this->title;

            // Hook to save shipping method settings in WooCommerce.
            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        /**
         * Form fields for the shipping method screen.
         */
        public function init_form_fields() {
            // These fields appear under WooCommerce > Settings > Shipping.
            $this->form_fields = array(
                'enabled'        => array(
                    'title'       => 'Enable/Disable',
                    'type'        => 'checkbox',
                    'label'       => 'Enable this shipping method',
                    'default'     => 'yes',
                ),
            );
        }

        /**
         * Render the shipping method settings page with the table editor.
         */
        public function admin_options() {
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
            <h2><?php echo esc_html( $this->method_title ); ?></h2>
            <table class="form-table">
                <?php
                // Render standard fields like Enable/Disable.
                $this->generate_settings_html( $this->form_fields );
                ?>
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
                        <!-- Rules table (each row is a tier). -->
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
                        <!-- Currency note: uses WooCommerce store currency. -->
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
            </table>
            <script>
                // Simple UI for adding/removing rule rows without a page reload.
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

        /**
         * Save settings from the shipping method page.
         */
        public function process_admin_options() {
            // Save standard fields like Enable/Disable.
            parent::process_admin_options();

            // Save the custom fields from the table editor.
            $rules_input = isset( $_POST['wc_qbs_rules'] ) ? wp_unslash( $_POST['wc_qbs_rules'] ) : array();
            $rules       = wc_quantity_based_shipping_sanitize_rules( $rules_input );
            update_option( 'wc_qbs_rules', $rules );

            $free_threshold = isset( $_POST['wc_qbs_free_threshold'] ) ? absint( wp_unslash( $_POST['wc_qbs_free_threshold'] ) ) : 0;
            update_option( 'wc_qbs_free_threshold', $free_threshold );

            $shipping_name = isset( $_POST['wc_qbs_shipping_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_qbs_shipping_name'] ) ) : 'Shipping';
            update_option( 'wc_qbs_shipping_name', $shipping_name );
        }

        /**
         * Calculate shipping.
         *
         * @param array $package Cart package.
         */
        public function calculate_shipping( $package = array() ) {
            // Total item quantity in the cart.
            $cart_quantity  = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
            // Free shipping threshold, stored in settings.
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

            // Tier rules stored as structured data (array of rows).
            $stored_rules = get_option( 'wc_qbs_rules', array() );
            // Normalize rules into a validated array.
            $rules        = $this->parse_rules( $stored_rules );
            $cost       = 0;

            // Find the first matching tier.
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

            // Accept both array-based rules and legacy comma-separated strings.
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

            // Normalize and validate each row.
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

/**
 * Register the shipping method with WooCommerce.
 *
 * @param array $methods Existing methods.
 * @return array
 */
add_filter( 'woocommerce_shipping_methods', 'wc_quantity_based_shipping_add_method' );

function wc_quantity_based_shipping_add_method( $methods ) {
    $methods['wc_quantity_based_shipping'] = 'WC_Shipping_Quantity_Based';
    return $methods;
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

    // Validate and normalize each row before saving.
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
 * Add a settings link on the Plugins screen.
 *
 * @param array $links Action links.
 * @return array
 */
function wc_quantity_based_shipping_action_links( $links ) {
    // Link goes to the shipping method settings page.
    $settings_url = admin_url( 'admin.php?page=wc-settings&tab=shipping&section=wc_quantity_based_shipping' );
    $settings     = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'wc-quantity-based-shipping' ) . '</a>';
    array_unshift( $links, $settings );
    return $links;
}
