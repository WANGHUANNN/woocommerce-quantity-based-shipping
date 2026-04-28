<?php
/**
 * Plugin Name: WordPress ALT Generator (AI + Rules Hybrid)
 * Description: Bulk generate image ALT text with rules-first logic and AI fallback for the WordPress Media Library.
 * Version: 1.0.0
 * Author: ChatGPT
 * Text Domain: wp-alt-generator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_ALT_Generator_Plugin' ) ) {
    class WP_ALT_Generator_Plugin {
        const OPTION_KEY         = 'wp_alt_generator_settings';
        const CRON_HOOK          = 'wp_alt_generator_cron_process';
        const NONCE_ACTION       = 'wp_alt_generator_action';
        const BATCH_DEFAULT      = 50;
        const MAX_ALT_LENGTH     = 120;
        const QUEUE_STATUS_PENDING    = 'pending';
        const QUEUE_STATUS_PROCESSING = 'processing';
        const QUEUE_STATUS_DONE       = 'done';
        const QUEUE_STATUS_FAILED     = 'failed';

        private static $instance = null;

        /**
         * Get singleton.
         *
         * @return WP_ALT_Generator_Plugin
         */
        public static function instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Constructor.
         */
        private function __construct() {
            add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_post_wp_alt_generator_scan', array( $this, 'handle_scan_queue' ) );
            add_action( 'admin_post_wp_alt_generator_start', array( $this, 'handle_start' ) );
            add_action( 'admin_post_wp_alt_generator_pause', array( $this, 'handle_pause' ) );
            add_action( 'admin_post_wp_alt_generator_resume', array( $this, 'handle_resume' ) );
            add_action( 'admin_post_wp_alt_generator_stop', array( $this, 'handle_stop' ) );
            add_action( 'admin_post_wp_alt_generator_single', array( $this, 'handle_single_generate' ) );

            add_action( self::CRON_HOOK, array( $this, 'process_queue_batch' ) );
            add_action( 'add_attachment', array( $this, 'auto_generate_on_upload' ) );

            add_filter( 'manage_upload_columns', array( $this, 'add_media_columns' ) );
            add_action( 'manage_media_custom_column', array( $this, 'render_media_columns' ), 10, 2 );
            add_filter( 'media_row_actions', array( $this, 'add_media_row_action' ), 10, 2 );

            if ( defined( 'WP_CLI' ) && WP_CLI ) {
                \WP_CLI::add_command( 'alt-generator run', array( $this, 'cli_run' ) );
            }
        }

        /**
         * Activation hook.
         */
        public static function activate() {
            self::instance()->create_tables();

            if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                wp_schedule_event( time() + MINUTE_IN_SECONDS, 'minute', self::CRON_HOOK );
            }
        }

        /**
         * Deactivation hook.
         */
        public static function deactivate() {
            wp_clear_scheduled_hook( self::CRON_HOOK );
        }

        /**
         * Register custom schedule.
         *
         * @param array $schedules Existing schedules.
         * @return array
         */
        public function register_schedule( $schedules ) {
            if ( ! isset( $schedules['minute'] ) ) {
                $schedules['minute'] = array(
                    'interval' => MINUTE_IN_SECONDS,
                    'display'  => __( 'Every Minute', 'wp-alt-generator' ),
                );
            }

            return $schedules;
        }

        /**
         * Create queue table.
         */
        public function create_tables() {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $table_name      = $this->queue_table();
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$table_name} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                attachment_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                alt_result TEXT NULL,
                source_filename VARCHAR(255) NULL,
                error_message TEXT NULL,
                updated_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY attachment_id_unique (attachment_id),
                KEY status_idx (status)
            ) {$charset_collate};";

            dbDelta( $sql );
        }

        /**
         * Get default settings.
         *
         * @return array
         */
        private function default_settings() {
            return array(
                'enable_rules'        => 1,
                'template'            => '{Brand} {Model} {Puffs} disposable vape device',
                'enable_ai'           => 1,
                'openai_api_key'      => '',
                'openai_model'        => 'gpt-5-mini',
                'ai_limit_per_batch'  => 5,
                'batch_size'          => self::BATCH_DEFAULT,
                'strategy'            => 'only_empty',
                'status'              => 'idle',
                'sleep_seconds'       => 1,
                'filename_cache'      => array(),
                'last_run_logs'       => array(),
                'processed_count'     => 0,
                'failed_count'        => 0,
                'last_api_error'      => '',
            );
        }

        /**
         * Get settings.
         *
         * @return array
         */
        private function get_settings() {
            $settings = get_option( self::OPTION_KEY, array() );
            return wp_parse_args( $settings, $this->default_settings() );
        }

        /**
         * Save settings.
         *
         * @param array $settings Settings.
         */
        private function save_settings( $settings ) {
            update_option( self::OPTION_KEY, $settings, false );
        }

        /**
         * Register settings.
         */
        public function register_settings() {
            register_setting(
                'wp_alt_generator_settings_group',
                self::OPTION_KEY,
                array( $this, 'sanitize_settings' )
            );
        }

        /**
         * Sanitize settings.
         *
         * @param array $input Input.
         * @return array
         */
        public function sanitize_settings( $input ) {
            $settings = $this->get_settings();

            $settings['enable_rules']       = ! empty( $input['enable_rules'] ) ? 1 : 0;
            $settings['template']           = sanitize_text_field( $input['template'] ?? $settings['template'] );
            $settings['enable_ai']          = ! empty( $input['enable_ai'] ) ? 1 : 0;
            $settings['openai_api_key']     = sanitize_text_field( $input['openai_api_key'] ?? '' );
            $settings['openai_model']       = sanitize_text_field( $input['openai_model'] ?? 'gpt-5-mini' );
            $settings['ai_limit_per_batch'] = max( 1, min( 10, absint( $input['ai_limit_per_batch'] ?? 5 ) ) );
            $settings['batch_size']         = max( 20, min( 100, absint( $input['batch_size'] ?? self::BATCH_DEFAULT ) ) );
            $settings['sleep_seconds']      = max( 0, min( 5, absint( $input['sleep_seconds'] ?? 1 ) ) );

            $allowed_strategy = array( 'only_empty', 'overwrite', 'skip_existing' );
            $strategy         = sanitize_key( $input['strategy'] ?? 'only_empty' );
            $settings['strategy'] = in_array( $strategy, $allowed_strategy, true ) ? $strategy : 'only_empty';

            return $settings;
        }

        /**
         * Register tools page.
         */
        public function register_admin_page() {
            add_management_page(
                __( 'ALT Generator', 'wp-alt-generator' ),
                __( 'ALT Generator', 'wp-alt-generator' ),
                'manage_options',
                'wp-alt-generator',
                array( $this, 'render_admin_page' )
            );
        }

        /**
         * Render settings and dashboard.
         */
        public function render_admin_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $settings = $this->get_settings();
            $stats    = $this->get_library_stats();
            $queue    = $this->get_queue_stats();
            ?>
            <div class="wrap">
                <h1><?php echo esc_html__( 'ALT Generator (AI + Rules)', 'wp-alt-generator' ); ?></h1>

                <h2><?php echo esc_html__( 'Dashboard', 'wp-alt-generator' ); ?></h2>
                <ul>
                    <li><?php echo esc_html( sprintf( 'Total images: %d', (int) $stats['total_images'] ) ); ?></li>
                    <li><?php echo esc_html( sprintf( 'Without ALT: %d', (int) $stats['without_alt'] ) ); ?></li>
                    <li><?php echo esc_html( sprintf( 'Processed queue items: %d', (int) $queue['done'] ) ); ?></li>
                    <li><?php echo esc_html( sprintf( 'Failed queue items: %d', (int) $queue['failed'] ) ); ?></li>
                    <li><?php echo esc_html( sprintf( 'Current status: %s', $settings['status'] ) ); ?></li>
                </ul>

                <p>
                    <?php $this->render_action_button( 'wp_alt_generator_scan', 'Scan Media Library' ); ?>
                    <?php $this->render_action_button( 'wp_alt_generator_start', 'Start Batch Generation' ); ?>
                    <?php $this->render_action_button( 'wp_alt_generator_pause', 'Pause' ); ?>
                    <?php $this->render_action_button( 'wp_alt_generator_resume', 'Resume' ); ?>
                    <?php $this->render_action_button( 'wp_alt_generator_stop', 'Stop' ); ?>
                </p>

                <h2><?php echo esc_html__( 'Settings', 'wp-alt-generator' ); ?></h2>
                <form method="post" action="options.php">
                    <?php settings_fields( 'wp_alt_generator_settings_group' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Enable rules</th>
                            <td><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_rules]" value="1" <?php checked( 1, (int) $settings['enable_rules'] ); ?>></td>
                        </tr>
                        <tr>
                            <th scope="row">Rule template</th>
                            <td><input class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[template]" value="<?php echo esc_attr( $settings['template'] ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">Enable AI fallback</th>
                            <td><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_ai]" value="1" <?php checked( 1, (int) $settings['enable_ai'] ); ?>></td>
                        </tr>
                        <tr>
                            <th scope="row">OpenAI API Key</th>
                            <td><input class="regular-text" type="password" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openai_api_key]" value="<?php echo esc_attr( $settings['openai_api_key'] ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">AI model</th>
                            <td><input class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openai_model]" value="<?php echo esc_attr( $settings['openai_model'] ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">AI limit per batch</th>
                            <td><input type="number" min="1" max="10" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ai_limit_per_batch]" value="<?php echo esc_attr( (string) $settings['ai_limit_per_batch'] ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">Batch size</th>
                            <td><input type="number" min="20" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[batch_size]" value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">Delay seconds</th>
                            <td><input type="number" min="0" max="5" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sleep_seconds]" value="<?php echo esc_attr( (string) $settings['sleep_seconds'] ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">Processing strategy</th>
                            <td>
                                <label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[strategy]" value="only_empty" <?php checked( 'only_empty', $settings['strategy'] ); ?>> Only images without ALT</label><br>
                                <label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[strategy]" value="overwrite" <?php checked( 'overwrite', $settings['strategy'] ); ?>> Overwrite existing ALT</label><br>
                                <label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[strategy]" value="skip_existing" <?php checked( 'skip_existing', $settings['strategy'] ); ?>> Skip existing ALT</label>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>

                <h2><?php echo esc_html__( 'Recent Logs', 'wp-alt-generator' ); ?></h2>
                <pre style="background:#fff;padding:12px;border:1px solid #ddd;max-height:300px;overflow:auto;"><?php
                $logs = $settings['last_run_logs'];
                if ( empty( $logs ) ) {
                    echo esc_html__( 'No logs yet.', 'wp-alt-generator' );
                } else {
                    echo esc_html( implode( "\n", array_slice( $logs, -20 ) ) );
                }
                ?></pre>
                <?php if ( ! empty( $settings['last_api_error'] ) ) : ?>
                    <p><strong>Last API error:</strong> <?php echo esc_html( $settings['last_api_error'] ); ?></p>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * Render admin action form.
         *
         * @param string $action Action.
         * @param string $label Label.
         */
        private function render_action_button( $action, $label ) {
            ?>
            <form style="display:inline-block;margin-right:8px;" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
                <button class="button button-secondary" type="submit"><?php echo esc_html( $label ); ?></button>
            </form>
            <?php
        }

        /**
         * Queue scan action.
         */
        public function handle_scan_queue() {
            $this->guard_admin_post();

            $settings = $this->get_settings();
            $strategy = $settings['strategy'];

            $attachment_ids = get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/jpg' ),
                    'posts_per_page' => -1,
                    'post_status'    => 'inherit',
                    'fields'         => 'ids',
                )
            );

            foreach ( $attachment_ids as $attachment_id ) {
                $existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

                if ( 'only_empty' === $strategy && ! empty( $existing_alt ) ) {
                    continue;
                }

                if ( 'skip_existing' === $strategy && ! empty( $existing_alt ) ) {
                    continue;
                }

                $this->upsert_queue_item( $attachment_id, self::QUEUE_STATUS_PENDING );
            }

            $this->append_log( 'Scan completed and queue updated.' );
            $this->redirect_back();
        }

        /**
         * Start processing.
         */
        public function handle_start() {
            $this->guard_admin_post();
            $settings           = $this->get_settings();
            $settings['status'] = 'running';
            $this->save_settings( $settings );
            $this->append_log( 'Processing started.' );
            $this->process_queue_batch();
            $this->redirect_back();
        }

        /**
         * Pause processing.
         */
        public function handle_pause() {
            $this->guard_admin_post();
            $settings           = $this->get_settings();
            $settings['status'] = 'paused';
            $this->save_settings( $settings );
            $this->append_log( 'Processing paused.' );
            $this->redirect_back();
        }

        /**
         * Resume processing.
         */
        public function handle_resume() {
            $this->guard_admin_post();
            $settings           = $this->get_settings();
            $settings['status'] = 'running';
            $this->save_settings( $settings );
            $this->append_log( 'Processing resumed.' );
            $this->process_queue_batch();
            $this->redirect_back();
        }

        /**
         * Stop processing.
         */
        public function handle_stop() {
            $this->guard_admin_post();
            $settings           = $this->get_settings();
            $settings['status'] = 'stopped';
            $this->save_settings( $settings );
            $this->append_log( 'Processing stopped.' );
            $this->redirect_back();
        }

        /**
         * Process batch by cron/UI/CLI.
         *
         * @param int|null $limit Limit.
         * @param int|null $offset Offset.
         * @return array
         */
        public function process_queue_batch( $limit = null, $offset = null ) {
            global $wpdb;

            $settings = $this->get_settings();
            if ( ! in_array( $settings['status'], array( 'running', 'idle' ), true ) ) {
                return array( 'processed' => 0, 'failed' => 0 );
            }

            $batch_size = $limit ? max( 1, absint( $limit ) ) : max( 20, min( 100, absint( $settings['batch_size'] ) ) );
            $offset     = is_null( $offset ) ? 0 : max( 0, absint( $offset ) );

            $table = $this->queue_table();

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE status IN (%s, %s) ORDER BY id ASC LIMIT %d OFFSET %d",
                    self::QUEUE_STATUS_PENDING,
                    self::QUEUE_STATUS_FAILED,
                    $batch_size,
                    $offset
                )
            );

            if ( empty( $rows ) ) {
                $this->append_log( 'No pending queue items.' );
                return array( 'processed' => 0, 'failed' => 0 );
            }

            $ai_used = 0;
            $done    = 0;
            $failed  = 0;

            foreach ( $rows as $row ) {
                $this->update_queue_status( (int) $row->attachment_id, self::QUEUE_STATUS_PROCESSING );

                $result = $this->generate_alt_for_attachment( (int) $row->attachment_id, $settings, $ai_used );

                if ( $result['success'] ) {
                    update_post_meta( (int) $row->attachment_id, '_wp_attachment_image_alt', $result['alt'] );
                    $this->update_queue_status( (int) $row->attachment_id, self::QUEUE_STATUS_DONE, $result['alt'], '' );
                    $done++;
                    $this->append_log( 'Generated ALT for attachment #' . (int) $row->attachment_id . ': ' . $result['alt'] );
                } else {
                    $failed++;
                    $this->increase_attempts( (int) $row->attachment_id, $result['error'] );
                    $this->append_log( 'Failed attachment #' . (int) $row->attachment_id . ': ' . $result['error'] );
                }

                if ( $settings['sleep_seconds'] > 0 ) {
                    sleep( (int) $settings['sleep_seconds'] );
                }
            }

            $settings['processed_count'] += $done;
            $settings['failed_count']    += $failed;
            $this->save_settings( $settings );

            return array( 'processed' => $done, 'failed' => $failed );
        }

        /**
         * Generate single attachment ALT.
         *
         * @param int   $attachment_id Attachment ID.
         * @param array $settings Settings.
         * @param int   $ai_used AI used in current batch (reference).
         * @return array
         */
        private function generate_alt_for_attachment( $attachment_id, $settings, &$ai_used ) {
            $file_path = get_attached_file( $attachment_id );
            if ( empty( $file_path ) ) {
                return array( 'success' => false, 'error' => 'Missing attached file.' );
            }

            $filename = strtolower( wp_basename( $file_path ) );

            if ( isset( $settings['filename_cache'][ $filename ] ) ) {
                return array( 'success' => true, 'alt' => $settings['filename_cache'][ $filename ] );
            }

            $existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
            $strategy     = $settings['strategy'];

            if ( 'skip_existing' === $strategy && ! empty( $existing_alt ) ) {
                return array( 'success' => true, 'alt' => $existing_alt );
            }

            $rule_alt = $this->generate_rule_alt( $filename );
            $rule_alt = $this->normalize_alt( $rule_alt );

            $needs_ai = $this->should_use_ai( $filename, $rule_alt );
            $alt      = $rule_alt;

            if ( $needs_ai && ! empty( $settings['enable_ai'] ) && $ai_used < (int) $settings['ai_limit_per_batch'] ) {
                $ai_used++;
                $ai_alt = $this->generate_ai_alt( $filename, $settings );
                if ( ! empty( $ai_alt['success'] ) ) {
                    $alt = $this->normalize_alt( $ai_alt['alt'] );
                } else {
                    $settings['last_api_error'] = $ai_alt['error'];
                    $this->save_settings( $settings );
                }
            }

            if ( empty( $alt ) ) {
                return array( 'success' => false, 'error' => 'ALT generation returned empty result.' );
            }

            $alt = $this->ensure_unique_alt( $alt, $attachment_id );

            $settings['filename_cache'][ $filename ] = $alt;
            $this->save_settings( $settings );

            return array( 'success' => true, 'alt' => $alt );
        }

        /**
         * Rule-based ALT generation.
         *
         * @param string $filename Filename.
         * @return string
         */
        private function generate_rule_alt( $filename ) {
            $name = preg_replace( '/\.[a-z0-9]+$/i', '', $filename );
            $name = preg_replace( '/[-_]?\d{4,}$/', '', $name );
            $name = str_replace( array( '-', '_' ), ' ', $name );
            $name = strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ) );

            if ( empty( $name ) ) {
                return '';
            }

            $map = array(
                'smartscreen' => 'smart screen',
                'smartscreen' => 'smart screen',
                'led'         => 'LED display',
                'kit'         => 'vape kit',
            );

            $words = explode( ' ', $name );
            foreach ( $words as &$word ) {
                if ( isset( $map[ $word ] ) ) {
                    $word = $map[ $word ];
                }
            }
            unset( $word );

            $name = implode( ' ', $words );
            $name = preg_replace( '/\s+/', ' ', $name );
            $name = ucwords( trim( $name ) );

            if ( ! preg_match( '/(device|kit|display)/i', $name ) ) {
                $name .= ' disposable vape device';
            }

            return trim( $name );
        }

        /**
         * Determine if AI should be used.
         *
         * @param string $filename Filename.
         * @param string $alt Rule ALT.
         * @return bool
         */
        private function should_use_ai( $filename, $alt ) {
            $word_count = str_word_count( wp_strip_all_tags( (string) $alt ) );
            if ( $word_count < 5 ) {
                return true;
            }

            if ( preg_match( '/(detail|close\-up|lifestyle)/i', $filename ) ) {
                return true;
            }

            if ( empty( $alt ) ) {
                return true;
            }

            return false;
        }

        /**
         * AI fallback generation.
         *
         * @param string $filename Filename.
         * @param array  $settings Settings.
         * @return array
         */
        private function generate_ai_alt( $filename, $settings ) {
            if ( empty( $settings['openai_api_key'] ) ) {
                return array( 'success' => false, 'error' => 'API key missing.' );
            }

            $prompt = 'Generate a concise and natural product image ALT text (max 120 chars), avoid keyword stuffing and sales words, based on this filename: ' . $filename;

            $response = wp_remote_post(
                'https://api.openai.com/v1/responses',
                array(
                    'timeout' => 30,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $settings['openai_api_key'],
                        'Content-Type'  => 'application/json',
                    ),
                    'body'    => wp_json_encode(
                        array(
                            'model' => $settings['openai_model'],
                            'input' => $prompt,
                        )
                    ),
                )
            );

            if ( is_wp_error( $response ) ) {
                return array( 'success' => false, 'error' => $response->get_error_message() );
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( $code >= 300 || empty( $body ) ) {
                return array( 'success' => false, 'error' => 'Unexpected API response.' );
            }

            $text = '';
            if ( isset( $body['output'][0]['content'][0]['text'] ) ) {
                $text = (string) $body['output'][0]['content'][0]['text'];
            }

            if ( empty( $text ) ) {
                return array( 'success' => false, 'error' => 'No text returned by AI.' );
            }

            return array( 'success' => true, 'alt' => $text );
        }

        /**
         * Normalize ALT quality.
         *
         * @param string $alt Alt text.
         * @return string
         */
        private function normalize_alt( $alt ) {
            $alt = wp_strip_all_tags( (string) $alt );
            $alt = preg_replace( '/[_\-]+/', ' ', $alt );
            $alt = preg_replace( '/\s+/', ' ', trim( $alt ) );
            $alt = preg_replace( '/\b(buy|cheap|best)\b/i', '', $alt );
            $alt = preg_replace( '/\s+/', ' ', trim( $alt ) );

            $words       = explode( ' ', strtolower( $alt ) );
            $unique      = array();
            $final_words = array();
            foreach ( $words as $word ) {
                if ( '' === $word || isset( $unique[ $word ] ) ) {
                    continue;
                }

                $unique[ $word ] = true;
                $final_words[]   = $word;
            }

            $alt = ucwords( implode( ' ', $final_words ) );

            if ( mb_strlen( $alt ) > self::MAX_ALT_LENGTH ) {
                $alt = mb_substr( $alt, 0, self::MAX_ALT_LENGTH );
                $alt = rtrim( $alt, " .,!?:;-" );
            }

            return $alt;
        }

        /**
         * Ensure ALT is unique globally.
         *
         * @param string $alt Alt text.
         * @param int    $attachment_id Attachment id.
         * @return string
         */
        private function ensure_unique_alt( $alt, $attachment_id ) {
            global $wpdb;

            $meta_table = $wpdb->postmeta;
            $count      = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$meta_table} WHERE meta_key = '_wp_attachment_image_alt' AND meta_value = %s AND post_id != %d",
                    $alt,
                    $attachment_id
                )
            );

            if ( $count > 0 ) {
                $suffix = ( $count % 2 === 0 ) ? ' design' : ' detail';
                $alt   .= $suffix;
            }

            return $alt;
        }

        /**
         * Single media action handler.
         */
        public function handle_single_generate() {
            $this->guard_admin_post();

            $attachment_id = absint( $_POST['attachment_id'] ?? 0 );
            if ( $attachment_id <= 0 ) {
                $this->redirect_back();
            }

            $settings = $this->get_settings();
            $ai_used  = 0;
            $result   = $this->generate_alt_for_attachment( $attachment_id, $settings, $ai_used );

            if ( ! empty( $result['success'] ) ) {
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', $result['alt'] );
                $this->append_log( 'Single generate success for #' . $attachment_id );
            } else {
                $this->append_log( 'Single generate failed for #' . $attachment_id . ': ' . $result['error'] );
            }

            $this->redirect_back();
        }

        /**
         * Auto-generate for uploads.
         *
         * @param int $attachment_id Attachment.
         */
        public function auto_generate_on_upload( $attachment_id ) {
            if ( ! wp_attachment_is_image( $attachment_id ) ) {
                return;
            }

            $settings = $this->get_settings();
            $ai_used  = 0;
            $result   = $this->generate_alt_for_attachment( $attachment_id, $settings, $ai_used );
            if ( ! empty( $result['success'] ) ) {
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', $result['alt'] );
            }
        }

        /**
         * Add media status column.
         *
         * @param array $columns Columns.
         * @return array
         */
        public function add_media_columns( $columns ) {
            $columns['wp_alt_status'] = __( 'ALT Status', 'wp-alt-generator' );
            return $columns;
        }

        /**
         * Render media status column.
         *
         * @param string $column Column key.
         * @param int    $post_id Post id.
         */
        public function render_media_columns( $column, $post_id ) {
            if ( 'wp_alt_status' !== $column ) {
                return;
            }

            $alt = get_post_meta( $post_id, '_wp_attachment_image_alt', true );
            echo ! empty( $alt ) ? '✔' : '✘';
        }

        /**
         * Add row action in media list.
         *
         * @param array   $actions Actions.
         * @param \WP_Post $post Post.
         * @return array
         */
        public function add_media_row_action( $actions, $post ) {
            if ( 'attachment' !== $post->post_type || ! wp_attachment_is_image( $post->ID ) ) {
                return $actions;
            }

            $url = admin_url( 'admin-post.php' );

            $actions['wp_generate_alt'] = sprintf(
                '<form method="post" action="%1$s" style="display:inline;">%2$s<input type="hidden" name="action" value="wp_alt_generator_single"><input type="hidden" name="attachment_id" value="%3$d"><button type="submit" class="button-link">Generate ALT</button></form>',
                esc_url( $url ),
                wp_nonce_field( self::NONCE_ACTION, '_wpnonce', true, false ),
                (int) $post->ID
            );

            return $actions;
        }

        /**
         * CLI command.
         *
         * @param array $args Args.
         * @param array $assoc_args Assoc args.
         */
        public function cli_run( $args, $assoc_args ) {
            $limit  = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : self::BATCH_DEFAULT;
            $offset = isset( $assoc_args['offset'] ) ? absint( $assoc_args['offset'] ) : 0;
            $all    = isset( $assoc_args['all'] );

            $settings           = $this->get_settings();
            $settings['status'] = 'running';
            $this->save_settings( $settings );

            if ( $all ) {
                $total_done   = 0;
                $total_failed = 0;

                do {
                    $result       = $this->process_queue_batch( $limit, 0 );
                    $total_done   += $result['processed'];
                    $total_failed += $result['failed'];
                } while ( $result['processed'] > 0 || $result['failed'] > 0 );

                \WP_CLI::success( sprintf( 'Completed all queue items. Done: %d, failed: %d', $total_done, $total_failed ) );
                return;
            }

            $result = $this->process_queue_batch( $limit, $offset );
            \WP_CLI::success( sprintf( 'Batch done. Processed: %d, failed: %d', $result['processed'], $result['failed'] ) );
        }

        /**
         * Get media stats.
         *
         * @return array
         */
        private function get_library_stats() {
            global $wpdb;

            $posts = $wpdb->posts;
            $meta  = $wpdb->postmeta;

            $total_images = (int) $wpdb->get_var(
                "SELECT COUNT(ID) FROM {$posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'"
            );

            $without_alt = (int) $wpdb->get_var(
                "SELECT COUNT(p.ID)
                FROM {$posts} p
                LEFT JOIN {$meta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
                WHERE p.post_type='attachment' AND p.post_mime_type LIKE 'image/%' AND (m.meta_value IS NULL OR m.meta_value = '')"
            );

            return array(
                'total_images' => $total_images,
                'without_alt'  => $without_alt,
            );
        }

        /**
         * Queue stats.
         *
         * @return array
         */
        private function get_queue_stats() {
            global $wpdb;

            $table = $this->queue_table();
            $rows  = $wpdb->get_results( "SELECT status, COUNT(*) as total FROM {$table} GROUP BY status", ARRAY_A );

            $stats = array(
                'pending'    => 0,
                'processing' => 0,
                'done'       => 0,
                'failed'     => 0,
            );

            foreach ( $rows as $row ) {
                if ( isset( $stats[ $row['status'] ] ) ) {
                    $stats[ $row['status'] ] = (int) $row['total'];
                }
            }

            return $stats;
        }

        /**
         * Check nonce/caps.
         */
        private function guard_admin_post() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'Insufficient permissions.' );
            }

            check_admin_referer( self::NONCE_ACTION );
        }

        /**
         * Redirect back to admin screen.
         */
        private function redirect_back() {
            wp_safe_redirect( admin_url( 'tools.php?page=wp-alt-generator' ) );
            exit;
        }

        /**
         * Queue table name.
         *
         * @return string
         */
        private function queue_table() {
            global $wpdb;
            return $wpdb->prefix . 'alt_generator_queue';
        }

        /**
         * Insert/update queue row.
         *
         * @param int    $attachment_id Attachment id.
         * @param string $status Status.
         */
        private function upsert_queue_item( $attachment_id, $status ) {
            global $wpdb;

            $table = $this->queue_table();
            $now   = current_time( 'mysql' );
            $file  = get_attached_file( $attachment_id );

            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table} (attachment_id, status, attempts, alt_result, source_filename, error_message, updated_at, created_at)
                     VALUES (%d, %s, 0, %s, %s, %s, %s, %s)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at), source_filename = VALUES(source_filename)",
                    $attachment_id,
                    $status,
                    '',
                    wp_basename( (string) $file ),
                    '',
                    $now,
                    $now
                )
            );
        }

        /**
         * Update queue status.
         *
         * @param int    $attachment_id Attachment.
         * @param string $status Status.
         * @param string $alt_result Alt.
         * @param string $error Error.
         */
        private function update_queue_status( $attachment_id, $status, $alt_result = '', $error = '' ) {
            global $wpdb;

            $table = $this->queue_table();
            $wpdb->update(
                $table,
                array(
                    'status'       => $status,
                    'alt_result'   => $alt_result,
                    'error_message' => $error,
                    'updated_at'   => current_time( 'mysql' ),
                ),
                array( 'attachment_id' => $attachment_id ),
                array( '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );
        }

        /**
         * Increment attempts and set failed.
         *
         * @param int    $attachment_id Attachment.
         * @param string $error Error.
         */
        private function increase_attempts( $attachment_id, $error ) {
            global $wpdb;

            $table = $this->queue_table();
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                    SET attempts = attempts + 1,
                        status = %s,
                        error_message = %s,
                        updated_at = %s
                    WHERE attachment_id = %d",
                    self::QUEUE_STATUS_FAILED,
                    $error,
                    current_time( 'mysql' ),
                    $attachment_id
                )
            );
        }

        /**
         * Add log.
         *
         * @param string $line Log line.
         */
        private function append_log( $line ) {
            $settings = $this->get_settings();
            $logs     = $settings['last_run_logs'];

            if ( ! is_array( $logs ) ) {
                $logs = array();
            }

            $logs[] = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . sanitize_text_field( $line );
            $settings['last_run_logs'] = array_slice( $logs, -200 );
            $this->save_settings( $settings );
        }
    }
}

add_filter( 'cron_schedules', array( WP_ALT_Generator_Plugin::instance(), 'register_schedule' ) );
register_activation_hook( __FILE__, array( 'WP_ALT_Generator_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_ALT_Generator_Plugin', 'deactivate' ) );
WP_ALT_Generator_Plugin::instance();
