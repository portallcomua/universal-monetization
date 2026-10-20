<?php
/**
 * Plugin Name: Universal Monetization & Billing
 * Description: Універсальне ядро монетизації. Створюйте проекти, генеруйте код для вставки в будь-який плагін, додаток, розширення. Надає PHP+JS+REST API.
 * Version: 4.0.0
 * Author: Your Name
 * Text Domain: universal-monetization
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════════════════════════
   КОНСТАНТИ
   ═══════════════════════════════════════════════════════════════════ */

define( 'UM_VERSION', '4.0.0' );
define( 'UM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'UM_SERVICES_TABLE', 'um_services' );
define( 'UM_LOGS_TABLE', 'um_activation_logs' );
define( 'UM_CRON_HOOK', 'um_daily_expiry_check' );
define( 'UM_OPTION_API_KEY', 'um_api_key' );

/* ═══════════════════════════════════════════════════════════════════
   РОЗДІЛ 1. БАЗА ДАНИХ
   ═══════════════════════════════════════════════════════════════════ */

class UM_DB {

    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $services = $wpdb->prefix . UM_SERVICES_TABLE;
        $logs     = $wpdb->prefix . UM_LOGS_TABLE;

        $sql1 = "CREATE TABLE $services (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(100) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL,
            price_text VARCHAR(100) NOT NULL DEFAULT '',
            payment_url TEXT NOT NULL,
            duration_days INT NOT NULL DEFAULT 30,
            success_message TEXT NOT NULL,
            popup_instruction TEXT NOT NULL,
            popup_context TEXT NOT NULL,
            email_subject VARCHAR(255) NOT NULL DEFAULT '',
            email_body LONGTEXT NOT NULL,
            owner_plugin VARCHAR(100) NOT NULL DEFAULT 'core',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY slug_idx (slug),
            KEY owner_idx (owner_plugin)
        ) $charset;";

        $sql2 = "CREATE TABLE $logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            reminded TINYINT(1) NOT NULL DEFAULT 0,
            source VARCHAR(100) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY user_service (user_id, service_id),
            KEY status_idx (status)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql1 );
        dbDelta( $sql2 );

        if ( ! get_option( UM_OPTION_API_KEY ) ) {
            update_option( UM_OPTION_API_KEY, wp_generate_password( 40, false ) );
        }
    }

    public static function get_services( $owner = null ) {
        global $wpdb;
        if ( $owner ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . UM_SERVICES_TABLE . " WHERE owner_plugin = %s ORDER BY id ASC",
                $owner
            ) );
        }
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}" . UM_SERVICES_TABLE . " ORDER BY id ASC" );
    }

    public static function get_service( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . UM_SERVICES_TABLE . " WHERE id = %d", $id
        ) );
    }

    public static function get_service_by_slug( $slug ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . UM_SERVICES_TABLE . " WHERE slug = %s", $slug
        ) );
    }

    public static function save_service( $data, $id = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . UM_SERVICES_TABLE;

        $fields = [
            'slug'              => sanitize_key( $data['slug'] ?? '' ),
            'title'             => sanitize_text_field( $data['title'] ?? '' ),
            'price_text'        => sanitize_text_field( $data['price_text'] ?? '' ),
            'payment_url'       => esc_url_raw( $data['payment_url'] ?? '' ),
            'duration_days'     => intval( $data['duration_days'] ?? 30 ),
            'success_message'   => wp_kses_post( $data['success_message'] ?? '' ),
            'popup_instruction' => wp_kses_post( $data['popup_instruction'] ?? '' ),
            'popup_context'     => wp_kses_post( $data['popup_context'] ?? '' ),
            'email_subject'     => sanitize_text_field( $data['email_subject'] ?? '' ),
            'email_body'        => wp_kses_post( $data['email_body'] ?? '' ),
            'owner_plugin'      => sanitize_key( $data['owner_plugin'] ?? 'core' ),
        ];

        if ( $id > 0 ) {
            $wpdb->update( $table, $fields, [ 'id' => $id ] );
            return $id;
        }
        $wpdb->insert( $table, $fields );
        return $wpdb->insert_id;
    }

    /** Оновити лише налаштування, які дозволено змінювати покупцю */
    public static function update_client_settings( $id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . UM_SERVICES_TABLE;

        $allowed = [];
        if ( isset( $data['payment_url'] ) )       $allowed['payment_url']       = esc_url_raw( $data['payment_url'] );
        if ( isset( $data['price_text'] ) )         $allowed['price_text']         = sanitize_text_field( $data['price_text'] );
        if ( isset( $data['title'] ) )              $allowed['title']              = sanitize_text_field( $data['title'] );
        if ( isset( $data['duration_days'] ) )      $allowed['duration_days']      = intval( $data['duration_days'] );
        if ( isset( $data['success_message'] ) )    $allowed['success_message']    = wp_kses_post( $data['success_message'] );
        if ( isset( $data['popup_instruction'] ) )  $allowed['popup_instruction']  = wp_kses_post( $data['popup_instruction'] );
        if ( isset( $data['popup_context'] ) )      $allowed['popup_context']      = wp_kses_post( $data['popup_context'] );
        if ( isset( $data['email_subject'] ) )      $allowed['email_subject']      = sanitize_text_field( $data['email_subject'] );
        if ( isset( $data['email_body'] ) )         $allowed['email_body']         = wp_kses_post( $data['email_body'] );

        if ( empty( $allowed ) ) return false;

        $wpdb->update( $table, $allowed, [ 'id' => intval( $id ) ] );
        return true;
    }

    public static function delete_service( $id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . UM_SERVICES_TABLE, [ 'id' => $id ] );
    }

    public static function activate_premium( $user_id, $service_id, $source = '' ) {
        global $wpdb;
        $service = self::get_service( $service_id );
        if ( ! $service ) return false;

        $duration = intval( $service->duration_days );
        $expires  = date( 'Y-m-d H:i:s', strtotime( "+{$duration} days" ) );

        update_user_meta( $user_id, "_um_premium_{$service_id}", $expires );
        update_user_meta( $user_id, "_um_premium_active_{$service_id}", '1' );

        $wpdb->insert( $wpdb->prefix . UM_LOGS_TABLE, [
            'user_id'    => $user_id,
            'service_id' => $service_id,
            'expires_at' => $expires,
            'status'     => 'active',
            'source'     => sanitize_key( $source ),
        ] );

        return true;
    }

    public static function revoke_premium( $log_id ) {
        global $wpdb;
        $log = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . UM_LOGS_TABLE . " WHERE id = %d", $log_id
        ) );
        if ( ! $log ) return false;

        delete_user_meta( $log->user_id, "_um_premium_{$log->service_id}" );
        update_user_meta( $log->user_id, "_um_premium_active_{$log->service_id}", '0' );

        $wpdb->update(
            $wpdb->prefix . UM_LOGS_TABLE,
            [ 'status' => 'blocked' ],
            [ 'id' => $log_id ]
        );
        return true;
    }

    public static function is_premium( $user_id, $service_id ) {
        $active = get_user_meta( $user_id, "_um_premium_active_{$service_id}", true );
        if ( $active !== '1' ) return false;

        $expires = get_user_meta( $user_id, "_um_premium_{$service_id}", true );
        if ( ! $expires ) return false;

        return strtotime( $expires ) > time();
    }

    public static function days_left( $user_id, $service_id ) {
        $expires = get_user_meta( $user_id, "_um_premium_{$service_id}", true );
        if ( ! $expires ) return null;
        $diff = strtotime( $expires ) - time();
        return (int) floor( $diff / DAY_IN_SECONDS );
    }

    public static function get_logs( $limit = 200, $service_ids = null ) {
        global $wpdb;
        $table = $wpdb->prefix . UM_LOGS_TABLE;

        if ( is_array( $service_ids ) && ! empty( $service_ids ) ) {
            $ids = implode( ',', array_map( 'intval', $service_ids ) );
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $table WHERE service_id IN ($ids) ORDER BY activated_at DESC LIMIT %d",
                $limit
            ) );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table ORDER BY activated_at DESC LIMIT %d", $limit
        ) );
    }

    public static function get_expiring( $days = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . UM_LOGS_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table 
             WHERE status = 'active' 
               AND reminded = 0 
               AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }

    public static function mark_reminded( $log_id ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . UM_LOGS_TABLE, [ 'reminded' => 1 ], [ 'id' => $log_id ] );
    }
}

