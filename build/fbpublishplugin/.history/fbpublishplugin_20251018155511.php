<?php
/**
 * Plugin Name: FB Publish Plugin
 * Description: Publie automatiquement le lien de l’article sur une page et un groupe Facebook lors du passage de brouillon à publié (y compris planification). Offre une méta box pour publication manuelle avec message personnalisé.
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
    }

    public static function get_options() {
        $defaults = [
            'access_token' => '',
            'page_id' => '',
            'group_id' => '',
            'default_message' => 'Nouvel article: {title}',
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
                $sanitized['page_id'] = isset($input['page_id']) ? sanitize_text_field($input['page_id']) : '';
                $sanitized['group_id'] = isset($input['group_id']) ? sanitize_text_field($input['group_id']) : '';
                $sanitized['default_message'] = isset($input['default_message']) ? wp_kses_post($input['default_message']) : '';
                return $sanitized;
            },
            'default' => self::get_options(),
        ]);

        add_settings_section('fbpublish_main', __('Paramètres Facebook', 'fbpublishplugin'), function () {
            echo '<p>' . esc_html__('Configurez le jeton, les IDs et le message par défaut.', 'fbpublishplugin') . '</p>';
        }, self::OPTION_KEY);

        add_settings_field('access_token', __('Jeton d’accès (Graph API)', 'fbpublishplugin'), function () {
            $options = self::get_options();
            echo '<input type="text" class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[access_token]" value="' . esc_attr($options['access_token']) . '" placeholder="EA..." />';
            echo '<p class="description">' . esc_html__('Fournissez un jeton d’accès valide (idéalement de page) avec les permissions nécessaires (pages_manage_posts, pages_read_engagement, publish_to_groups).', 'fbpublishplugin') . '</p>';
        }, self::OPTION_KEY, 'fbpublish_main');

        add_settings_field('page_id', __('ID de la Page Facebook', 'fbpublishplugin'), function () {
            $options = self::get_options();
            echo '<input type="text" class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[page_id]" value="' . esc_attr($options['page_id']) . '" />';
        }, self::OPTION_KEY, 'fbpublish_main');

        add_settings_field('group_id', __('ID du Groupe Facebook', 'fbpublishplugin'), function () {
            $options = self::get_options();
            echo '<input type="text" class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[group_id]" value="' . esc_attr($options['group_id']) . '" />';
        }, self::OPTION_KEY, 'fbpublish_main');

        add_settings_field('default_message', __('Message par défaut', 'fbpublishplugin'), function () {
            $options = self::get_options();
            echo '<textarea class="large-text" rows="3" name="' . esc_attr(self::OPTION_KEY) . '[default_message]">' . esc_textarea($options['default_message']) . '</textarea>';
            echo '<p class="description">' . esc_html__('Placeholders disponibles: {title} {link}', 'fbpublishplugin') . '</p>';
        }, self::OPTION_KEY, 'fbpublish_main');
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
        $has_group = !empty($options['group_id']);

        $post_to_page = get_post_meta($post->ID, '_fbpublish_post_to_page', true);
        $post_to_group = get_post_meta($post->ID, '_fbpublish_post_to_group', true);
        $custom_message = get_post_meta($post->ID, '_fbpublish_custom_message', true);

        if ($post_to_page === '') {
            $post_to_page = $has_page ? '1' : '0';
        }
        if ($post_to_group === '') {
            $post_to_group = $has_group ? '1' : '0';
        }

        echo '<p><label><input type="checkbox" name="fbpublish_post_to_page" value="1" ' . checked('1', $post_to_page, false) . ' ' . disabled(!$has_page, true, false) . ' /> ' . esc_html__('Publier sur la Page Facebook', 'fbpublishplugin') . '</label></p>';
        if (!$has_page) {
            echo '<p class="description">' . esc_html__('Aucune Page configurée.', 'fbpublishplugin') . '</p>';
        }

        echo '<p><label><input type="checkbox" name="fbpublish_post_to_group" value="1" ' . checked('1', $post_to_group, false) . ' ' . disabled(!$has_group, true, false) . ' /> ' . esc_html__('Publier dans le Groupe Facebook', 'fbpublishplugin') . '</label></p>';
        if (!$has_group) {
            echo '<p class="description">' . esc_html__('Aucun Groupe configuré.', 'fbpublishplugin') . '</p>';
        }

        echo '<p><textarea name="fbpublish_custom_message" rows="3" class="widefat" placeholder="' . esc_attr__('Message personnalisé (optionnel)', 'fbpublishplugin') . '">' . esc_textarea($custom_message) . '</textarea></p>';

        $is_published = $post->post_status === 'publish';
        $button_disabled = $is_published ? '' : 'disabled';
        echo '<p><button type="button" class="button button-primary" id="fbpublish_manual_btn" ' . $button_disabled . '>' . esc_html__('Publier maintenant sur Facebook', 'fbpublishplugin') . '</button></p>';
        if (!$is_published) {
            echo '<p class="description">' . esc_html__('Le bouton est actif lorsque l’article est publié.', 'fbpublishplugin') . '</p>';
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
        $post_to_group = isset($_POST['fbpublish_post_to_group']) ? '1' : '0';
        $custom_message = isset($_POST['fbpublish_custom_message']) ? wp_kses_post($_POST['fbpublish_custom_message']) : '';

        update_post_meta($post_id, '_fbpublish_post_to_page', $post_to_page);
        update_post_meta($post_id, '_fbpublish_post_to_group', $post_to_group);
        update_post_meta($post_id, '_fbpublish_custom_message', $custom_message);
    }

    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        wp_enqueue_script(
            'fbpublish_admin',
            plugins_url('assets/admin.js', __FILE__),
            ['jquery'],
            '1.0.0',
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
        $to_group = isset($_POST['toGroup']) && $_POST['toGroup'] === '1';
        $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';

        $destinations = [];
        if ($to_page) { $destinations[] = 'page'; }
        if ($to_group) { $destinations[] = 'group'; }
        if (empty($destinations)) {
            wp_send_json_error(['message' => __('Aucune destination sélectionnée', 'fbpublishplugin')], 400);
        }

        $result = $this->share_to_facebook($post_id, $destinations, $message);
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
        $to_group = get_post_meta($post_id, '_fbpublish_post_to_group', true);
        $custom_message = get_post_meta($post_id, '_fbpublish_custom_message', true);
        $destinations = [];
        if ($to_page === '1' || ($to_page === '' && !empty(self::get_options()['page_id']))) {
            $destinations[] = 'page';
        }
        if ($to_group === '1' || ($to_group === '' && !empty(self::get_options()['group_id']))) {
            $destinations[] = 'group';
        }
        if (empty($destinations)) {
            return;
        }
        $this->share_to_facebook($post_id, $destinations, $custom_message);
    }

    public function auto_share_simple($post_id, $post) {
        if (get_post_type($post_id) !== 'post') {
            return;
        }
        // This is a fallback; main handler uses transition_post_status. Avoid double-post using flags.
        $this->maybe_auto_share_on_publish('publish', 'draft', get_post($post_id));
    }

    private function replace_placeholders($message, $post_id) {
        $post = get_post($post_id);
        $replacements = [
            '{title}' => get_the_title($post_id),
            '{link}' => get_permalink($post_id),
        ];
        return strtr($message, $replacements);
    }

    private function build_message($post_id, $message_override = '') {
        $options = self::get_options();
        $message = trim($message_override) !== '' ? $message_override : $options['default_message'];
        return $this->replace_placeholders($message, $post_id);
    }

    private function has_already_shared($post_id, $dest) {
        $key = $dest === 'page' ? '_fbpublish_shared_page' : '_fbpublish_shared_group';
        return (bool) get_post_meta($post_id, $key, true);
    }

    private function mark_shared($post_id, $dest, $response_body) {
        $key = $dest === 'page' ? '_fbpublish_shared_page' : '_fbpublish_shared_group';
        update_post_meta($post_id, $key, 1);
        if (!empty($response_body)) {
            update_post_meta($post_id, $key . '_response', wp_json_encode($response_body));
        }
        update_post_meta($post_id, '_fbpublish_last_shared', time());
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

    private function share_to_destination($post_id, $dest, $message) {
        $options = self::get_options();
        $access_token = $options['access_token'];
        if (empty($access_token)) {
            return ['ok' => false, 'error' => __('Jeton manquant', 'fbpublishplugin')];
        }
        $link = get_permalink($post_id);
        if ($dest === 'page') {
            $page_id = $options['page_id'];
            if (empty($page_id)) {
                return ['ok' => false, 'error' => __('ID de Page manquant', 'fbpublishplugin')];
            }
            $params = [
                'message' => $message,
                'link' => $link,
                'access_token' => $access_token,
            ];
            return $this->http_post_graph($page_id . '/feed', $params);
        }
        if ($dest === 'group') {
            $group_id = $options['group_id'];
            if (empty($group_id)) {
                return ['ok' => false, 'error' => __('ID de Groupe manquant', 'fbpublishplugin')];
            }
            $params = [
                'message' => $message,
                'link' => $link,
                'access_token' => $access_token,
            ];
            return $this->http_post_graph($group_id . '/feed', $params);
        }
        return ['ok' => false, 'error' => __('Destination inconnue', 'fbpublishplugin')];
    }

    public function share_to_facebook($post_id, $destinations, $message_override = '') {
        $details = [];
        $message = $this->build_message($post_id, $message_override);

        foreach ($destinations as $dest) {
            if ($this->has_already_shared($post_id, $dest)) {
                $details[$dest] = ['ok' => true, 'skipped' => true, 'reason' => 'already_shared'];
                continue;
            }
            $resp = $this->share_to_destination($post_id, $dest, $message);
            if ($resp['ok']) {
                $this->mark_shared($post_id, $dest, $resp['body']);
                $details[$dest] = ['ok' => true, 'id' => isset($resp['body']['id']) ? $resp['body']['id'] : null];
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


