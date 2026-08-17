<?php
/**
 * Plugin Name: footballi league sync
 * Plugin URI: https://example.com
 * Description: نمایش حرفه‌ای نتایج زنده، برنامه بازی‌ها و جدول لیگ‌ها از منابع آنلاین، با شورت‌کد اختصاصی هر لیگ و نمای کلی همه لیگ‌ها.
 * Version: 3.10.2
 * Author: Vira Agency
 * Text Domain: f360-league-score-pro
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

define('F360LS_VERSION', '3.10.2');
define('F360LS_PLUGIN_FILE', __FILE__);
define('F360LS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('F360LS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('F360LS_OPTION_LEAGUES', 'f360ls_leagues');
define('F360LS_OPTION_SETTINGS', 'f360ls_settings');
define('F360LS_OPTION_LOGS', 'f360ls_logs');
define('F360LS_OPTION_DIRECTORY', 'f360ls_footballi_directory');
define('F360LS_CACHE_PREFIX', 'f360ls_league_data_');
define('F360LS_CACHE_TTL', 6 * HOUR_IN_SECONDS);
define('F360LS_CRON_HOOK', 'f360ls_hourly_update');

require_once F360LS_PLUGIN_DIR . 'includes/parsers/class-f360ls-parser.php';
require_once F360LS_PLUGIN_DIR . 'includes/parsers/class-f360ls-json-parser.php';
require_once F360LS_PLUGIN_DIR . 'includes/parsers/class-f360ls-footballi-parser.php';
require_once F360LS_PLUGIN_DIR . 'includes/core/class-f360ls-logger.php';
require_once F360LS_PLUGIN_DIR . 'includes/core/class-f360ls-repository.php';
require_once F360LS_PLUGIN_DIR . 'includes/admin/class-f360ls-admin.php';
require_once F360LS_PLUGIN_DIR . 'includes/frontend/class-f360ls-shortcodes.php';

final class F360LS_Plugin {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function boot() {
        F360LS_Repository::instance()->ensure_upload_dir();
        F360LS_Repository::instance()->cleanup_legacy_builtin_json_leagues();
        F360LS_Repository::instance()->scan_plugin_html_files();
        F360LS_Repository::instance()->scan_plugin_json_files();
        if (is_admin()) new F360LS_Admin();
        new F360LS_Shortcodes();
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action(F360LS_CRON_HOOK, [$this, 'hourly_update']);
        $this->maybe_schedule_cron();
    }

    private function get_settings(): array {
        $settings = get_option(F360LS_OPTION_SETTINGS, []);
        $defaults = [
            'hourly_cron' => '1',
        ];
        return wp_parse_args(is_array($settings) ? $settings : [], $defaults);
    }

    private function maybe_schedule_cron(): void {
        $settings = $this->get_settings();
        if (($settings['hourly_cron'] ?? '1') === '1') {
            if (!wp_next_scheduled(F360LS_CRON_HOOK)) {
                wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', F360LS_CRON_HOOK);
            }
        } else {
            wp_clear_scheduled_hook(F360LS_CRON_HOOK);
        }
    }

    public function hourly_update(): void {
        $settings = $this->get_settings();
        if (($settings['hourly_cron'] ?? '1') !== '1') return;
        $repo = F360LS_Repository::instance();
        foreach ($repo->get_enabled_leagues() as $league) {
            if (empty($league['id'])) continue;
            $repo->clear_cache($league['id']);
            $repo->parse_league($league['id']);
        }
        if (class_exists('F360LS_Logger')) {
            F360LS_Logger::log('info', 'بروزرسانی خودکار ساعتی اجرا شد.', ['count' => count($repo->get_enabled_leagues())]);
        }
    }

    public function register_assets() {
        wp_register_style('f360ls-front', F360LS_PLUGIN_URL . 'assets/css/front.css', [], F360LS_VERSION);
        wp_register_script('f360ls-front', F360LS_PLUGIN_URL . 'assets/js/front.js', [], F360LS_VERSION, true);
    }

    public function activate() {
        $repo = F360LS_Repository::instance();
        $repo->ensure_upload_dir();

        $sample = F360LS_PLUGIN_DIR . 'data/leagues/premier-league-sample.html';
        if (!file_exists($sample)) {
            file_put_contents($sample, '<!doctype html><html><body><div class="style_container__Fk1k4 container"><header><div class="style_header__tkz51"><h1 class="style_title__dAUjZ">لیگ برتر انگلیس</h1></div></header><div class="style_lastUpdate__DTIb4">آخرین به‌روزرسانی: نمونه</div><div class="style_containerStandingTable__LeWck"><table><tbody><tr class="style_content__7_00X"><td class="style_right__2Uojd"><span class="style_number__FM8qt">1</span><a><span class="style_name__0jbNK">آرسنال</span></a></td><td></td><td>38</td><td>26</td><td>7</td><td>5</td><td>44</td><td>27-71</td><td class="style_boldLastChild__cHt5s">85</td></tr></tbody></table></div><div class="LeagueMatches_section__n6sHO"><h2 class="LeagueMatches_title__Aplzj">هفته نمونه</h2><ul><li><a class="style_MatchItem__9fzN3"><div class="style_HomeTeam__Bi3Zc"><span class="style_title__VxtR3">منچسترسیتی</span></div><div class="style_Result__M8la1"><div class="style_match__Fiqcg">3 - 1</div><div class="style_date__t6_B6">پایان</div></div><div class="style_AwayTeam__HPFe1"><span class="style_title__VxtR3">چلسی</span></div></a></li></ul></div></div></body></html>');
        }

        $repo->scan_plugin_html_files();
        $repo->scan_plugin_json_files();
        if (!wp_next_scheduled(F360LS_CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', F360LS_CRON_HOOK);
        }

        if (empty($repo->get_leagues())) {
            $repo->upsert_league([
                'id' => 'premier-league',
                'title' => 'لیگ برتر انگلیس',
                'subtitle' => 'نمونه آماده برای تست',
                'source_url' => 'https://football360.ir/league/fcec7abb-dead-49c3-a907-1948e33fa438/20252026-Premier-League/games',
                'games_url' => 'https://football360.ir/league/fcec7abb-dead-49c3-a907-1948e33fa438/20252026-Premier-League/games',
                'file' => $sample,
                'is_plugin_file' => true,
                'enabled' => true,
            ]);
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook(F360LS_CRON_HOOK);
        F360LS_Repository::instance()->clear_all_caches();
    }
}

F360LS_Plugin::instance();