/* ═══════════════════════════════════════════════════════════════════
   РОЗДІЛ 2. АДМІНКА (тільки для супер-адміна)
   ═══════════════════════════════════════════════════════════════════ */

class UM_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_post_um_save_service', [ $this, 'handle_save' ] );
        add_action( 'admin_post_um_delete_service', [ $this, 'handle_delete' ] );
        add_action( 'admin_post_um_revoke_premium', [ $this, 'handle_revoke' ] );
        add_action( 'admin_post_um_regenerate_key', [ $this, 'handle_regen_key' ] );
    }

    public function add_menu() {
        add_menu_page(
            'Монетизація PRO', 'Монетизація PRO', 'manage_options',
            'um-services', [ $this, 'render_services_page' ], 'dashicons-money-alt', 30
        );
        add_submenu_page(
            'um-services', 'Проекти', 'Проекти', 'manage_options',
            'um-services', [ $this, 'render_services_page' ]
        );
        add_submenu_page(
            'um-services', 'Контроль Преміумів', 'Контроль Преміумів', 'manage_options',
            'um-control', [ $this, 'render_control_page' ]
        );
        add_submenu_page(
            'um-services', 'API-ключ', 'API-ключ', 'manage_options',
            'um-api-key', [ $this, 'render_api_key_page' ]
        );
    }

    public function render_services_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $services = UM_DB::get_services();
        $edit_id  = isset( $_GET['edit'] ) ? intval( $_GET['edit'] ) : 0;
        $editing  = $edit_id ? UM_DB::get_service( $edit_id ) : null;
        ?>
        <div class="wrap">
            <h1>Проекти монетизації</h1>
            <p>Кожен проект — окремий «продукт» (плагін, додаток, розширення тощо).
               Кожен отримує свій ID і працює незалежно від інших.</p>

            <h2><?php echo $editing ? 'Редагувати проект #' . $editing->id : 'Створити новий проект'; ?></h2>
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <?php wp_nonce_field( 'um_save_service' ); ?>
                <input type="hidden" name="action" value="um_save_service">
                <input type="hidden" name="service_id" value="<?php echo $editing ? $editing->id : 0; ?>">
                <table class="form-table">
                    <tr><th>Slug (ключ)</th>
                        <td><input type="text" name="slug" class="regular-text"
                            value="<?php echo esc_attr( $editing->slug ?? '' ); ?>"
                            placeholder="my-plugin-pro">
                            <p class="description">Унікальний ключ проекту. Латиниця, дефіси. Для сторонніх плагінів заповнюється автоматично.</p></td></tr>
                    <tr><th>Власник (плагін)</th>
                        <td><input type="text" name="owner_plugin" class="regular-text"
                            value="<?php echo esc_attr( $editing->owner_plugin ?? 'core' ); ?>">
                            <p class="description"><code>core</code> — ваш власний проект. Інше — slug стороннього плагіна.</p></td></tr>
                    <tr><th>Назва проекту</th>
                        <td><input type="text" name="title" class="regular-text" required
                            value="<?php echo esc_attr( $editing->title ?? '' ); ?>"></td></tr>
                    <tr><th>Вартість (текст)</th>
                        <td><input type="text" name="price_text" class="regular-text"
                            value="<?php echo esc_attr( $editing->price_text ?? '' ); ?>"></td></tr>
                    <tr><th>Посилання на Конверт</th>
                        <td><input type="url" name="payment_url" class="large-text"
                            value="<?php echo esc_attr( $editing->payment_url ?? '' ); ?>">
                            <p class="description">Для сторонніх плагінів покупець заповнює в СВОЇЙ адмінці.</p></td></tr>
                    <tr><th>Термін дії (днів)</th>
                        <td><input type="number" name="duration_days" min="1"
                            value="<?php echo esc_attr( $editing->duration_days ?? 30 ); ?>"></td></tr>
                    <tr><th>Повідомлення після активації</th>
                        <td><textarea name="success_message" class="large-text" rows="3"><?php
                            echo esc_textarea( $editing->success_message ?? '' ); ?></textarea></td></tr>
                    <tr><th>Інструкція в попапі</th>
                        <td><textarea name="popup_instruction" class="large-text" rows="4"><?php
                            echo esc_textarea( $editing->popup_instruction ?? '' ); ?></textarea>
                            <p class="description">Те, що бачить користувач у попапі. Для сторонніх плагінів покупець редагує у СВОЇЙ адмінці.</p></td></tr>
                    <tr><th>Контекст попапа</th>
                        <td><textarea name="popup_context" class="large-text" rows="2"><?php
                            echo esc_textarea( $editing->popup_context ?? '' ); ?></textarea>
                            <p class="description">Рядок на кшталт «PRO-доступ до гри Шахи на 30 днів».</p></td></tr>
                    <tr><th>Тема Email-нагадування</th>
                        <td><input type="text" name="email_subject" class="large-text"
                            value="<?php echo esc_attr( $editing->email_subject ?? '' ); ?>"></td></tr>
                    <tr><th>Текст Email-нагадування</th>
                        <td><textarea name="email_body" class="large-text" rows="5"><?php
                            echo esc_textarea( $editing->email_body ?? '' ); ?></textarea>
                            <p class="description">Плейсхолдери: <code>{username}</code>, <code>{service}</code>, <code>{days_left}</code></p></td></tr>
                </table>
                <?php submit_button( $editing ? 'Оновити' : 'Створити' ); ?>
            </form>

            <?php if ( $editing ): ?>
                <?php $this->render_integration_block( $editing ); ?>
            <?php endif; ?>

            <h2>Усі проекти</h2>
            <table class="widefat striped">
                <thead><tr>
                    <th>ID</th><th>Slug</th><th>Власник</th><th>Назва</th>
                    <th>Ціна</th><th>Днів</th><th>Дії</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $services as $s ): ?>
                    <tr>
                        <td><strong><?php echo $s->id; ?></strong></td>
                        <td><code><?php echo esc_html( $s->slug ); ?></code></td>
                        <td><?php echo esc_html( $s->owner_plugin ); ?></td>
                        <td><?php echo esc_html( $s->title ); ?></td>
                        <td><?php echo esc_html( $s->price_text ); ?></td>
                        <td><?php echo intval( $s->duration_days ); ?></td>
                        <td>
                            <a href="<?php echo admin_url( 'admin.php?page=um-services&edit=' . $s->id ); ?>">Редагувати / Код</a> |
                            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline"
                                  onsubmit="return confirm('Видалити проект?');">
                                <?php wp_nonce_field( 'um_delete_service' ); ?>
                                <input type="hidden" name="action" value="um_delete_service">
                                <input type="hidden" name="service_id" value="<?php echo $s->id; ?>">
                                <button type="submit" class="button-link" style="color:red">Видалити</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_integration_block( $service ) {
        $id   = intval( $service->id );
        $slug = esc_attr( $service->slug );
        $key  = get_option( UM_OPTION_API_KEY );
        $site = home_url();
        ?>
        <hr style="margin:30px 0">
        <h2>📋 Код для вставки — проект #<?php echo $id; ?> «<?php echo esc_html( $service->title ); ?>»</h2>

        <h3>1️⃣ Інший WordPress-плагін (PHP)</h3>
        <textarea readonly rows="8" class="large-text code" onclick="this.select()"><?php
echo esc_textarea( "// Перевірка статусу PRO\nif ( wcc_check_user_premium( get_current_user_id(), {$id} ) ) {\n    // PRO-логіка\n}\n\n// Кнопка купівлі\n echo UM_Helpers::buy_button( {$id}, 'Купити PRO' );\n" );
?></textarea>

        <h3>2️⃣ Шорткод</h3>
        <textarea readonly rows="2" class="large-text code" onclick="this.select()"><?php
echo esc_textarea( "[um_buy_button id=\"{$id}\" label=\"Купити PRO\"]" );
?></textarea>

        <h3>3️⃣ REST API (Android, Chrome, зовнішні)</h3>
        <textarea readonly rows="3" class="large-text code" onclick="this.select()"><?php
echo esc_textarea( "GET {$site}/wp-json/um/v1/check?username=USERNAME&service_id={$id}&api_key={$key}" );
?></textarea>

        <h3>4️⃣ JavaScript (браузер)</h3>
        <textarea readonly rows="6" class="large-text code" onclick="this.select()"><?php
echo esc_textarea( "fetch('/wp-json/um/v1/check?service_id={$id}', {\n    credentials: 'include'\n}).then(r => r.json()).then(d => {\n    if (d.premium) console.log('PRO активний');\n});" );
?></textarea>

        <h3>5️⃣ Реєстрація проекту зі стороннього плагіна (автоматично)</h3>
        <textarea readonly rows="6" class="large-text code" onclick="this.select()"><?php
echo esc_textarea( "UM_Client::register( [\n    'slug'         => '{$slug}',\n    'name'         => '{$service->title}',\n    'default_days' => {$service->duration_days},\n] );" );
?></textarea>
        <?php
    }

    public function render_control_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $logs = UM_DB::get_logs();
        ?>
        <div class="wrap">
            <h1>Контроль Преміумів</h1>
            <p>Найсвіжіші активації зверху. Якщо гроші не надійшли — тисніть «Відключити PRO».</p>
            <table class="widefat striped">
                <thead><tr>
                    <th>Дата</th><th>Логін</th><th>Email</th>
                    <th>Проект</th><th>Джерело</th><th>Діє до</th><th>Статус</th><th>Дія</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $logs as $log ):
                    $user = get_userdata( $log->user_id );
                    $service = UM_DB::get_service( $log->service_id );
                    if ( ! $user ) continue;
                    ?>
                    <tr>
                        <td><?php echo esc_html( $log->activated_at ); ?></td>
                        <td><strong><?php echo esc_html( $user->user_login ); ?></strong></td>
                        <td><?php echo esc_html( $user->user_email ); ?></td>
                        <td><?php echo $service ? esc_html( $service->title ) . ' (#' . $service->id . ')' : '—'; ?></td>
                        <td><code><?php echo esc_html( $log->source ); ?></code></td>
                        <td><?php echo esc_html( $log->expires_at ); ?></td>
                        <td>
                            <?php if ( $log->status === 'active' ): ?>
                                <span style="color:green">Активний</span>
                            <?php else: ?>
                                <span style="color:red">Заблокований</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $log->status === 'active' ): ?>
                                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>"
                                      onsubmit="return confirm('Заблокувати PRO?');">
                                    <?php wp_nonce_field( 'um_revoke_premium' ); ?>
                                    <input type="hidden" name="action" value="um_revoke_premium">
                                    <input type="hidden" name="log_id" value="<?php echo $log->id; ?>">
                                    <button type="submit" class="button"
                                            style="background:#d63638;border-color:#d63638;color:#fff">
                                        ❌ Відключити PRO
                                    </button>
                                </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_api_key_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $key = get_option( UM_OPTION_API_KEY );
        ?>
        <div class="wrap">
            <h1>API-ключ</h1>
            <p>Для зовнішніх клієнтів (Android, Chrome, сторонні сайти).</p>
            <input type="text" readonly value="<?php echo esc_attr( $key ); ?>"
                   class="large-text code" onclick="this.select()">
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>"
                  onsubmit="return confirm('Згенерувати новий ключ?');" style="margin-top:20px">
                <?php wp_nonce_field( 'um_regenerate_key' ); ?>
                <input type="hidden" name="action" value="um_regenerate_key">
                <?php submit_button( '🔄 Згенерувати новий', 'secondary' ); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'um_save_service' ) ) wp_die( 'Access denied' );
        $id = UM_DB::save_service( $_POST, intval( $_POST['service_id'] ?? 0 ) );
        wp_safe_redirect( admin_url( 'admin.php?page=um-services&edit=' . $id ) );
        exit;
    }

    public function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'um_delete_service' ) ) wp_die( 'Access denied' );
        UM_DB::delete_service( intval( $_POST['service_id'] ) );
        wp_safe_redirect( admin_url( 'admin.php?page=um-services' ) );
        exit;
    }

    public function handle_revoke() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'um_revoke_premium' ) ) wp_die( 'Access denied' );
        UM_DB::revoke_premium( intval( $_POST['log_id'] ) );
        wp_safe_redirect( admin_url( 'admin.php?page=um-control' ) );
        exit;
    }

    public function handle_regen_key() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'um_regenerate_key' ) ) wp_die( 'Access denied' );
        update_option( UM_OPTION_API_KEY, wp_generate_password( 40, false ) );
        wp_safe_redirect( admin_url( 'admin.php?page=um-api-key' ) );
        exit;
    }
}

