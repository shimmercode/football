<?php
if (!defined('ABSPATH')) { exit; }

class F360LS_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_f360ls_save_league', [$this, 'save_league']);
        add_action('admin_post_f360ls_save_settings', [$this, 'save_settings']);
        add_action('admin_post_f360ls_delete_league', [$this, 'delete_league']);
        add_action('admin_post_f360ls_bulk_delete_leagues', [$this, 'bulk_delete_leagues']);
        add_action('admin_post_f360ls_reorder_leagues', [$this, 'reorder_leagues']);
        add_action('admin_post_f360ls_clear_cache', [$this, 'clear_cache']);
        add_action('admin_post_f360ls_clear_league_cache', [$this, 'clear_league_cache']);
        add_action('admin_post_f360ls_scan_files', [$this, 'scan_files']);
        add_action('admin_post_f360ls_import_quick_leagues', [$this, 'import_quick_leagues']);
        add_action('admin_post_f360ls_scan_footballi_directory', [$this, 'scan_footballi_directory']);
        add_action('admin_post_f360ls_import_footballi_directory', [$this, 'import_footballi_directory']);
        add_action('admin_post_f360ls_clear_footballi_directory', [$this, 'clear_footballi_directory']);
        add_action('admin_post_f360ls_clear_logs', [$this, 'clear_logs']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void {
        add_menu_page('F360 لیگ‌ها', 'F360 لیگ‌ها', 'manage_options', 'f360ls', [$this, 'render'], 'dashicons-awards', 58);
    }

    public function assets($hook): void {
        if ($hook !== 'toplevel_page_f360ls') return;
        wp_enqueue_style('f360ls-admin', F360LS_PLUGIN_URL . 'assets/css/admin.css', [], F360LS_VERSION);
        $settings = $this->get_settings();
        if (!empty($settings['custom_font_url'])) {
            wp_add_inline_style('f360ls-admin', "@font-face{font-family:'F360LSCustomFont';src:url('" . esc_url($settings['custom_font_url']) . "');font-display:swap}.f360ls-admin,.f360ls-admin *{font-family:'F360LSCustomFont',Tahoma,Arial,sans-serif!important}");
        }
        wp_enqueue_script('f360ls-admin', F360LS_PLUGIN_URL . 'assets/js/admin.js', [], F360LS_VERSION, true);
    }

    public function render(): void {
        if (!current_user_can('manage_options')) return;
        $tab = sanitize_key($_GET['tab'] ?? 'leagues');
        $allowed = ['leagues','directory','appearance','shortcodes','tools','health','logs'];
        if (!in_array($tab, $allowed, true)) $tab = 'leagues';
        ?>
        <div class="wrap f360ls-admin">
            <div class="f360ls-admin-hero">
                <div>
                    <h1>F360 League Score Pro</h1>
                    <p>مرکز مدیریت لیگ‌ها، نتایج زنده، جدول‌ها، شورت‌کدها و ظاهر افزونه</p>
                </div>
                <span>v<?php echo esc_html(F360LS_VERSION); ?></span>
            </div>
            <?php if (!empty($_GET['f360ls_msg'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['f360ls_msg'])); ?></p></div>
            <?php endif; ?>
            <nav class="nav-tab-wrapper f360ls-nav">
                <?php foreach (['leagues'=>'لیگ‌ها','directory'=>'اسکرپر رقابت‌ها','appearance'=>'ظاهر و رنگ‌ها','shortcodes'=>'راهنمای شورت‌کدها','tools'=>'ابزارها و تست','health'=>'سلامت سیستم','logs'=>'گزارش خطاها'] as $key => $label): ?>
                    <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page'=>'f360ls','tab'=>$key], admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php
            if ($tab === 'directory') $this->render_directory_tab();
            elseif ($tab === 'appearance') $this->render_appearance_tab();
            elseif ($tab === 'shortcodes') $this->render_shortcodes_tab();
            elseif ($tab === 'tools') $this->render_tools_tab();
            elseif ($tab === 'health') $this->render_health_tab();
            elseif ($tab === 'logs') $this->render_logs_tab();
            else $this->render_leagues_tab();
            ?>
        </div>
        <?php
    }

    private function render_leagues_tab(): void {
        $repo = F360LS_Repository::instance();
        $leagues = $repo->get_leagues();
        $totals = ['leagues'=>count($leagues),'active'=>0,'matches'=>0,'teams'=>0,'live'=>0];
        foreach ($leagues as $league) {
            if (!isset($league['enabled']) || $league['enabled']) $totals['active']++;
            $data = !empty($league['id']) ? $repo->parse_league($league['id']) : [];
            $totals['matches'] += intval($data['stats']['total'] ?? 0);
            $totals['teams'] += intval($data['stats']['teams'] ?? 0);
            $totals['live'] += intval($data['stats']['live'] ?? 0);
        }
        ?>
        <div class="f360ls-dashboard-cards">
            <div><strong><?php echo intval($totals['leagues']); ?></strong><span>کل لیگ‌ها</span></div>
            <div><strong><?php echo intval($totals['active']); ?></strong><span>لیگ فعال</span></div>
            <div><strong><?php echo intval($totals['matches']); ?></strong><span>بازی استخراج‌شده</span></div>
            <div><strong><?php echo intval($totals['teams']); ?></strong><span>تیم در جدول‌ها</span></div>
            <div><strong><?php echo intval($totals['live']); ?></strong><span>بازی زنده</span></div>
        </div>
        <div class="f360ls-admin-grid">
            <?php $this->render_league_form(); ?>
            <div class="f360ls-card">
                <h2>شروع سریع</h2>
                <p>با یک کلیک رقابت‌های آماده را اضافه کن و بعد شورت‌کدها را در سایت قرار بده.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field('f360ls_import_quick_leagues'); ?>
                    <input type="hidden" name="action" value="f360ls_import_quick_leagues">
                    <?php submit_button('افزودن سریع رقابت‌های آماده', 'primary'); ?>
                </form>
                <hr>
                <p><strong>نمایش همه لیگ‌ها:</strong></p>
                <code>[f360_all_leagues]</code>
                <p><strong>بازی‌های زنده:</strong></p>
                <code>[f360_live_matches]</code>
                <p><strong>بازی‌های امروز:</strong></p>
                <code>[f360_today_matches]</code>
            </div>
        </div>
        <?php $this->render_leagues_table($leagues, $repo); ?>
        <?php
    }

    private function render_league_form(): void { ?>
        <div class="f360ls-card">
            <h2>افزودن یا بروزرسانی لیگ</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('f360ls_save_league'); ?>
                <input type="hidden" name="action" value="f360ls_save_league">
                <table class="form-table">
                    <tr><th><label for="league_id">شناسه لیگ</label></th><td><input name="league_id" id="league_id" type="text" class="regular-text" placeholder="premier-league" required><p class="description">برای شورت‌کد اختصاصی استفاده می‌شود. انگلیسی و بدون فاصله بهتر است.</p></td></tr>
                    <tr><th><label for="title">عنوان لیگ</label></th><td><input name="title" id="title" type="text" class="regular-text" placeholder="لیگ برتر انگلیس" required></td></tr>
                    <tr><th><label for="subtitle">زیرعنوان</label></th><td><input name="subtitle" id="subtitle" type="text" class="regular-text" placeholder="فصل 2025/2026"></td></tr>
                    <tr><th><label for="source_url">لینک منبع اصلی</label></th><td><input name="source_url" id="source_url" type="url" class="large-text" placeholder="https://footballi.net/competition/..."><p class="description">لینک صفحه لیگ/رقابت یا نتایج زنده را وارد کنید.</p></td></tr>
                    <tr><th><label for="games_url">لینک اختصاصی بازی‌ها</label></th><td><input name="games_url" id="games_url" type="url" class="large-text" placeholder="https://footballi.net/live-scores"><p class="description">اختیاری؛ برای نتایج زنده می‌توان /live-scores را وارد کرد.</p></td></tr>
                    <tr><th><label for="table_url">لینک اختصاصی جدول</label></th><td><input name="table_url" id="table_url" type="url" class="large-text" placeholder="https://footballi.net/competition/.../standing"><p class="description">اختیاری؛ لینک صفحه جدول یا لیگ.</p></td></tr>
                    <tr><th><label for="html_file">فایل HTML</label></th><td><input name="html_file" id="html_file" type="file" accept=".html,.htm"><p class="description">اگر لینک مستقیم کار نکرد، HTML ذخیره‌شده صفحه مرجع را آپلود کنید.</p></td></tr>
                    <tr><th><label for="json_file">فایل JSON</label></th><td><input name="json_file" id="json_file" type="file" accept=".json,application/json"><p class="description">اختیاری؛ فایل JSON بازی‌ها یا داده لیگ.</p></td></tr>
                    <tr><th>وضعیت</th><td><label><input type="checkbox" name="enabled" value="1" checked> فعال باشد</label></td></tr>
                </table>
                <?php submit_button('ذخیره لیگ'); ?>
            </form>
        </div>
    <?php }

    private function render_leagues_table(array $leagues, F360LS_Repository $repo): void { ?>
        <div class="f360ls-card">
            <h2>لیگ‌های ثبت‌شده</h2>
            <div class="f360ls-table-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="f360ls-bulk-delete-form">
                    <?php wp_nonce_field('f360ls_bulk_delete_leagues'); ?>
                    <input type="hidden" name="action" value="f360ls_bulk_delete_leagues">
                    <select name="league_ids[]" multiple size="5" class="f360ls-bulk-select">
                        <?php foreach ($leagues as $league): ?><option value="<?php echo esc_attr($league['id']); ?>"><?php echo esc_html(($league['title'] ?? $league['id']) . ' (' . $league['id'] . ')'); ?></option><?php endforeach; ?>
                    </select>
                    <?php submit_button('حذف دسته‌جمعی انتخاب‌شده‌ها', 'delete', 'submit', false, ['onclick' => "return confirm('لیگ‌های انتخاب‌شده حذف شوند؟')"]); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="f360ls-reorder-form">
                    <?php wp_nonce_field('f360ls_reorder_leagues'); ?>
                    <input type="hidden" name="action" value="f360ls_reorder_leagues">
                    <input type="hidden" name="league_order" value="" id="f360ls-league-order">
                    <?php submit_button('ذخیره ترتیب نمایش', 'secondary', 'submit', false); ?>
                </form>
            </div>
            <table class="widefat striped f360ls-leagues-table">
                <thead><tr><th>ترتیب</th><th>شناسه</th><th>عنوان</th><th>شورت‌کدها</th><th>منابع</th><th>وضعیت</th><th>داده</th><th>عملیات</th></tr></thead>
                <tbody id="f360ls-leagues-sortable">
                <?php if (empty($leagues)): ?><tr><td colspan="8">هنوز لیگی ثبت نشده است.</td></tr><?php endif; ?>
                <?php foreach ($leagues as $league):
                    $data = !empty($league['id']) ? $repo->parse_league($league['id']) : [];
                    $alias = sanitize_key(str_replace('-', '_', sanitize_title($league['id'] ?? '')));
                    $subtitle_display = $this->display_admin_subtitle((string) ($league['subtitle'] ?? ''));
                    ?>
                    <tr draggable="true" data-league-id="<?php echo esc_attr($league['id']); ?>">
                        <td class="f360ls-drag-handle" title="برای جابجایی بکشید">☰</td>
                        <td><code><?php echo esc_html($league['id']); ?></code></td>
                        <td><strong><?php echo esc_html($league['title'] ?? ''); ?></strong><?php if ($subtitle_display !== ''): ?><br><small><?php echo esc_html($subtitle_display); ?></small><?php endif; ?></td>
                        <td><code>[f360_league id=&quot;<?php echo esc_attr($league['id']); ?>&quot;]</code><br><?php if ($alias): ?><code>[f360_league_<?php echo esc_html($alias); ?>]</code><br><code>[f360_<?php echo esc_html($alias); ?>]</code><?php endif; ?></td>
                        <td><small><?php echo esc_html($league['source_url'] ?? ''); ?></small><br><small><?php echo esc_html($league['games_url'] ?? ''); ?></small><br><small><?php echo esc_html($league['table_url'] ?? ''); ?></small></td>
                        <td><?php echo (!isset($league['enabled']) || $league['enabled']) ? '<span class="f360ls-pill is-ok">فعال</span>' : '<span class="f360ls-pill">غیرفعال</span>'; ?></td>
                        <td>بازی‌ها: <?php echo intval($data['stats']['total'] ?? 0); ?><br>زنده: <?php echo intval($data['stats']['live'] ?? 0); ?><br>تیم‌ها: <?php echo intval($data['stats']['teams'] ?? 0); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0 0 6px">
                                <?php wp_nonce_field('f360ls_clear_league_cache'); ?><input type="hidden" name="action" value="f360ls_clear_league_cache"><input type="hidden" name="league_id" value="<?php echo esc_attr($league['id']); ?>"><button class="button">پاک‌کردن کش</button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                                <?php wp_nonce_field('f360ls_delete_league'); ?><input type="hidden" name="action" value="f360ls_delete_league"><input type="hidden" name="league_id" value="<?php echo esc_attr($league['id']); ?>"><button class="button button-link-delete" onclick="return confirm('حذف شود؟')">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php }

    private function display_admin_subtitle(string $subtitle): string {
        $subtitle = trim($subtitle);
        if ($subtitle === '') return '';
        if (preg_match('/Footballi|footballi|فوتبالی|کشف|اسکرپر|منبع/u', $subtitle)) return '';
        return $subtitle;
    }

    private function render_appearance_tab(): void {
        $s = $this->get_settings(); ?>
        <div class="f360ls-card f360ls-settings-card">
            <h2>تنظیمات ظاهر، کش و بروزرسانی</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('f360ls_save_settings'); ?><input type="hidden" name="action" value="f360ls_save_settings">
                <div class="f360ls-settings-grid">
                    <label><span>قالب پیش‌فرض</span><select name="default_theme"><option value="light" <?php selected($s['default_theme'], 'light'); ?>>روشن و مدرن</option><option value="dark" <?php selected($s['default_theme'], 'dark'); ?>>تیره و ورزشی</option></select></label>
                    <label><span>رنگ اصلی</span><input type="color" name="accent_color" value="<?php echo esc_attr($s['accent_color']); ?>"></label>
                    <label><span>رنگ دوم</span><input type="color" name="accent2_color" value="<?php echo esc_attr($s['accent2_color']); ?>"></label>
                    <label><span>پس‌زمینه</span><input type="color" name="background_color" value="<?php echo esc_attr($s['background_color']); ?>"></label>
                    <label><span>رنگ کارت‌ها</span><input type="color" name="card_color" value="<?php echo esc_attr($s['card_color']); ?>"></label>
                    <label><span>رنگ متن</span><input type="color" name="text_color" value="<?php echo esc_attr($s['text_color']); ?>"></label>
                    <label><span>گردی گوشه‌ها</span><input type="number" name="radius" min="12" max="44" value="<?php echo esc_attr($s['radius']); ?>"></label>
                    <label><span>تراکم نمایش</span><select name="density"><option value="comfortable" <?php selected($s['density'], 'comfortable'); ?>>راحت و بزرگ</option><option value="compact" <?php selected($s['density'], 'compact'); ?>>فشرده‌تر</option></select></label>
                    <label><span>بروزرسانی زنده Ajax در صفحه</span><select name="auto_refresh"><option value="0" <?php selected($s['auto_refresh'], '0'); ?>>غیرفعال</option><option value="1" <?php selected($s['auto_refresh'], '1'); ?>>فعال</option></select></label>
                    <label><span>آپدیت خودکار سرور هر ۱ ساعت</span><select name="hourly_cron"><option value="0" <?php selected($s['hourly_cron'], '0'); ?>>غیرفعال</option><option value="1" <?php selected($s['hourly_cron'], '1'); ?>>فعال</option></select><small>با WP-Cron هر ساعت کش لیگ‌های فعال را بروزرسانی می‌کند.</small></label>
                    <label><span>فاصله بروزرسانی Ajax/ثانیه</span><input type="number" name="refresh_interval" min="15" max="600" value="<?php echo esc_attr($s['refresh_interval']); ?>"></label>
                    <label><span>کش بازی زنده/ثانیه</span><input type="number" name="live_cache_ttl" min="15" max="900" value="<?php echo esc_attr($s['live_cache_ttl']); ?>"></label>
                    <label><span>کش عادی/ثانیه</span><input type="number" name="default_cache_ttl" min="300" max="86400" value="<?php echo esc_attr($s['default_cache_ttl']); ?>"></label>
                    <label class="f360ls-wide"><span>فونت اختصاصی سایت</span><input type="file" name="custom_font_file" accept=".woff2,.woff,.ttf"><small>فرمت پیشنهادی: woff2. بعد از آپلود، همین فونت برای خروجی افزونه استفاده می‌شود.</small><?php if (!empty($s['custom_font_url'])): ?><code><?php echo esc_html(basename($s['custom_font_url'])); ?></code><label class="f360ls-check"><input type="checkbox" name="remove_custom_font" value="1"> حذف فونت فعلی</label><?php endif; ?></label>
                    <label class="f360ls-wide"><span>دامنه‌های مجاز برای دریافت داده</span><textarea name="allowed_domains" rows="4" class="large-text" dir="ltr"><?php echo esc_textarea($s['allowed_domains']); ?></textarea><small>هر دامنه در یک خط. برای امنیت، URLهای خارج از این لیست fetch نمی‌شوند.</small></label>
                    <label class="f360ls-check"><input type="checkbox" name="show_source" value="1" <?php checked($s['show_source'], '1'); ?>><span>نمایش منبع دیتا</span></label>
                    <label class="f360ls-check"><input type="checkbox" name="show_hero" value="1" <?php checked($s['show_hero'], '1'); ?>><span>نمایش هدر گرافیکی</span></label>
                    <label class="f360ls-check"><input type="checkbox" name="animations" value="1" <?php checked($s['animations'], '1'); ?>><span>انیمیشن‌ها</span></label>
                </div>
                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>
        </div>
    <?php }

    private function render_shortcodes_tab(): void {
        $rows = $this->shortcode_docs(); ?>
        <div class="f360ls-card">
            <h2>راهنمای کامل شورت‌کدها</h2>
            <p>همه شورت‌کدهای افزونه در این بخش با توضیح و نمونه استفاده آمده‌اند.</p>
            <table class="widefat striped"><thead><tr><th>شورت‌کد</th><th>کاربرد</th><th>پارامترهای مهم</th></tr></thead><tbody>
            <?php foreach ($rows as $row): ?><tr><td><code><?php echo esc_html($row[0]); ?></code></td><td><?php echo esc_html($row[1]); ?></td><td><?php echo esc_html($row[2]); ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <div class="f360ls-card">
            <h2>شورت‌کد اختصاصی لیگ‌ها</h2>
            <p>برای هر لیگ بر اساس شناسه، دو شورت‌کد اختصاصی ساخته می‌شود:</p>
            <code>[f360_league_premier_league]</code> <code>[f360_premier_league]</code>
            <p class="description">در تب «لیگ‌ها»، روبروی هر لیگ شورت‌کد اختصاصی همان لیگ نمایش داده شده است.</p>
        </div>
    <?php }

    private function render_tools_tab(): void {
        $test = '';
        if (!empty($_GET['test_url'])) $test = $this->test_url(esc_url_raw(wp_unslash($_GET['test_url'])));
        ?>
        <div class="f360ls-admin-grid">
            <div class="f360ls-card">
                <h2>ابزارها</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('f360ls_scan_files'); ?><input type="hidden" name="action" value="f360ls_scan_files"><?php submit_button('اسکن فایل‌های HTML و JSON داخل افزونه', 'secondary'); ?></form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('f360ls_clear_cache'); ?><input type="hidden" name="action" value="f360ls_clear_cache"><?php submit_button('پاک کردن کش همه لیگ‌ها', 'secondary'); ?></form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('f360ls_import_quick_leagues'); ?><input type="hidden" name="action" value="f360ls_import_quick_leagues"><?php submit_button('افزودن سریع رقابت‌های آماده', 'primary'); ?></form>
            </div>
            <div class="f360ls-card">
                <h2>تست اتصال و استخراج</h2>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                    <input type="hidden" name="page" value="f360ls"><input type="hidden" name="tab" value="tools">
                    <input type="url" name="test_url" class="large-text" placeholder="https://footballi.net/live-scores" value="<?php echo esc_attr(wp_unslash($_GET['test_url'] ?? '')); ?>">
                    <?php submit_button('تست URL', 'secondary'); ?>
                </form>
                <?php if ($test): ?><div class="f360ls-test-result"><?php echo wp_kses_post($test); ?></div><?php endif; ?>
            </div>
        </div>
    <?php }

    private function render_directory_tab(): void {
        $directory = get_option(F360LS_OPTION_DIRECTORY, []);
        $directory = is_array($directory) ? $directory : [];
        ?>
        <div class="f360ls-card">
            <h2>اسکرپر حرفه‌ای رقابت‌ها</h2>
            <p>این ابزار از صفحات مرجع لینک‌های رقابت‌ها را پیدا می‌کند، تکراری‌ها را حذف می‌کند و برای Import آماده می‌کند. بعد از اسکن می‌توانید همه یا موارد انتخابی را به افزونه اضافه کنید.</p>
            <div class="f360ls-tool-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:8px">
                    <?php wp_nonce_field('f360ls_scan_footballi_directory'); ?>
                    <input type="hidden" name="action" value="f360ls_scan_footballi_directory">
                    <?php submit_button('اسکن منبع مرجع و بروزرسانی دایرکتوری', 'primary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:8px">
                    <?php wp_nonce_field('f360ls_clear_footballi_directory'); ?>
                    <input type="hidden" name="action" value="f360ls_clear_footballi_directory">
                    <?php submit_button('پاک کردن دایرکتوری', 'secondary', 'submit', false); ?>
                </form>
            </div>
            <p><strong><?php echo intval(count($directory)); ?></strong> رقابت در دایرکتوری ذخیره شده است.</p>
        </div>

        <div class="f360ls-card">
            <h2>رقابت‌های کشف‌شده</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('f360ls_import_footballi_directory'); ?>
                <input type="hidden" name="action" value="f360ls_import_footballi_directory">
                <p>
                    <label><input type="checkbox" name="import_all" value="1"> افزودن همه رقابت‌های کشف‌شده</label>
                    <?php submit_button('Import رقابت‌های انتخابی/همه', 'primary', 'submit', false); ?>
                </p>
                <table class="widefat striped f360ls-directory-table">
                    <thead><tr><th>انتخاب</th><th>ID</th><th>عنوان</th><th>لینک</th><th>شورت‌کد بعد از Import</th></tr></thead>
                    <tbody>
                    <?php if (!$directory): ?><tr><td colspan="5">هنوز دایرکتوری اسکن نشده است.</td></tr><?php endif; ?>
                    <?php foreach ($directory as $item): $id = sanitize_title($item['id'] ?? ''); if (!$id) continue; ?>
                        <tr>
                            <td><input type="checkbox" name="competition_ids[]" value="<?php echo esc_attr($id); ?>"></td>
                            <td><code><?php echo esc_html($id); ?></code></td>
                            <td><?php echo esc_html($item['title'] ?? $id); ?></td>
                            <td><a href="<?php echo esc_url($item['url'] ?? ''); ?>" target="_blank" rel="noopener"><?php echo esc_html($item['url'] ?? ''); ?></a></td>
                            <td><code>[f360_competition id=&quot;<?php echo esc_attr($id); ?>&quot;]</code><br><code>[f360_competition_<?php echo esc_html(sanitize_key(str_replace('-', '_', $id))); ?>]</code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php
    }

    private function render_health_tab(): void {
        $upload = wp_upload_dir();
        $checks = [
            ['PHP نسخه', PHP_VERSION, version_compare(PHP_VERSION, '7.4', '>=')],
            ['WordPress نسخه', get_bloginfo('version'), version_compare(get_bloginfo('version'), '5.6', '>=')],
            ['DOMDocument', class_exists('DOMDocument') ? 'فعال' : 'غیرفعال', class_exists('DOMDocument')],
            ['JSON', function_exists('json_decode') ? 'فعال' : 'غیرفعال', function_exists('json_decode')],
            ['wp_remote_get', function_exists('wp_remote_get') ? 'فعال' : 'غیرفعال', function_exists('wp_remote_get')],
            ['پوشه uploads', is_writable($upload['basedir'] ?? '') ? 'قابل نوشتن' : 'غیرقابل نوشتن', is_writable($upload['basedir'] ?? '')],
            ['پوشه افزونه', is_writable(F360LS_PLUGIN_DIR . 'data/') ? 'قابل نوشتن' : 'غیرقابل نوشتن', is_writable(F360LS_PLUGIN_DIR . 'data/')],
            ['کران آپدیت ساعتی', wp_next_scheduled(F360LS_CRON_HOOK) ? 'زمان‌بندی شده: ' . date_i18n('Y-m-d H:i:s', wp_next_scheduled(F360LS_CRON_HOOK)) : 'زمان‌بندی نشده', (bool) wp_next_scheduled(F360LS_CRON_HOOK)],
        ];
        ?>
        <div class="f360ls-card">
            <h2>سلامت سیستم</h2>
            <p>این بخش وضعیت پیش‌نیازها و محیط اجرا را بررسی می‌کند.</p>
            <table class="widefat striped"><thead><tr><th>مورد</th><th>وضعیت</th><th>نتیجه</th></tr></thead><tbody>
            <?php foreach ($checks as $check): ?><tr><td><?php echo esc_html($check[0]); ?></td><td><?php echo esc_html((string) $check[1]); ?></td><td><?php echo $check[2] ? '<span class="f360ls-pill is-ok">OK</span>' : '<span class="f360ls-pill is-bad">Needs attention</span>'; ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <div class="f360ls-card">
            <h2>پیشنهادهای سلامت</h2>
            <ul class="f360ls-admin-list">
                <li>اگر DOMDocument غیرفعال است، parser HTML دقت کمتری خواهد داشت.</li>
                <li>اگر uploads قابل نوشتن نیست، آپلود HTML/JSON و کش فایل‌ها مشکل پیدا می‌کند.</li>
                <li>دامنه‌های مجاز را در تب ظاهر و رنگ‌ها بررسی کنید تا fetch فقط از منابع امن انجام شود.</li>
            </ul>
        </div>
        <?php
    }

    private function render_logs_tab(): void {
        $logs = class_exists('F360LS_Logger') ? F360LS_Logger::get_logs() : [];
        ?>
        <div class="f360ls-card">
            <h2>گزارش خطاها و رویدادها</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px">
                <?php wp_nonce_field('f360ls_clear_logs'); ?><input type="hidden" name="action" value="f360ls_clear_logs">
                <?php submit_button('پاک کردن گزارش‌ها', 'secondary', 'submit', false); ?>
            </form>
            <table class="widefat striped"><thead><tr><th>زمان</th><th>سطح</th><th>پیام</th><th>جزئیات</th></tr></thead><tbody>
            <?php if (!$logs): ?><tr><td colspan="4">هنوز گزارشی ثبت نشده است.</td></tr><?php endif; ?>
            <?php foreach ($logs as $log): ?>
                <tr><td><?php echo esc_html($log['time'] ?? ''); ?></td><td><span class="f360ls-pill is-<?php echo esc_attr($log['level'] ?? 'info'); ?>"><?php echo esc_html($log['level'] ?? 'info'); ?></span></td><td><?php echo esc_html($log['message'] ?? ''); ?></td><td><code><?php echo esc_html(wp_json_encode($log['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></code></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    private function shortcode_docs(): array {
        return [
            ['[f360_all_leagues]', 'نمایش همه لیگ‌های فعال یکجا', 'ids, theme, show_filters, title, refresh'],
            ['[f360_league_tabs]', 'نمایش لیگ‌ها به‌صورت تب‌دار', 'ids, default, theme, show_filters, refresh'],
            ['[f360_league id="premier-league"]', 'نمایش یک لیگ/رقابت مشخص با چیدمان حرفه‌ای', 'id, theme, show_filters, refresh'],
            ['[f360_competition id="champions-league"]', 'نام جایگزین برای نمایش جدول هر رقابت با شورت‌کد خودش', 'id, theme, show_filters, refresh'],
            ['[f360_competition_premier_league]', 'شورت‌کد اختصاصی خودکار رقابت بر اساس شناسه', 'بدون پارامتر'], 
            ['[f360_today_matches]', 'نمایش بازی‌های امروز/لیست بازی‌های منبع زنده', 'ids, limit, theme, refresh'],
            ['[f360_live_matches]', 'نمایش فقط بازی‌های زنده', 'ids, limit, theme'],
            ['[f360_featured_matches]', 'اسلایدر/لیست بازی‌های مهم', 'ids, limit, theme'],
            ['[f360_mini_table id="premier-league" limit="5"]', 'جدول خلاصه برای سایدبار یا صفحه اصلی', 'id, limit, theme, title'],
            ['[f360_team_matches team="پرسپولیس"]', 'نمایش بازی‌های یک تیم', 'team, ids/id, limit, theme'],
            ['[f360_favorite_teams]', 'انتخاب تیم محبوب و برجسته‌سازی بازی‌ها', 'ids, limit, theme'],
            ['[f360_match_preview id="league" home="تیم ۱" away="تیم ۲"]', 'پیش‌نمایش و مقایسه دو تیم', 'id, home, away, limit, theme'],
            ['[f360_team_form id="league" team="تیم"]', 'نمایش فرم چند بازی اخیر تیم', 'id, team, limit, theme'],
            ['[f360_top_scorers id="league"]', 'جدول گلزنان، اگر منبع داده داشته باشد', 'id, limit, theme'],
            ['[f360_league_news id="league"]', 'اخبار لیگ از منبع، اگر استخراج شود', 'id, limit, theme'],
            ['[f360_team_news team="پرسپولیس"]', 'اخبار مرتبط با تیم', 'team, ids/id, limit, theme'],
        ];
    }

    private function quick_leagues(): array {
        return [
            ['id'=>'premier-league','title'=>'لیگ برتر انگلیس','url'=>'https://footballi.net/competition/9'],
            ['id'=>'laliga','title'=>'لالیگا اسپانیا','url'=>'https://footballi.net/competition/21'],
            ['id'=>'serie-a','title'=>'سری آ ایتالیا','url'=>'https://footballi.net/competition/17'],
            ['id'=>'bundesliga','title'=>'بوندس لیگای آلمان','url'=>'https://footballi.net/competition/12'],
            ['id'=>'persian-gulf-pro-league','title'=>'لیگ برتر ایران','url'=>'https://footballi.net/competition/14'],
            ['id'=>'ligue-1','title'=>'لیگ 1 فرانسه','url'=>'https://footballi.net/competition/11'],
            ['id'=>'champions-league','title'=>'لیگ قهرمانان اروپا','url'=>'https://footballi.net/competition/3'],
            ['id'=>'europa-league','title'=>'لیگ اروپا','url'=>'https://footballi.net/competition/4'],
            ['id'=>'conference-league','title'=>'لیگ کنفرانس اروپا','url'=>''],
            ['id'=>'elite-asia','title'=>'لیگ نخبگان آسیا','url'=>'https://footballi.net/competition/25'],
            ['id'=>'acl-two','title'=>'لیگ قهرمانان 2 آسیا','url'=>'https://footballi.net/competition/147'],
            ['id'=>'championship','title'=>'چمپیونشیپ انگلیس','url'=>''],
            ['id'=>'bundesliga-2','title'=>'بوندس لیگای 2 آلمان','url'=>''],
            ['id'=>'scottish-premiership','title'=>'پریمیرشیپ اسکاتلند','url'=>''],
            ['id'=>'primeira-liga','title'=>'لیگ برتر پرتغال','url'=>''],
            ['id'=>'eredivisie','title'=>'اردیویسه هلند','url'=>''],
            ['id'=>'fa-cup','title'=>'جام حذفی انگلیس','url'=>''],
            ['id'=>'efl-cup','title'=>'جام اتحادیه انگلیس','url'=>'https://footballi.net/competition/60'],
            ['id'=>'dfb-pokal','title'=>'جام حذفی آلمان','url'=>''],
            ['id'=>'copa-del-rey','title'=>'جام حذفی اسپانیا','url'=>''],
            ['id'=>'coppa-italia','title'=>'جام حذفی ایتالیا','url'=>''],
            ['id'=>'coupe-de-france','title'=>'جام حذفی فرانسه','url'=>''],
            ['id'=>'saudi-pro-league','title'=>'لیگ برتر عربستان','url'=>'https://footballi.net/competition/104'],
            ['id'=>'uae-pro-league','title'=>'لیگ برتر امارات','url'=>''],
            ['id'=>'qatar-stars-league','title'=>'لیگ ستارگان قطر','url'=>''],
            ['id'=>'world-cup-qualifying-asia','title'=>'انتخابی جام جهانی آسیا','url'=>''],
            ['id'=>'world-cup-qualifying-europe','title'=>'انتخابی جام جهانی اروپا','url'=>''],
            ['id'=>'world-cup-qualifying-africa','title'=>'انتخابی جام جهانی آفریقا','url'=>''],
            ['id'=>'world-cup-qualifying-south-america','title'=>'انتخابی جام جهانی آمریکای جنوبی','url'=>''],
            ['id'=>'world-cup-qualifying-north-america','title'=>'انتخابی جام جهانی آمریکای شمالی','url'=>''],
            ['id'=>'uefa-nations-league','title'=>'لیگ ملت های اروپا','url'=>'https://footballi.net/competition/83'],
            ['id'=>'afc-asian-cup','title'=>'جام ملت های آسیا','url'=>''],
            ['id'=>'uefa-euro','title'=>'جام ملت های اروپا','url'=>''],
            ['id'=>'africa-cup-of-nations','title'=>'جام ملت های آفریقا','url'=>''],
            ['id'=>'copa-america','title'=>'کوپا آمریکا','url'=>''],
        ];
    }
    private function default_settings(): array {
        return ['default_theme'=>'light','accent_color'=>'#16a34a','accent2_color'=>'#22c55e','background_color'=>'#f4f7fb','card_color'=>'#ffffff','text_color'=>'#0f172a','radius'=>'32','density'=>'comfortable','show_source'=>'0','show_hero'=>'1','auto_refresh'=>'0','hourly_cron'=>'1','refresh_interval'=>'60','live_cache_ttl'=>'45','default_cache_ttl'=>'21600','allowed_domains'=>"footballi.net\nfootball360.ir\ncdn.oddrun.ir",'custom_font_url'=>'','animations'=>'1'];
    }

    private function get_settings(): array {
        $settings = get_option(F360LS_OPTION_SETTINGS, []);
        return wp_parse_args(is_array($settings) ? $settings : [], $this->default_settings());
    }

    private function sanitize_hex(string $value, string $fallback): string {
        $value = sanitize_hex_color($value);
        return $value ?: $fallback;
    }

    private function sanitize_domains(string $value): string {
        $domains = array_filter(array_map('trim', preg_split('/\R+|,/', $value)));
        $out = [];
        foreach ($domains as $domain) {
            $domain = strtolower(preg_replace('/[^a-z0-9.\-]/', '', $domain));
            $domain = ltrim($domain, '.-');
            if ($domain && strpos($domain, '.') !== false) $out[] = $domain;
        }
        $out = array_values(array_unique($out));
        return implode("\n", $out ?: ['footballi.net','football360.ir','cdn.oddrun.ir']);
    }

    private function handle_font_upload(array $file): string {
        $name = sanitize_file_name($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['woff2','woff','ttf'], true)) return '';
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'f360-league-score-pro/fonts/';
        $url = trailingslashit($upload['baseurl']) . 'f360-league-score-pro/fonts/';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        $target = $dir . 'custom-font.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $target)) return '';
        return $url . basename($target);
    }

    public function save_settings(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('f360ls_save_settings')) wp_die('Access denied');
        $d = $this->default_settings();
        $settings = [
            'default_theme' => in_array(($_POST['default_theme'] ?? 'light'), ['light','dark'], true) ? sanitize_text_field(wp_unslash($_POST['default_theme'])) : $d['default_theme'],
            'accent_color' => $this->sanitize_hex((string) wp_unslash($_POST['accent_color'] ?? ''), $d['accent_color']),
            'accent2_color' => $this->sanitize_hex((string) wp_unslash($_POST['accent2_color'] ?? ''), $d['accent2_color']),
            'background_color' => $this->sanitize_hex((string) wp_unslash($_POST['background_color'] ?? ''), $d['background_color']),
            'card_color' => $this->sanitize_hex((string) wp_unslash($_POST['card_color'] ?? ''), $d['card_color']),
            'text_color' => $this->sanitize_hex((string) wp_unslash($_POST['text_color'] ?? ''), $d['text_color']),
            'radius' => (string) max(12, min(44, absint($_POST['radius'] ?? $d['radius']))),
            'density' => in_array(($_POST['density'] ?? 'comfortable'), ['comfortable','compact'], true) ? sanitize_text_field(wp_unslash($_POST['density'])) : $d['density'],
            'show_source' => !empty($_POST['show_source']) ? '1' : '0',
            'show_hero' => !empty($_POST['show_hero']) ? '1' : '0',
            'auto_refresh' => !empty($_POST['auto_refresh']) ? '1' : '0',
            'hourly_cron' => !empty($_POST['hourly_cron']) ? '1' : '0',
            'refresh_interval' => (string) max(15, min(600, absint($_POST['refresh_interval'] ?? $d['refresh_interval']))),
            'live_cache_ttl' => (string) max(15, min(900, absint($_POST['live_cache_ttl'] ?? $d['live_cache_ttl']))),
            'default_cache_ttl' => (string) max(300, min(86400, absint($_POST['default_cache_ttl'] ?? $d['default_cache_ttl']))),
            'allowed_domains' => $this->sanitize_domains((string) wp_unslash($_POST['allowed_domains'] ?? $d['allowed_domains'])),
            'animations' => !empty($_POST['animations']) ? '1' : '0',
            'custom_font_url' => sanitize_text_field($d['custom_font_url'] ?? ''),
        ];
        $old_settings = $this->get_settings();
        $settings['custom_font_url'] = $old_settings['custom_font_url'] ?? '';
        if (!empty($_POST['remove_custom_font'])) {
            $settings['custom_font_url'] = '';
        }
        if (!empty($_FILES['custom_font_file']['tmp_name']) && is_uploaded_file($_FILES['custom_font_file']['tmp_name'])) {
            $font_url = $this->handle_font_upload($_FILES['custom_font_file']);
            if ($font_url) $settings['custom_font_url'] = $font_url;
        }
        update_option(F360LS_OPTION_SETTINGS, $settings, false);
        if (defined('F360LS_CRON_HOOK')) {
            if ($settings['hourly_cron'] === '1') {
                if (!wp_next_scheduled(F360LS_CRON_HOOK)) wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', F360LS_CRON_HOOK);
            } else {
                wp_clear_scheduled_hook(F360LS_CRON_HOOK);
            }
        }
        F360LS_Repository::instance()->clear_all_caches();
        $this->redirect('appearance', 'تنظیمات ذخیره شد.');
    }

    public function save_league(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('f360ls_save_league')) wp_die('Access denied');
        $id = sanitize_title($_POST['league_id'] ?? '');
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        if (!$id || !$title) wp_die('شناسه و عنوان الزامی است.');
        $repo = F360LS_Repository::instance();
        $existing = $repo->get_league($id);
        $file_path = $existing['file'] ?? '';
        $json_file_path = $existing['json_file'] ?? '';
        if (!empty($_FILES['html_file']['tmp_name']) && is_uploaded_file($_FILES['html_file']['tmp_name'])) {
            $ext = strtolower(pathinfo(sanitize_file_name($_FILES['html_file']['name']), PATHINFO_EXTENSION));
            if (!in_array($ext, ['html','htm'], true)) wp_die('فقط فایل HTML مجاز است.');
            $repo->ensure_upload_dir(); $file_path = trailingslashit($repo->upload_dir()) . $id . '.html'; move_uploaded_file($_FILES['html_file']['tmp_name'], $file_path);
        }
        if (!empty($_FILES['json_file']['tmp_name']) && is_uploaded_file($_FILES['json_file']['tmp_name'])) {
            $ext = strtolower(pathinfo(sanitize_file_name($_FILES['json_file']['name']), PATHINFO_EXTENSION));
            if ($ext !== 'json') wp_die('فقط فایل JSON مجاز است.');
            $repo->ensure_upload_dir(); $json_file_path = trailingslashit($repo->upload_dir()) . $id . '.json'; move_uploaded_file($_FILES['json_file']['tmp_name'], $json_file_path);
        }
        $repo->upsert_league(['id'=>$id,'title'=>$title,'subtitle'=>sanitize_text_field(wp_unslash($_POST['subtitle'] ?? '')),'source_url'=>esc_url_raw(wp_unslash($_POST['source_url'] ?? '')),'games_url'=>esc_url_raw(wp_unslash($_POST['games_url'] ?? '')),'table_url'=>esc_url_raw(wp_unslash($_POST['table_url'] ?? '')),'file'=>$file_path,'json_file'=>$json_file_path,'is_plugin_file'=>false,'enabled'=>!empty($_POST['enabled'])]);
        $this->redirect('leagues', 'لیگ ذخیره شد و کش پاک شد.');
    }

    public function import_quick_leagues(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('f360ls_import_quick_leagues')) wp_die('Access denied');
        $repo = F360LS_Repository::instance();
        $quick = $this->quick_leagues();
        foreach ($quick as $league) {
            $existing = $repo->get_league($league['id']) ?: [];
            $source_url = $league['url'] ?: ($existing['source_url'] ?? '');
            $games_url = $league['url'] ?: ($existing['games_url'] ?? '');
            // Never use the global live-scores feed for a league: it mixes competitions.
            if ($source_url === 'https://footballi.net/live-scores') $source_url = '';
            if ($games_url === 'https://footballi.net/live-scores') $games_url = '';
            $repo->upsert_league(['id'=>$league['id'],'title'=>$league['title'],'subtitle'=>'','source_url'=>$source_url,'games_url'=>$games_url,'table_url'=>$source_url ? $source_url . '/standing' : '','enabled'=>true,'is_plugin_file'=>false]);
        }
        $ordered = [];
        foreach ($quick as $league) if ($item = $repo->get_league($league['id'])) $ordered[] = $item;
        foreach ($repo->get_leagues() as $item) if (!in_array($item['id'] ?? '', array_column($quick, 'id'), true)) $ordered[] = $item;
        $repo->save_leagues($ordered);
        $repo->clear_all_caches();
        $this->redirect('leagues', 'لیگ‌های معروف اضافه/بروزرسانی شدند.');
    }

    public function scan_footballi_directory(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('f360ls_scan_footballi_directory')) wp_die('Access denied');
        $items = $this->discover_footballi_competitions();
        update_option(F360LS_OPTION_DIRECTORY, $items, false);
        if (class_exists('F360LS_Logger')) F360LS_Logger::log('info', 'دایرکتوری رقابت‌ها اسکن شد.', ['count' => count($items)]);
        $this->redirect('directory', count($items) . ' رقابت پیدا و ذخیره شد.');
    }

    public function import_footballi_directory(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('f360ls_import_footballi_directory')) wp_die('Access denied');
        $directory = get_option(F360LS_OPTION_DIRECTORY, []);
        $directory = is_array($directory) ? $directory : [];
        $selected = array_map('sanitize_title', (array) ($_POST['competition_ids'] ?? []));
        $import_all = !empty($_POST['import_all']);
        $repo = F360LS_Repository::instance();
        $count = 0;
        foreach ($directory as $item) {
            $id = sanitize_title($item['id'] ?? '');
            if (!$id || (!$import_all && !in_array($id, $selected, true))) continue;
            $url = esc_url_raw($item['url'] ?? '');
            if (!$url) continue;
            $repo->upsert_league([
                'id' => $id,
                'title' => sanitize_text_field($item['title'] ?? $id),
                'subtitle' => '',
                'source_url' => $url,
                'games_url' => $url,
                'table_url' => rtrim($url, '/') . '/standing',
                'enabled' => true,
                'is_plugin_file' => false,
            ]);
            $count++;
        }
        $repo->clear_all_caches();
        $this->redirect('leagues', $count . ' رقابت از دایرکتوری اضافه/بروزرسانی شد.');
    }

    public function clear_footballi_directory(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('f360ls_clear_footballi_directory')) wp_die('Access denied');
        delete_option(F360LS_OPTION_DIRECTORY);
        $this->redirect('directory', 'دایرکتوری رقابت‌ها پاک شد.');
    }

    public function delete_league(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('f360ls_delete_league')) wp_die('Access denied');
        F360LS_Repository::instance()->delete_league(sanitize_title($_POST['league_id'] ?? ''));
        $this->redirect('leagues', 'لیگ حذف شد.');
    }

    public function clear_league_cache(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('f360ls_clear_league_cache')) wp_die('Access denied');
        F360LS_Repository::instance()->clear_cache(sanitize_title($_POST['league_id'] ?? ''));
        $this->redirect('leagues', 'کش لیگ پاک شد.');
    }

    public function clear_cache(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('f360ls_clear_cache')) wp_die('Access denied');
        F360LS_Repository::instance()->clear_all_caches();
        $this->redirect('tools', 'کش همه لیگ‌ها پاک شد.');
    }

    public function scan_files(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('f360ls_scan_files')) wp_die('Access denied');
        $repo = F360LS_Repository::instance(); $repo->scan_plugin_html_files(); $repo->scan_plugin_json_files(); $repo->clear_all_caches();
        $this->redirect('tools', 'فایل‌های HTML و JSON پوشه افزونه اسکن شدند.');
    }

    public function clear_logs(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('f360ls_clear_logs')) wp_die('Access denied');
        if (class_exists('F360LS_Logger')) F360LS_Logger::clear();
        $this->redirect('logs', 'گزارش‌ها پاک شدند.');
    }

    private function discover_footballi_competitions(): array {
        $found = [];
        foreach ($this->quick_leagues() as $item) {
            $this->add_directory_item($found, $item['url'], $item['title'] ?? '');
        }

        $queue = [
            'https://footballi.net/',
            'https://footballi.net/competition',
            'https://footballi.net/live-scores',
            'https://footballi.net/news',
            'https://footballi.net/live',
        ];
        foreach ($this->quick_leagues() as $item) $queue[] = $item['url'];

        $seen_pages = [];
        $max_pages = 80;
        while ($queue && count($seen_pages) < $max_pages) {
            $url = array_shift($queue);
            $url = esc_url_raw($url);
            if (!$url || isset($seen_pages[$url]) || !$this->domain_allowed($url)) continue;
            $seen_pages[$url] = true;
            $html = $this->admin_fetch_url($url);
            if (!$html) continue;

            if (preg_match_all("~href=[\"']([^\"']*(?:/competition/|/جام-جهانی-2026)[^\"']*)[\"'][^>]*>(.*?)</a>~isu", $html, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $href = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
                    $title = $this->clean_link_text($match[2]);
                    $full = $this->absolute_footballi_url($href);
                    if (!$full) continue;
                    $this->add_directory_item($found, $full, $title);
                    if (count($seen_pages) + count($queue) < $max_pages && strpos($full, '/competition/') !== false) $queue[] = $full;
                }
            }

            if (preg_match_all("~https?://footballi\\.net/(?:competition/\\d+/[^\\s\"'<]+|%D8%AC%D8%A7%D9%85-[^\\s\"'<]+)~iu", $html, $m2)) {
                foreach ($m2[0] as $full) $this->add_directory_item($found, html_entity_decode($full, ENT_QUOTES, 'UTF-8'), '');
            }
        }

        uasort($found, fn($a, $b) => strnatcasecmp($a['title'], $b['title']));
        return array_values($found);
    }

    private function add_directory_item(array &$found, string $url, string $title = ''): void {
        $url = preg_replace('/[?#].*$/', '', $this->absolute_footballi_url($url));
        if (!$url) return;
        $id = '';
        if (preg_match('~/competition/(\d+)/([^/]+)~u', $url, $m)) {
            $id = sanitize_title(rawurldecode($m[2]));
            if (!$title) $title = rawurldecode($m[2]);
        } elseif (strpos($url, 'جام-جهانی-2026') !== false || strpos($url, '%D8%AC%D8%A7%D9%85') !== false) {
            $id = 'world-cup-2026';
            if (!$title) $title = 'جام جهانی ۲۰۲۶';
        }
        if (!$id) return;
        $title = $this->clean_link_text($title ?: $id);
        $found[$id] = ['id' => $id, 'title' => $title, 'url' => $url];
    }

    private function admin_fetch_url(string $url): string {
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'redirection' => 5,
            'sslverify' => false,
            'headers' => ['User-Agent' => 'Mozilla/5.0 F360LS-Directory-Crawler'],
        ]);
        if (is_wp_error($response)) {
            if (class_exists('F360LS_Logger')) F360LS_Logger::log('warning', 'خطا در اسکن دایرکتوری رقابت‌ها.', ['url' => $url, 'error' => $response->get_error_message()]);
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) return '';
        return (string) wp_remote_retrieve_body($response);
    }

    private function absolute_footballi_url(string $href): string {
        $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
        if (!$href) return '';
        if (strpos($href, '//') === 0) $href = 'https:' . $href;
        if (strpos($href, '/') === 0) $href = 'https://footballi.net' . $href;
        if (strpos($href, 'http://footballi.net') === 0) $href = preg_replace('/^http:/', 'https:', $href);
        if (strpos($href, 'https://footballi.net/') !== 0) return '';
        return $href;
    }

    private function clean_link_text(string $text): string {
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        return $text ?: 'رقابت';
    }

    private function test_url(string $url): string {
        if (!$url || !wp_http_validate_url($url)) return '<p class="f360ls-error">URL معتبر نیست.</p>';
        if (!$this->domain_allowed($url)) return '<p class="f360ls-error">دامنه این URL در لیست دامنه‌های مجاز نیست.</p>';
        $response = wp_remote_get($url, ['timeout'=>20,'redirection'=>5,'sslverify'=>false,'headers'=>['User-Agent'=>'Mozilla/5.0 F360LS-Test']]);
        if (is_wp_error($response)) return '<p class="f360ls-error">خطا: ' . esc_html($response->get_error_message()) . '</p>';
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $parser = (class_exists('F360LS_Footballi_Parser') && F360LS_Footballi_Parser::looks_like($body, ['url'=>$url])) ? new F360LS_Footballi_Parser($body, ['url'=>$url]) : new F360LS_Parser($body);
        $data = $parser->parse(['title'=>'تست']);
        $html = '<ul><li>HTTP: ' . esc_html((string) $code) . '</li><li>حجم دریافت: ' . esc_html(size_format(strlen($body))) . '</li><li>بازی‌ها: ' . intval(count($data['matches'] ?? [])) . '</li><li>تیم‌های جدول: ' . intval(count($data['standings'] ?? [])) . '</li><li>خبرها: ' . intval(count($data['news'] ?? [])) . '</li></ul>';
        $matches = array_slice($data['matches'] ?? [], 0, 5);
        $standings = array_slice($data['standings'] ?? [], 0, 5);
        if ($standings) {
            $html .= '<h3>پیش‌نمایش جدول</h3><table class="widefat striped"><thead><tr><th>رتبه</th><th>تیم</th><th>امتیاز</th></tr></thead><tbody>';
            foreach ($standings as $row) $html .= '<tr><td>' . esc_html($row['rank'] ?? '') . '</td><td>' . esc_html($row['team'] ?? '') . '</td><td>' . esc_html($row['points'] ?? '') . '</td></tr>';
            $html .= '</tbody></table>';
        }
        if ($matches) {
            $html .= '<h3>پیش‌نمایش بازی‌ها</h3><table class="widefat striped"><thead><tr><th>میزبان</th><th>نتیجه</th><th>مهمان</th><th>وضعیت</th></tr></thead><tbody>';
            foreach ($matches as $m) $html .= '<tr><td>' . esc_html($m['home'] ?? '') . '</td><td>' . esc_html($m['score'] ?? '') . '</td><td>' . esc_html($m['away'] ?? '') . '</td><td>' . esc_html($m['status'] ?? '') . '</td></tr>';
            $html .= '</tbody></table>';
        }
        return $html;
    }

    private function domain_allowed(string $url): bool {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (!$host) return false;
        $domains = array_filter(array_map('trim', preg_split('/\R+|,/', (string) $this->get_settings()['allowed_domains'])));
        foreach ($domains as $domain) {
            $domain = strtolower(ltrim($domain, '.'));
            if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) return true;
        }
        return false;
    }

    private function redirect(string $tab, string $message): void {
        wp_safe_redirect(add_query_arg(['page'=>'f360ls','tab'=>$tab,'f360ls_msg'=>$message], admin_url('admin.php')));
        exit;
    }
    public function reorder_leagues(): void {
    if (!current_user_can('manage_options') || !check_admin_referer('f360ls_reorder_leagues')) {
        wp_die('Access denied');
    }

    $order = array_filter(array_map('sanitize_title', explode(',', (string) wp_unslash($_POST['league_order'] ?? ''))));

    if (!$order) {
        $this->redirect('leagues', 'ترتیبی برای ذخیره ارسال نشد.');
    }

    $repo = F360LS_Repository::instance();
    $leagues = $repo->get_leagues();

    $by_id = [];
    foreach ($leagues as $league) {
        if (!empty($league['id'])) {
            $by_id[$league['id']] = $league;
        }
    }

    $sorted = [];
    foreach ($order as $id) {
        if (isset($by_id[$id])) {
            $sorted[] = $by_id[$id];
            unset($by_id[$id]);
        }
    }

    foreach ($by_id as $league) {
        $sorted[] = $league;
    }

    $repo->save_leagues($sorted);
    $repo->clear_all_caches();

    $this->redirect('leagues', 'ترتیب نمایش لیگ‌ها ذخیره شد.');
}
}
