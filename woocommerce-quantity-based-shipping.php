<?php
/**
 * Plugin Name: WooCommerce Quantity Based Shipping
 * Description: Tiered shipping based on total cart quantity.
 * Version: 3.3.0
 * Author: ChatGPT
 * Text Domain: wc-quantity-based-shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Prevent direct access.
    exit;
}

// Initialize WooCommerce shipping method.
add_action( 'woocommerce_shipping_init', 'wc_quantity_based_shipping_init' );
// Add quick Settings link on Plugins page.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_quantity_based_shipping_action_links' );
// Add info tab under WooCommerce > Settings > Shipping.
add_filter( 'woocommerce_shipping_settings_tabs_array', 'wc_quantity_based_shipping_add_info_tab', 30 );
add_action( 'woocommerce_settings_tabs_wc_qbs_info', 'wc_quantity_based_shipping_render_info_tab' );

/**
 * Shipping method bootstrap.
 */
function wc_quantity_based_shipping_init() {
    if ( ! class_exists( 'WC_Shipping_Method' ) ) {
        return;
    }

    /**
     * Quantity tier-based shipping method.
     */
    class WC_Shipping_Quantity_Based extends WC_Shipping_Method {
        /**
         * Constructor.
         *
         * @param int $instance_id Zone instance ID.
         */
        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'wc_quantity_based_shipping';
            $this->method_title       = 'Quantity Based Shipping';
            $this->method_description = 'Calculate shipping based on total cart quantity.';
            $this->enabled            = 'yes';
            $this->title              = 'Shipping';
            $this->supports           = array( 'shipping-zones', 'instance-settings' );

            add_action( 'woocommerce_admin_field_qbs_rules', array( $this, 'render_rules_field' ) );

            parent::__construct( $instance_id );
            $this->init();
        }

        /**
         * Initialize settings.
         */
        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            $this->init_instance_settings();

            $this->title = $this->get_instance_option( 'shipping_name', $this->title );

            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        /**
         * Define settings fields.
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable this shipping method',
                    'default' => 'yes',
                ),
            );

            $this->instance_form_fields = array(
                'shipping_name'  => array(
                    'title'       => 'Shipping label',
                    'type'        => 'text',
                    'description' => 'Label shown on cart and checkout.',
                    'default'     => 'Shipping',
                ),
                'rules'          => array(
                    'title'       => 'Tier rules',
                    'type'        => 'qbs_rules',
                    'description' => 'Add tier rules using the table above. Shipping costs use the store currency settings (WooCommerce > Settings > Currency).',
                    'default'     => "1,10,5\n11,30,8\n31,50,12",
                ),
                'free_threshold' => array(
                    'title'       => 'Free shipping threshold',
                    'type'        => 'number',
                    'description' => 'Shipping cost is 0 when cart quantity is greater than or equal to this value. Use 0 to disable.',
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
         * @param array $package Shipping package.
         */
        public function calculate_shipping( $package = array() ) {
            $cart_quantity  = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
            $free_threshold = intval( $this->get_instance_option( 'free_threshold', 0 ) );

            if ( $free_threshold > 0 && $cart_quantity >= $free_threshold ) {
                $this->add_rate(
                    array(
                        'id'    => $this->id . ':' . $this->instance_id,
                        'label' => $this->title,
                        'cost'  => 0,
                    )
                );
                return;
            }

            $rules_text = $this->get_instance_option( 'rules', '' );
            $rules      = $this->parse_rules( $rules_text );
            $cost       = 0;

            foreach ( $rules as $rule ) {
                if ( $cart_quantity >= $rule['min'] && $cart_quantity <= $rule['max'] ) {
                    $cost = $rule['cost'];
                    break;
                }
            }

            $this->add_rate(
                array(
                    'id'    => $this->id . ':' . $this->instance_id,
                    'label' => $this->title,
                    'cost'  => $cost,
                )
            );
        }

        /**
         * Parse tier rules.
         *
         * @param string|array $rules_input Rules string/array.
         * @return array
         */
        private function parse_rules( $rules_input ) {
            $rules = array();
            $rows  = array();

            if ( is_array( $rules_input ) ) {
                $rows = $rules_input;
            } else {
                $lines = preg_split( '/\r\n|\r|\n/', trim( (string) $rules_input ) );
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

        /**
         * Render table-based rules field for zone instance settings.
         *
         * @param array $field Field config.
         */
        public function render_rules_field( $field ) {
            $raw_rules = $this->get_instance_option( 'rules', $field['default'] );
            $rules     = $this->parse_rules( $raw_rules );
            $field_key = $this->get_field_key( 'rules' );
            ?>
            <tr valign="top">
                <th scope="row"><?php echo esc_html( $field['title'] ); ?></th>
                <td>
                    <style>
                        .wc-qbs-rules-wrapper { max-width: 960px; }
                        .wc-qbs-rules-table th,
                        .wc-qbs-rules-table td { padding: 10px; vertical-align: middle; }
                        .wc-qbs-rules-table input[type="number"] { width: 100%; }
                        .wc-qbs-rules-actions { display: flex; gap: 12px; align-items: center; margin-top: 10px; }
                        .wc-qbs-rules-help { margin-top: 0; color: #666; }
                        .wc-qbs-rules-empty { color: #999; font-style: italic; }
                    </style>
                    <div class="wc-qbs-rules-wrapper">
                    <table class="widefat striped wc-qbs-rules-table" id="wc-qbs-rules-table">
                        <caption class="screen-reader-text"><?php echo esc_html__( 'Tier rules table', 'wc-quantity-based-shipping' ); ?></caption>
                        <thead>
                            <tr>
                                <th><?php echo esc_html__( 'Min quantity', 'wc-quantity-based-shipping' ); ?></th>
                                <th><?php echo esc_html__( 'Max quantity', 'wc-quantity-based-shipping' ); ?></th>
                                <th><?php echo esc_html__( 'Shipping cost', 'wc-quantity-based-shipping' ); ?></th>
                                <th><?php echo esc_html__( 'Actions', 'wc-quantity-based-shipping' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $rules ) ) : ?>
                                <tr class="wc-qbs-rules-empty">
                                    <td colspan="4"><?php echo esc_html__( 'No rules yet. Use “Add rule” to create the first tier.', 'wc-quantity-based-shipping' ); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ( $rules as $rule ) : ?>
                                    <tr>
                                        <td><input type="number" min="1" step="1" value="<?php echo esc_attr( $rule['min'] ); ?>"></td>
                                        <td><input type="number" min="1" step="1" value="<?php echo esc_attr( $rule['max'] ); ?>"></td>
                                        <td><input type="number" min="0" step="0.01" value="<?php echo esc_attr( $rule['cost'] ); ?>"></td>
                                        <td><button type="button" class="button wc-qbs-remove-row"><?php echo esc_html__( 'Remove', 'wc-quantity-based-shipping' ); ?></button></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="wc-qbs-rules-actions">
                        <button type="button" class="button button-secondary" id="wc-qbs-add-row"><?php echo esc_html__( 'Add rule', 'wc-quantity-based-shipping' ); ?></button>
                        <span class="wc-qbs-rules-help"><?php echo esc_html( $field['description'] ); ?></span>
                    </div>
                    </div>
                    <textarea name="<?php echo esc_attr( $field_key ); ?>" id="wc-qbs-rules-text" class="hidden" style="display:none;"><?php echo esc_textarea( is_string( $raw_rules ) ? $raw_rules : '' ); ?></textarea>
                </td>
            </tr>
            <script>
                (function () {
                    var table = document.getElementById('wc-qbs-rules-table');
                    var addButton = document.getElementById('wc-qbs-add-row');
                    var hiddenInput = document.getElementById('wc-qbs-rules-text');
                    if (!table || !addButton || !hiddenInput) {
                        return;
                    }

                    var serializeRules = function () {
                        var lines = [];
                        var rows = table.querySelectorAll('tbody tr');
                        rows.forEach(function (row) {
                            var inputs = row.querySelectorAll('input');
                            if (inputs.length < 3) {
                                return;
                            }
                            var min = inputs[0].value.trim();
                            var max = inputs[1].value.trim();
                            var cost = inputs[2].value.trim();
                            if (min && max && cost) {
                                lines.push([min, max, cost].join(','));
                            }
                        });
                        hiddenInput.value = lines.join('\n');
                    };

                    addButton.addEventListener('click', function () {
                        var row = document.createElement('tr');
                        row.innerHTML =
                            '<td><input type="number" min="1" step="1" value=""></td>' +
                            '<td><input type="number" min="1" step="1" value=""></td>' +
                            '<td><input type="number" min="0" step="0.01" value=""></td>' +
                            '<td><button type="button" class="button wc-qbs-remove-row"><?php echo esc_html__( 'Remove', 'wc-quantity-based-shipping' ); ?></button></td>';
                        var emptyRow = table.querySelector('.wc-qbs-rules-empty');
                        if (emptyRow) {
                            emptyRow.remove();
                        }
                        table.querySelector('tbody').appendChild(row);
                        serializeRules();
                    });

                    table.addEventListener('click', function (event) {
                        if (event.target && event.target.classList.contains('wc-qbs-remove-row')) {
                            event.preventDefault();
                            var row = event.target.closest('tr');
                            if (row) {
                                row.remove();
                                serializeRules();
                            }
                        }
                    });

                    table.addEventListener('input', function () {
                        serializeRules();
                    });

                    serializeRules();
                })();
            </script>
            <?php
        }
    }
}

/**
 * Register shipping method.
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
 * Add Settings quick-link in Plugins list.
 *
 * @param array $links Action links.
 * @return array
 */
function wc_quantity_based_shipping_action_links( $links ) {
    $settings_url = admin_url( 'admin.php?page=wc-settings&tab=shipping&section=zones' );
    $settings     = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'wc-quantity-based-shipping' ) . '</a>';
    array_unshift( $links, $settings );
    return $links;
}

/**
 * Add Shipping settings info tab.
 *
 * @param array $tabs Existing tabs.
 * @return array
 */
function wc_quantity_based_shipping_add_info_tab( $tabs ) {
    $tabs['wc_qbs_info'] = 'Quantity Shipping Info';
    return $tabs;
}

/**
 * Render info tab content.
 */
function wc_quantity_based_shipping_render_info_tab() {
    ?>
    <h2><?php echo esc_html__( 'Quantity Shipping Info', 'wc-quantity-based-shipping' ); ?></h2>
    <p><?php echo esc_html__( 'This shipping method is configured per Shipping Zone. Go to Shipping Zones to add the method and edit its tier rules.', 'wc-quantity-based-shipping' ); ?></p>
    <ol>
        <li><?php echo esc_html__( 'Open WooCommerce → Settings → Shipping → Shipping Zones.', 'wc-quantity-based-shipping' ); ?></li>
        <li><?php echo esc_html__( 'Add or edit a zone, then add “Quantity Based Shipping”.', 'wc-quantity-based-shipping' ); ?></li>
        <li><?php echo esc_html__( 'Use the table editor to define min/max quantity tiers and costs.', 'wc-quantity-based-shipping' ); ?></li>
    </ol>
    <?php
}