/* ═══════════════════════════════════════════════════════════════════
   РОЗДІЛ 3. AJAX
   ═══════════════════════════════════════════════════════════════════ */

class UM_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_um_activate', [ $this, 'activate' ] );
        add_action( 'wp_ajax_nopriv_um_activate', [ $this, 'not_logged_in' ] );
        add_action( 'wp_ajax_um_get_popup_data', [ $this, 'get_popup_data' ] );
        add_action( 'wp_ajax_nopriv_um_get_popup_data', [ $this, 'get_popup_data' ] );
    }

    public function activate() {
        check_ajax_referer( 'um_ajax_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( [ 'message' => 'Потрібно увійти в акаунт' ] );

        $service_id = intval( $_POST['service_id'] ?? 0 );
        $source     = sanitize_key( $_POST['source'] ?? 'unknown' );
        $service = UM_DB::get_service( $service_id );
        if ( ! $service ) wp_send_json_error( [ 'message' => 'Проект не знайдено' ] );

        UM_DB::activate_premium( $user_id, $service_id, $source );

        wp_send_json_success( [
            'message' => $service->success_message ?: 'PRO активовано!',
            'reload'  => true,
        ] );
    }

    public function get_popup_data() {
        check_ajax_referer( 'um_ajax_nonce', 'nonce' );

        $service_id = intval( $_POST['service_id'] ?? 0 );
        $service = UM_DB::get_service( $service_id );
        if ( ! $service ) wp_send_json_error( [ 'message' => 'Проект не знайдено' ] );

        $user = wp_get_current_user();

        wp_send_json_success( [
            'title'             => $service->title,
            'price_text'        => $service->price_text,
            'payment_url'       => $service->payment_url,
            'popup_instruction' => wp_kses_post( wpautop( $service->popup_instruction ) ),
            'popup_context'     => wp_kses_post( wpautop( $service->popup_context ) ),
            'user_login'        => $user->user_login,
        ] );
    }

    public function not_logged_in() {
        wp_send_json_error( [ 'message' => 'Потрібно увійти в акаунт' ] );
    }
}

