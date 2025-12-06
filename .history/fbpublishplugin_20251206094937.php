<?php
/**
 * Plugin Name: FB Publish Plugin
 * Description: Publie automatiquement le lien de l’article sur une Page Facebook lors du passage de brouillon à publié (y compris planification). Offre une méta box pour publication manuelle avec message personnalisé.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: fbpublishplugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class FB_Publish_Plugin {
    const OPTION_KEY = 'fbpublishplugin_options';
    const NONCE_META_BOX = 'fbpublish_meta_box_nonce';
    const AJAX_ACTION = 'fbpublish_manual_share';
    const CRON_HOOK = 'fbpublish_deferred_share';

    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);

        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('save_post', [$this, 'save_meta_box']);

        // Auto share when post is published (including scheduled)
        add_action('transition_post_status', [$this, 'maybe_auto_share_on_publish'], 10, 3);
        // Fallback: publish_post also fires on publish
        add_action('publish_post', [$this, 'auto_share_simple'], 10, 2);

        // AJAX for manual share
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle_manual_share_ajax']);

        // Admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Deferred share handler (for scheduled/immediate slight delay)
        // Accept 4 args now (post_id, destinations, message_override, force)
        add_action(self::CRON_HOOK, [$this, 'handle_deferred_share'], 10, 4);
    }

    public static function get_options() {
        $defaults = [
            'access_token' => '', // global fallback (déconseillé)
            'page_access_token' => '',
            'page_id' => '',
            'default_message' => 'Nouvel article: {title}',
            'share_format' => 'link', // link | photo
            'force_on_scheduled' => '0',
            'openai_api_key' => '',
            'enable_ai_hook' => '0',
        ];
        $options = get_option(self::OPTION_KEY, []);
        if (!is_array($options)) {
            $options = [];
        }
        return array_merge($defaults, $options);
    }

    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => function ($input) {
                $sanitized = [];
                $sanitized['access_token'] = isset($input['access_token']) ? sanitize_text_field($input['access_token']) : '';
                $sanitized['page_access_token'] = isset($input['page_access_token']) ? sanitize_text_field($input['page_access_token']) : '';
                $sanitized['page_id'] = isset($input['page_id']) ? sanitize_text_field($input['page_id']) : '';
                $sanitized['default_message'] = isset($input['default_message']) ? wp_kses_post($input['default_message']) : '';
                $sf = isset($input['share_format']) ? sanitize_text_field($input['share_format']) : 'link';
                $sanitized['share_format'] = in_array($sf, ['link','photo'], true) ? $sf : 'link';
                $sanitized['force_on_scheduled'] = isset($input['force_on_scheduled']) && $input['force_on_scheduled'] === '1' ? '1' : '0';
                $sanitized['openai_api_key'] = isset($input['openai_api_key']) ? sanitize_text_field($input['openai_api_key']) : '';
                $sanitized['enable_ai_hook'] = isset($input['enable_ai_hook']) && $input['enable_ai_hook'] === '1' ? '1' : '0';
                return $sanitized;
            },
            'default' => self::get_options(),
        ]);

        add_settings_section('fbpublish_main', __('Paramètres Facebook', 'fbpublishplugin'), function () {
            echo '<p>' . esc_html__('Configurez le jeton, l’ID de Page et le message par défaut.', 'fbpublishplugin') . '</p>';
        }, self::OPTION_KEY);

        add_settings_field('page_access_token', __('Jeton d’accès Page', 'fbpublishplugin'), function () {
            $options = self::get_options();
            echo '<input type="text" class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[page_access_token]" value="' . esc_attr($options['page_access_token']) . '" placeholder="EA..." />';
            echo '<p class="description">' . esc_html__('Jeton de Page requis pour publier sur une Page (pages_manage_posts).', 'fbpublishplugin') . '</p>';
        }, self::OPTION_KEY, 'fbpublish_main');

        add_settings_field('access_token', __('Jeton global (optionnel)', 'fbpublishplugin'), function () {
            $options = self::get_options();
            echo '<input type="text" class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[access_token]" value="' . esc_attr($options['access_token']) . '" placeholder="EA..." />';
            echo '<p class="description">' . esc_html__('Facultatif: utilisé en repli si le jeton Page est vide.', 'fbpublishplugin') . '</p>';
        }, self::OPTION_KEY, 'fbpublish_main');

        add_settings_field('page_id', __('ID de la Page Facebook', 'fbpublishplugin'), function () {
            $options = self::get_options();
            echo '<input type="text" class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[page_id]" value="' . esc_attr($options['page_id']) . '" />';
        }, self::OPTION_KEY, 'fbpublish_main');

        add_settings_field('default_message', __('Message par défaut', 'fbpublishplugin'), function () {
            $options = self::get_options();
            echo '<textarea class="large-text" rows="3" name="' . esc_attr(self::OPTION_KEY) . '[default_message]">' . esc_textarea($options['default_message']) . '</textarea>';
            echo '<p class="description">' . esc_html__('Placeholders disponibles: {title} {link}', 'fbpublishplugin') . '</p>';
        }, self::OPTION_KEY, 'fbpublish_main');

        add_settings_field('share_format', __('Format de partage', 'fbpublishplugin'), function () {
            $options = self::get_options();
            $val = isset($options['share_format']) ? $options['share_format'] : 'link';
            echo '<label><input type="radio" name="' . esc_attr(self::OPTION_KEY) . '[share_format]" value="link" ' . checked('link', $val, false) . ' /> ' . esc_html__('Aperçu de lien (par défaut)', 'fbpublishplugin') . '</label><br/>';
            echo '<label><input type="radio" name="' . esc_attr(self::OPTION_KEY) . '[share_format]" value="photo" ' . checked('photo', $val, false) . ' /> ' . esc_html__('Photo + lien dans le message (grand visuel)', 'fbpublishplugin') . '</label>';
        }, self::OPTION_KEY, 'fbpublish_main');

        add_settings_field('force_on_scheduled', __('Forcer si publication planifiée', 'fbpublishplugin'), function () {
            $options = self::get_options();
            $val = isset($options['force_on_scheduled']) ? $options['force_on_scheduled'] : '0';
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[force_on_scheduled]" value="1" ' . checked('1', $val, false) . ' /> ' . esc_html__('Ignorer la déduplication si l'article était déjà partagé avant sa mise en ligne planifiée.', 'fbpublishplugin') . '</label>';
        }, self::OPTION_KEY, 'fbpublish_main');

        // OpenAI settings section
        add_settings_section('fbpublish_openai', __('Génération de message IA', 'fbpublishplugin'), function () {
            echo '<p>' . esc_html__('Configurez OpenAI pour générer automatiquement un message d'accroche percutant.', 'fbpublishplugin') . '</p>';
        }, self::OPTION_KEY);

        add_settings_field('openai_api_key', __('Clé API OpenAI', 'fbpublishplugin'), function () {
            $options = self::get_options();
            echo '<input type="password" class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[openai_api_key]" value="' . esc_attr($options['openai_api_key']) . '" placeholder="sk-..." />';
            echo '<p class="description">' . esc_html__('Clé API OpenAI pour générer les messages d'accroche. Obtenez-la sur platform.openai.com.', 'fbpublishplugin') . '</p>';
        }, self::OPTION_KEY, 'fbpublish_openai');

        add_settings_field('enable_ai_hook', __('Activer l'accroche IA', 'fbpublishplugin'), function () {
            $options = self::get_options();
            $val = isset($options['enable_ai_hook']) ? $options['enable_ai_hook'] : '0';
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[enable_ai_hook]" value="1" ' . checked('1', $val, false) . ' /> ' . esc_html__('Générer automatiquement un message d'accroche via IA avant le lien.', 'fbpublishplugin') . '</label>';
            echo '<p class="description">' . esc_html__('L'IA génère un message percutant basé sur le titre et l'extrait de l'article.', 'fbpublishplugin') . '</p>';
        }, self::OPTION_KEY, 'fbpublish_openai');
    }

    public function add_settings_page() {
        add_options_page(
            __('Publication Facebook', 'fbpublishplugin'),
            __('Publication Facebook', 'fbpublishplugin'),
            'manage_options',
            self::OPTION_KEY,
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Publication Facebook', 'fbpublishplugin') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_KEY);
        do_settings_sections(self::OPTION_KEY);
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function register_meta_box() {
        add_meta_box(
            'fbpublish_meta',
            __('Publication Facebook', 'fbpublishplugin'),
            [$this, 'render_meta_box'],
            ['post'],
            'side',
            'default'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field(self::NONCE_META_BOX, self::NONCE_META_BOX);
        $options = self::get_options();
        $has_page = !empty($options['page_id']);

        $post_to_page = get_post_meta($post->ID, '_fbpublish_post_to_page', true);
        $custom_message = get_post_meta($post->ID, '_fbpublish_custom_message', true);
        $share_as_photo = get_post_meta($post->ID, '_fbpublish_share_as_photo', true);
        $global_default_photo = (isset($options['share_format']) && $options['share_format'] === 'photo');

        if ($post_to_page === '') {
            $post_to_page = $has_page ? '1' : '0';
        }

        echo '<p><label><input type="checkbox" name="fbpublish_post_to_page" value="1" ' . checked('1', $post_to_page, false) . ' ' . disabled(!$has_page, true, false) . ' /> ' . esc_html__('Publier sur la Page Facebook', 'fbpublishplugin') . '</label></p>';
        if (!$has_page) {
            echo '<p class="description">' . esc_html__('Aucune Page configurée.', 'fbpublishplugin') . '</p>';
        }

        echo '<p><textarea name="fbpublish_custom_message" rows="3" class="widefat" placeholder="' . esc_attr__('Message personnalisé (optionnel)', 'fbpublishplugin') . '">' . esc_textarea($custom_message) . '</textarea></p>';

        // AI hook option
        $use_ai_hook = get_post_meta($post->ID, '_fbpublish_use_ai_hook', true);
        $global_ai_enabled = (isset($options['enable_ai_hook']) && $options['enable_ai_hook'] === '1');
        $has_openai_key = !empty($options['openai_api_key']);
        $checked_ai = ($use_ai_hook === '1') || ($use_ai_hook === '' && $global_ai_enabled);
        echo '<p><label><input type="checkbox" name="fbpublish_use_ai_hook" value="1" ' . checked(true, $checked_ai, false) . ' ' . disabled(!$has_openai_key, true, false) . ' /> ' . esc_html__('Générer accroche IA', 'fbpublishplugin') . '</label></p>';
        if (!$has_openai_key) {
            echo '<p class="description">' . esc_html__('Clé API OpenAI non configurée.', 'fbpublishplugin') . '</p>';
        }

        $thumb_url = get_the_post_thumbnail_url($post->ID, 'full');
        $checked_photo = ($share_as_photo === '1') || ($share_as_photo === '' && $global_default_photo);
        echo '<p><label><input type="checkbox" id="fbpublish_share_as_photo" name="fbpublish_share_as_photo" value="1" ' . checked(true, $checked_photo, false) . ' /> ' . esc_html__('Partager en photo (grand visuel)', 'fbpublishplugin') . '</label></p>';
        if (empty($thumb_url)) {
            echo '<p class="description">' . esc_html__('Astuce: ajoutez une image mise en avant pour un grand visuel.', 'fbpublishplugin') . '</p>';
        }

        $is_published = $post->post_status === 'publish';
        $button_disabled = $is_published ? '' : 'disabled';
        echo '<p><button type="button" class="button button-primary" id="fbpublish_manual_btn" ' . $button_disabled . '>' . esc_html__('Publier maintenant sur Facebook', 'fbpublishplugin') . '</button></p>';
        echo '<p><label><input type="checkbox" id="fbpublish_force" /> ' . esc_html__('Forcer la republication (ignorer la déduplication)', 'fbpublishplugin') . '</label></p>';
        if (!$is_published) {
            echo '<p class="description">' . esc_html__('Le bouton est actif lorsque l’article est publié.', 'fbpublishplugin') . '</p>';
        }

        // Diagnostics: last error and last share info
        $last_error_json = get_post_meta($post->ID, '_fbpublish_last_error', true);
        $last_shared_ts = intval(get_post_meta($post->ID, '_fbpublish_last_shared', true));
        if (!empty($last_error_json)) {
            $decoded = json_decode($last_error_json, true);
            $pretty = $decoded ? wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $last_error_json;
            echo '<details style="margin-top:8px"><summary>' . esc_html__('Dernière erreur Facebook', 'fbpublishplugin') . '</summary>';
            echo '<pre style="white-space:pre-wrap">' . esc_html($pretty) . '</pre>';
            echo '</details>';
        }
        if ($last_shared_ts > 0) {
            echo '<p class="description">' . sprintf(
                esc_html__('Dernier partage: %s', 'fbpublishplugin'),
                esc_html(human_time_diff($last_shared_ts, time()))
            ) . '</p>';
        }
    }

    public function save_meta_box($post_id) {
        if (!isset($_POST[self::NONCE_META_BOX]) || !wp_verify_nonce($_POST[self::NONCE_META_BOX], self::NONCE_META_BOX)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $post_to_page = isset($_POST['fbpublish_post_to_page']) ? '1' : '0';
        $custom_message = isset($_POST['fbpublish_custom_message']) ? wp_kses_post($_POST['fbpublish_custom_message']) : '';
        $share_as_photo = isset($_POST['fbpublish_share_as_photo']) ? '1' : '0';
        $use_ai_hook = isset($_POST['fbpublish_use_ai_hook']) ? '1' : '0';

        update_post_meta($post_id, '_fbpublish_post_to_page', $post_to_page);
        update_post_meta($post_id, '_fbpublish_custom_message', $custom_message);
        // Cleanup legacy group metas
        delete_post_meta($post_id, '_fbpublish_post_to_group');
        update_post_meta($post_id, '_fbpublish_share_as_photo', $share_as_photo);
        update_post_meta($post_id, '_fbpublish_use_ai_hook', $use_ai_hook);
    }

    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        wp_enqueue_script(
            'fbpublish_admin',
            plugins_url('assets/admin.js', __FILE__),
            ['jquery'],
            ' * Version: 1.0.1',
            true
        );
        wp_localize_script('fbpublish_admin', 'FBPUBLISH', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::AJAX_ACTION),
            'action' => self::AJAX_ACTION,
            'texts' => [
                'success' => __('Publication envoyée', 'fbpublishplugin'),
                'error' => __('Erreur lors de la publication', 'fbpublishplugin'),
            ],
        ]);
    }

    public function handle_manual_share_ajax() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Accès refusé', 'fbpublishplugin')], 403);
        }
        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        $post_id = isset($_POST['postId']) ? intval($_POST['postId']) : 0;
        if (!$post_id || get_post_status($post_id) !== 'publish') {
            wp_send_json_error(['message' => __('Article non publié', 'fbpublishplugin')], 400);
        }

        $to_page = isset($_POST['toPage']) && $_POST['toPage'] === '1';
        $force = isset($_POST['force']) && $_POST['force'] === '1';
        $as_photo = isset($_POST['shareAsPhoto']) && $_POST['shareAsPhoto'] === '1';
        $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';

        $destinations = [];
        if ($to_page) { $destinations[] = 'page'; }
        if (empty($destinations)) {
            wp_send_json_error(['message' => __('Aucune destination sélectionnée', 'fbpublishplugin')], 400);
        }

        $result = $this->share_to_facebook($post_id, $destinations, $message, $force, $as_photo);
        if ($result['success']) {
            wp_send_json_success(['message' => __('Publication réussie', 'fbpublishplugin'), 'details' => $result['details']]);
        }
        wp_send_json_error(['message' => __('Une ou plusieurs publications ont échoué', 'fbpublishplugin'), 'details' => $result['details']]);
    }

    public function maybe_auto_share_on_publish($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        if ($post->post_type !== 'post') {
            return;
        }
        $post_id = $post->ID;

        $to_page = get_post_meta($post_id, '_fbpublish_post_to_page', true);
        $custom_message = get_post_meta($post_id, '_fbpublish_custom_message', true);
        $destinations = [];
        if ($to_page === '1' || ($to_page === '' && !empty(self::get_options()['page_id']))) {
            $destinations[] = 'page';
        }
        if (empty($destinations)) {
            return;
        }
        // Mark as published to prevent reposting on updates
        update_post_meta($post_id, '_fbpublish_was_published', '1');
        // Delay share to allow OG cache and CDN to settle, especially for scheduled posts
        $delay = ($old_status === 'future') ? 20 : 3; // seconds
        $options = self::get_options();
        $force = ($old_status === 'future' && isset($options['force_on_scheduled']) && $options['force_on_scheduled'] === '1');

        // If running under server cron or wp-cron is disabled, execute inline with a short sleep
        if ((defined('DOING_CRON') && DOING_CRON) || (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
            if ($delay > 0) {
                // sleep is acceptable in cron context; avoids relying on another cron tick
                sleep($delay);
            }
            $this->share_to_facebook($post_id, $destinations, $custom_message, $force);
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK, [$post_id, $destinations, $custom_message, $force])) {
            wp_schedule_single_event(time() + $delay, self::CRON_HOOK, [$post_id, $destinations, $custom_message, $force]);
            // Proactively trigger WP-Cron to ensure the event runs on low-traffic sites
            $cron_url = site_url('wp-cron.php');
            if ($cron_url) {
                wp_remote_post($cron_url, [ 'timeout' => 0.01, 'blocking' => false ]);
            }
        }
    }

    public function auto_share_simple($post_id, $post) {
        if (get_post_type($post_id) !== 'post') {
            return;
        }
        // This is a fallback; main handler uses transition_post_status.
        // Check if post was already published to avoid reposting on updates
        $was_already_published = get_post_meta($post_id, '_fbpublish_was_published', true) === '1';
        if ($was_already_published) {
            return; // Don't repost on updates
        }
        // Mark as published for future updates
        update_post_meta($post_id, '_fbpublish_was_published', '1');
        $this->maybe_auto_share_on_publish('publish', 'draft', get_post($post_id));
    }

    private function replace_placeholders($message, $post_id) {
        $post = get_post($post_id);
        $title = $this->normalize_text(get_the_title($post_id));
        $replacements = [
            '{title}' => $title,
            '{link}' => get_permalink($post_id),
        ];
        return strtr($message, $replacements);
    }

    private function build_message($post_id, $message_override = '') {
        $options = self::get_options();

        // Check per-article AI hook setting (fallback to global)
        $use_ai_hook = get_post_meta($post_id, '_fbpublish_use_ai_hook', true);
        $global_ai_enabled = (isset($options['enable_ai_hook']) && $options['enable_ai_hook'] === '1');
        $ai_enabled = ($use_ai_hook === '1') || ($use_ai_hook === '' && $global_ai_enabled);

        // If AI hook is enabled and no custom message override, try to generate AI hook
        if (trim($message_override) === '' && $ai_enabled) {
            $ai_hook = $this->generate_ai_hook($post_id);
            if (!empty($ai_hook)) {
                return $this->normalize_text($ai_hook);
            }
        }

        $message = trim($message_override) !== '' ? $message_override : $options['default_message'];
        $message = $this->replace_placeholders($message, $post_id);
        return $this->normalize_text($message);
    }

    private function generate_ai_hook($post_id) {
        $options = self::get_options();
        $api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';

        if (empty($api_key)) {
            return '';
        }

        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        $title = $this->normalize_text(get_the_title($post_id));
        $excerpt = get_the_excerpt($post_id);
        if (empty($excerpt)) {
            $excerpt = wp_trim_words(strip_tags($post->post_content), 55, '...');
        }
        $excerpt = $this->normalize_text($excerpt);

        $prompt = "Tu es un expert en copywriting Facebook, spécialisé dans le domaine des sports canins. 
Génère un message Facebook court, percutant et très cliquable pour annoncer un nouvel article.

Contraintes :
- Ton ton doit être humain, naturel, jamais générique.
- Accroche obligatoire en 1 phrase : émotion, question forte ou erreur à éviter.
- Utilise le contenu suivant :
  - Titre de l'article : {$title}
  - Extrait de l'article : {$excerpt}
- Puis rédige 1 à 2 phrases maximum pour donner envie de cliquer.
- Ne fais pas de résumé de l'article.
- Ne révèle pas la réponse : crée de la curiosité.
- Ne répète pas le titre.
- Ne mets PAS le lien : le plugin l'ajoutera après.
- Optimise pour un taux de clic maximum.

Format final : 
2 à 3 phrases courtes, dynamiques, sans hashtags, sans emojis, sans conclusion.

Commence directement par l'accroche.";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => 200,
                'temperature' => 0.8,
            ]),
        ]);

        if (is_wp_error($response)) {
            update_post_meta($post_id, '_fbpublish_ai_error', $response->get_error_message());
            return '';
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        if ($code !== 200 || empty($body['choices'][0]['message']['content'])) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $code;
            update_post_meta($post_id, '_fbpublish_ai_error', $error_msg);
            return '';
        }

        $ai_message = trim($body['choices'][0]['message']['content']);
        // Store the generated hook for debugging
        update_post_meta($post_id, '_fbpublish_ai_hook', $ai_message);
        delete_post_meta($post_id, '_fbpublish_ai_error');

        return $ai_message;
    }

    private function normalize_text($text) {
        // Decode special chars and named HTML entities
        if (function_exists('wp_specialchars_decode')) {
            $text = wp_specialchars_decode($text, ENT_QUOTES);
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalize curly quotes/apostrophes and non-breaking spaces
        $search = [
            "\xE2\x80\x98", // ‘
            "\xE2\x80\x99", // ’
            "\xE2\x80\x9C", // “
            "\xE2\x80\x9D", // ”
            "\xC2\xA0",     // NBSP
        ];
        $replace = ["'", "'", '"', '"', ' '];
        $text = str_replace($search, $replace, $text);
        // Collapse whitespace and trim
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    public function handle_deferred_share($post_id, $destinations, $message_override, $force = false) {
        $status = get_post_status($post_id);
        if ($status !== 'publish') {
            return;
        }
        $this->share_to_facebook($post_id, (array) $destinations, (string) $message_override, (bool) $force);
    }

    private function has_already_shared($post_id, $dest) {
        $key = '_fbpublish_shared_page';
        return (bool) get_post_meta($post_id, $key, true);
    }

    private function mark_shared($post_id, $dest, $response_body) {
        $key = '_fbpublish_shared_page';
        update_post_meta($post_id, $key, 1);
        if (!empty($response_body)) {
            update_post_meta($post_id, $key . '_response', wp_json_encode($response_body));
        }
        update_post_meta($post_id, '_fbpublish_last_shared', time());
        // Cleanup legacy group metas
        delete_post_meta($post_id, '_fbpublish_shared_group');
        delete_post_meta($post_id, '_fbpublish_shared_group_response');
    }

    private function http_post_graph($endpoint, $params) {
        $url = 'https://graph.facebook.com/v20.0/' . ltrim($endpoint, '/');
        $response = wp_remote_post($url, [
            'timeout' => 20,
            'body' => $params,
        ]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message(), 'status' => 0, 'body' => null];
        }
        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'status' => $code, 'body' => $body];
        }
        $error_message = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $code;
        return ['ok' => false, 'error' => $error_message, 'status' => $code, 'body' => $body];
    }

    private function share_to_destination($post_id, $dest, $message, $as_photo_override = null) {
        $options = self::get_options();
        $access_token = !empty($options['page_access_token']) ? $options['page_access_token'] : $options['access_token'];
        if (empty($access_token)) {
            return ['ok' => false, 'error' => __('Jeton manquant', 'fbpublishplugin')];
        }
        $link = get_permalink($post_id);
        $page_id = $options['page_id'];
        if (empty($page_id)) {
            return ['ok' => false, 'error' => __('ID de Page manquant', 'fbpublishplugin')];
        }
        // Determine share format (link vs photo)
        $share_as_photo = false;
        if (is_bool($as_photo_override)) {
            $share_as_photo = $as_photo_override;
        } else {
            $meta_flag = get_post_meta($post_id, '_fbpublish_share_as_photo', true);
            if ($meta_flag === '1') {
                $share_as_photo = true;
            } else {
                $share_as_photo = (isset($options['share_format']) && $options['share_format'] === 'photo');
            }
        }

        if ($share_as_photo) {
            $image_url = get_the_post_thumbnail_url($post_id, 'full');
            if (!$image_url) {
                $image_url = $this->discover_og_image($link);
            }
            if ($image_url) {
                $caption = trim($message . "\n\n" . $link);
                $params = [
                    'url' => $image_url,
                    'message' => $caption,
                    'access_token' => $access_token,
                ];
                return $this->http_post_graph($page_id . '/photos', $params);
            }
            // Fallback to link if no image
        }

        // Link share path
        $this->prewarm_facebook_scrape($link, $access_token);
        $params = [
            'message' => $message,
            'link' => $link,
            'access_token' => $access_token,
        ];
        return $this->http_post_graph($page_id . '/feed', $params);
    }

    private function prewarm_facebook_scrape($url, $access_token) {
        if (empty($url) || empty($access_token)) {
            return;
        }
        $endpoint = '/';
        $params = [
            'id' => $url,
            'scrape' => 'true',
            'access_token' => $access_token,
        ];
        // Fire-and-forget; ignore result
        $this->http_post_graph($endpoint, $params);
    }

    private function discover_og_image($url) {
        $response = wp_remote_get($url, [ 'timeout' => 10 ]);
        if (is_wp_error($response)) {
            return '';
        }
        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            return '';
        }
        // Simple OG image extraction
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $m)) {
            return esc_url_raw(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    public function share_to_facebook($post_id, $destinations, $message_override = '', $force = false, $as_photo_override = null) {
        $details = [];
        $message = $this->build_message($post_id, $message_override);

        foreach ($destinations as $dest) {
            if (!$force && $this->has_already_shared($post_id, $dest)) {
                $details[$dest] = ['ok' => true, 'skipped' => true, 'reason' => 'already_shared'];
                continue;
            }
            $resp = $this->share_to_destination($post_id, $dest, $message, $as_photo_override);
            if ($resp['ok']) {
                $this->mark_shared($post_id, $dest, $resp['body']);
                $details[$dest] = [
                    'ok' => true,
                    'id' => isset($resp['body']['id']) ? $resp['body']['id'] : null,
                    'mode' => ($as_photo_override || get_post_meta($post_id, '_fbpublish_share_as_photo', true) === '1' || (isset(self::get_options()['share_format']) && self::get_options()['share_format'] === 'photo')) ? 'photo' : 'link'
                ];
            } else {
                $details[$dest] = ['ok' => false, 'error' => $resp['error'], 'status' => $resp['status']];
                update_post_meta($post_id, '_fbpublish_last_error', wp_json_encode($resp));
            }
        }

        $success = true;
        foreach ($details as $d) {
            if (isset($d['ok']) && $d['ok'] === false) {
                $success = false;
                break;
            }
        }
        return ['success' => $success, 'details' => $details];
    }
}

new FB_Publish_Plugin();