/* ═══════════════════════════════════════════════════════════════════
   РОЗДІЛ 4. REST API
   ═══════════════════════════════════════════════════════════════════ */

class UM_REST {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( 'um/v1', '/check', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'check' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function check( WP_REST_Request $req ) {
        $service_id = intval( $req->get_param( 'service_id' ) );
        if ( ! $service_id ) {
            return new WP_REST_Response( [ 'error' => 'service_id required' ], 400 );
        }

        $user_id = 0;
        $username = sanitize_text_field( $req->get_param( 'username' ) ?? '' );

        if ( $username ) {
            if ( ! $this->verify_key( $req ) ) {
                return new WP_REST_Response( [ 'error' => 'invalid api_key' ], 403 );
            }
            $user = get_user_by( 'login', $username );
            if ( ! $user ) return new WP_REST_Response( [ 'error' => 'user not found' ], 404 );
            $user_id = $user->ID;
        } else {
            $raw_id = intval( $req->get_param( 'user_id' ) );
            if ( $raw_id ) {
                if ( ! $this->verify_key( $req ) ) {
                    return new WP_REST_Response( [ 'error' => 'invalid api_key' ], 403 );
                }
                $user_id = $raw_id;
            } else {
                $user_id = get_current_user_id();
                if ( ! $user_id ) {
                    return new WP_REST_Response( [ 'error' => 'not authenticated' ], 401 );
                }
            }
        }

        $premium = UM_DB::is_premium( $user_id, $service_id );
        $expires = get_user_meta( $user_id, "_um_premium_{$service_id}", true );
        $days    = UM_DB::days_left( $user_id, $service_id );

        return new WP_REST_Response( [
            'premium'    => (bool) $premium,
            'expires_at' => $expires ?: null,
            'days_left'  => $days,
        ], 200 );
    }

    private function verify_key( $req ) {
        $key = $req->get_param( 'api_key' );
        return $key && hash_equals( get_option( UM_OPTION_API_KEY ), $key );
    }
}

/* ═══════════════════════════════════════════════════════════════════
   РОЗДІЛ 5. CRON
   ═══════════════════════════════════════════════════════════════════ */

class UM_Cron {

    public function __construct() {
        add_action( UM_CRON_HOOK, [ $this, 'check_expiring' ] );
    }

    public static function schedule() {
        if ( ! wp_next_scheduled( UM_CRON_HOOK ) ) {
            wp_schedule_event( time() + 3600, 'daily', UM_CRON_HOOK );
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook( UM_CRON_HOOK );
    }

    public function check_expiring() {
        $logs = UM_DB::get_expiring( 10 );
        foreach ( $logs as $log ) {
            $user    = get_userdata( $log->user_id );
            $service = UM_DB::get_service( $log->service_id );
            if ( ! $user || ! $service ) continue;

            if ( empty( $service->email_subject ) ) {
                UM_DB::mark_reminded( $log->id );
                continue;
            }

            $days_left = max( 0, (int) floor( ( strtotime( $log->expires_at ) - time() ) / DAY_IN_SECONDS ) );

            $repl = [
                '{username}'  => $user->user_login,
                '{service}'   => $service->title,
                '{days_left}' => $days_left,
            ];
            $subject = strtr( $service->email_subject, $repl );
            $body    = strtr( $service->email_body, $repl );

            wp_mail( $user->user_email, $subject, $body );
            UM_DB::mark_reminded( $log->id );
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════
   РОЗДІЛ 6. АСЕТИ (завжди підключені)
   ═══════════════════════════════════════════════════════════════════ */

class UM_Assets {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue() {
        wp_enqueue_style( 'um-styles', UM_PLUGIN_URL . 'assets/um-styles.css', [], UM_VERSION );
        wp_enqueue_script( 'um-core', UM_PLUGIN_URL . 'assets/um-core.js', [], UM_VERSION, true );

        wp_localize_script( 'um-core', 'UM_CFG', [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'um_ajax_nonce' ),
            'is_logged' => is_user_logged_in(),
            'login_url' => wp_login_url( get_permalink() ),
        ] );
    }

    public static function need() { /* сумісність */ }
}

/* ═══════════════════════════════════════════════════════════════════
   РОЗДІЛ 7. ПУБЛІЧНЕ API
   ═══════════════════════════════════════════════════════════════════ */

if ( ! function_exists( 'wcc_check_user_premium' ) ) {
    function wcc_check_user_premium( $user_id, $service_id ) {
        return UM_DB::is_premium( intval( $user_id ), intval( $service_id ) );
    }
}

if ( ! function_exists( 'wcc_get_days_left' ) ) {
    function wcc_get_days_left( $user_id, $service_id ) {
        return UM_DB::days_left( intval( $user_id ), intval( $service_id ) );
    }
}

if ( ! function_exists( 'wcc_add_premium_access' ) ) {
    function wcc_add_premium_access( $user_id, $service_id, $args = [] ) {
        $user_id    = intval( $user_id );
        $service_id = intval( $service_id );
        if ( ! $user_id || ! $service_id ) return false;

        $service = UM_DB::get_service( $service_id );
        if ( ! $service ) return false;

        $source = $args['source'] ?? 'api';
        $ok = UM_DB::activate_premium( $user_id, $service_id, $source );

        if ( $ok && is_array( $args ) ) {
            if ( ! empty( $args['type'] ) )   update_user_meta( $user_id, "_um_premium_type_{$service_id}", sanitize_key( $args['type'] ) );
            if ( ! empty( $args['email'] ) )  update_user_meta( $user_id, "_um_premium_email_{$service_id}", sanitize_email( $args['email'] ) );
            if ( ! empty( $args['domain'] ) ) update_user_meta( $user_id, "_um_premium_domain_{$service_id}", sanitize_text_field( $args['domain'] ) );
        }

        return $ok;
    }
}

if ( ! function_exists( 'wcc_block_premium_access' ) ) {
    function wcc_block_premium_access( $user_id, $service_id ) {
        $user_id    = intval( $user_id );
        $service_id = intval( $service_id );
        if ( ! $user_id || ! $service_id ) return false;

        global $wpdb;
        $log_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}" . UM_LOGS_TABLE . "
             WHERE user_id = %d AND service_id = %d AND status = 'active'
             ORDER BY id DESC LIMIT 1",
            $user_id, $service_id
        ) );

        if ( ! $log_id ) {
            delete_user_meta( $user_id, "_um_premium_{$service_id}" );
            update_user_meta( $user_id, "_um_premium_active_{$service_id}", '0' );
            return true;
        }

        return UM_DB::revoke_premium( $log_id );
    }
}

if ( ! function_exists( 'wcc_get_premium_info' ) ) {
    function wcc_get_premium_info( $user_id, $service_id ) {
        $user_id    = intval( $user_id );
        $service_id = intval( $service_id );

        return [
            'premium'    => UM_DB::is_premium( $user_id, $service_id ),
            'expires_at' => get_user_meta( $user_id, "_um_premium_{$service_id}", true ) ?: null,
            'days_left'  => UM_DB::days_left( $user_id, $service_id ),
            'type'       => get_user_meta( $user_id, "_um_premium_type_{$service_id}", true ) ?: null,
            'email'      => get_user_meta( $user_id, "_um_premium_email_{$service_id}", true ) ?: null,
            'domain'     => get_user_meta( $user_id, "_um_premium_domain_{$service_id}", true ) ?: null,
        ];
    }
}

class UM_Helpers {

    public static function buy_button( $service_id, $label = 'Купити Преміум', $args = [] ) {
        $service_id = intval( $service_id );
        $service = UM_DB::get_service( $service_id );
        if ( ! $service ) return '';

        $args = wp_parse_args( $args, [ 'class' => '', 'style' => '' ] );
        $user_id    = get_current_user_id();
        $is_premium = $user_id ? UM_DB::is_premium( $user_id, $service_id ) : false;

        if ( $is_premium ) {
            $days = UM_DB::days_left( $user_id, $service_id );
            $status = '✅ PRO активний';
            if ( $days !== null && $days <= 10 ) $status .= ' (залишилось ' . $days . ' дн.)';
            return sprintf(
                '<button type="button" class="um-btn um-btn-success %s" disabled style="%s">%s</button>',
                esc_attr( $args['class'] ), esc_attr( $args['style'] ), esc_html( $status )
            );
        }

        return sprintf(
            '<button type="button" class="um-btn um-btn-primary um-buy-btn %s" style="%s"
                     data-um-service="%d">%s</button>',
            esc_attr( $args['class'] ), esc_attr( $args['style'] ),
            $service_id, esc_html( $label )
        );
    }
}

/* Шорткод — ТІЛЬКИ кнопка */
add_shortcode( 'um_buy_button', function ( $atts ) {
    $atts = shortcode_atts( [ 'id' => 0, 'label' => 'Купити Преміум', 'class' => '' ], $atts );
    return UM_Helpers::buy_button( intval( $atts['id'] ), $atts['label'], [ 'class' => $atts['class'] ] );
} );

/* ═══════════════════════════════════════════════════════════════════
   РОЗДІЛ 8. UM_Client — РЕЄСТРАЦІЯ СТОРОННІХ ПЛАГІНІВ
   ═══════════════════════════════════════════════════════════════════ */

class UM_Client {

    /**
     * Сторонній плагін реєструє свій проект при активації:
     *
     *   UM_Client::register([
     *       'slug'         => 'chess',
     *       'name'         => 'Шахи PRO',
     *       'default_days' => 30,
     *       'price_text'   => '100 грн',
     *   ]);
     *
     * @return int|false ID створеного/існуючого проекту
     */
    public static function register( $args ) {
        $slug = sanitize_key( $args['slug'] ?? '' );
        if ( ! $slug ) return false;

        $existing = UM_DB::get_service_by_slug( $slug );
        if ( $existing ) return intval( $existing->id );

        $id = UM_DB::save_service( [
            'slug'              => $slug,
            'title'             => $args['name'] ?? $slug,
            'price_text'        => $args['price_text'] ?? '',
            'payment_url'       => $args['payment_url'] ?? '',
            'duration_days'     => $args['default_days'] ?? 30,
            'success_message'   => $args['success_message'] ?? 'PRO активовано!',
            'popup_instruction' => $args['popup_instruction'] ?? 'Вкажіть логін у полі «Призначення платежу».',
            'popup_context'     => $args['popup_context'] ?? '',
            'email_subject'     => $args['email_subject'] ?? 'Ваш доступ закінчується через {days_left} дн.',
            'email_body'        => $args['email_body'] ?? 'Вітаю, {username}! Ваш доступ до {service} закінчується через {days_left} днів.',
            'owner_plugin'      => $slug,
        ] );

        return $id ?: false;
    }

    /** Отримати ID проекту за slug (для використання в сторонньому плагіні) */
    public static function get_id( $slug ) {
        $s = UM_DB::get_service_by_slug( sanitize_key( $slug ) );
        return $s ? intval( $s->id ) : 0;
    }

    /** Отримати повні дані проекту за slug */
    public static function get_service( $slug ) {
        return UM_DB::get_service_by_slug( sanitize_key( $slug ) );
    }
}

/* ═══════════════════════════════════════════════════════════════════
   РОЗДІЛ 9. ІНІЦІАЛІЗАЦІЯ
   ═══════════════════════════════════════════════════════════════════ */

register_activation_hook( __FILE__, [ 'UM_DB', 'install' ] );
register_activation_hook( __FILE__, [ 'UM_Cron', 'schedule' ] );
register_deactivation_hook( __FILE__, [ 'UM_Cron', 'unschedule' ] );

add_action( 'plugins_loaded', function () {
    new UM_Admin();
    new UM_Ajax();
    new UM_REST();
    new UM_Cron();
    new UM_Assets();
} );