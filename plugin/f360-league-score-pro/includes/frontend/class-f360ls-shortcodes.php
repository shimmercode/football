<?php
if (!defined('ABSPATH')) { exit; }

class F360LS_Shortcodes {
    public function __construct() {
        add_shortcode('f360_league_tabs', [$this, 'league_tabs']);
        add_shortcode('f360_league', [$this, 'single_league']);
        add_shortcode('f360_competition', [$this, 'single_league']);
        add_shortcode('f360_leagues', [$this, 'all_leagues']);
        add_shortcode('f360_all_leagues', [$this, 'all_leagues']);
        add_shortcode('f360_today_matches', [$this, 'today_matches']);
        add_shortcode('f360_live_matches', [$this, 'live_matches']);
        add_shortcode('f360_featured_matches', [$this, 'featured_matches']);
        add_shortcode('f360_mini_table', [$this, 'mini_table']);
        add_shortcode('f360_team_matches', [$this, 'team_matches']);
        add_shortcode('f360_favorite_teams', [$this, 'favorite_teams']);
        add_shortcode('f360_match_preview', [$this, 'match_preview']);
        add_shortcode('f360_team_form', [$this, 'team_form']);
        add_shortcode('f360_top_scorers', [$this, 'top_scorers']);
        add_shortcode('f360_league_news', [$this, 'league_news']);
        add_shortcode('f360_team_news', [$this, 'team_news']);
        add_action('wp_ajax_f360ls_refresh', [$this, 'ajax_refresh']);
        add_action('wp_ajax_nopriv_f360ls_refresh', [$this, 'ajax_refresh']);
        $this->register_dynamic_league_shortcodes();
    }

    private function register_dynamic_league_shortcodes(): void {
        $repo = F360LS_Repository::instance();
        foreach ($repo->get_leagues() as $league) {
            $id = sanitize_title($league['id'] ?? '');
            if (!$id) continue;
            $alias = $this->shortcode_alias($id);
            if (!$alias) continue;
            add_shortcode('f360_league_' . $alias, function($atts = []) use ($id) {
                $atts = is_array($atts) ? $atts : [];
                $atts['id'] = $id;
                return $this->single_league($atts);
            });
            add_shortcode('f360_' . $alias, function($atts = []) use ($id) {
                $atts = is_array($atts) ? $atts : [];
                $atts['id'] = $id;
                return $this->single_league($atts);
            });
            add_shortcode('f360_competition_' . $alias, function($atts = []) use ($id) {
                $atts = is_array($atts) ? $atts : [];
                $atts['id'] = $id;
                return $this->single_league($atts);
            });
        }
    }

    private function enqueue_front(): void {
        wp_enqueue_style('f360ls-front');
        $settings = $this->get_settings();
        if (!empty($settings['custom_font_url'])) {
            wp_add_inline_style('f360ls-front', "@font-face{font-family:'F360LSCustomFont';src:url('" . esc_url($settings['custom_font_url']) . "');font-display:swap}.f360ls-wrap,.f360ls-wrap *{font-family:'F360LSCustomFont',Tahoma,Arial,sans-serif!important}.f360ls-wrap{--f360ls-font:'F360LSCustomFont',Tahoma,Arial,sans-serif}");
        }
        wp_enqueue_script('f360ls-front');
        $settings = $this->get_settings();
        wp_localize_script('f360ls-front', 'F360LS', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('f360ls_public'),
            'autoRefresh' => $settings['auto_refresh'] ?? '0',
            'refreshInterval' => max(15, absint($settings['refresh_interval'] ?? 60)),
            'strings' => [
                'chooseFavorites' => 'تیم‌های محبوبت را انتخاب کن',
                'noFavorites' => 'هنوز تیم محبوبی انتخاب نشده است.',
            ],
        ]);
    }
public function league_tabs($atts): string {
    $settings = $this->get_settings();

    $atts = shortcode_atts([
        'ids' => '',
        'default' => '',
        'theme' => $settings['default_theme'],
        'show_filters' => 'yes',

        // برای جلوگیری از کندی صفحه، تب‌دار پیش‌فرض refresh ندارد.
        'refresh' => '0',
    ], $atts, 'f360_league_tabs');

    $this->enqueue_front();

    $repo = F360LS_Repository::instance();
    $leagues = $this->filter_leagues($repo->get_enabled_leagues(), (string) $atts['ids']);

    if (empty($leagues)) {
        return '<div class="f360ls-empty">هیچ لیگ فعالی ثبت نشده است.</div>';
    }

    $uid = 'f360ls-' . wp_generate_uuid4();
    $default = sanitize_title($atts['default']);
    $active = 0;

    if ($default) {
        foreach ($leagues as $i => $league) {
            if (($league['id'] ?? '') === $default) {
                $active = $i;
                break;
            }
        }
    }

    ob_start();
    ?>
    <div
        id="<?php echo esc_attr($uid); ?>"
        class="<?php echo esc_attr($this->wrap_classes('f360ls-tabs-layout f360ls-tabs-modern', $atts['theme'], $settings)); ?>"
        style="<?php echo esc_attr($this->wrap_style($settings)); ?>"
        dir="rtl"
        data-f360ls-refresh="<?php echo esc_attr($atts['refresh']); ?>"
        data-f360ls-module="tabs"
        data-f360ls-ids="<?php echo esc_attr($atts['ids']); ?>"
    >
        <?php if (($settings['show_hero'] ?? '1') === '1'): ?>
            <?php echo $this->render_global_header('لیگ‌ها', count($leagues), 'نمایش سریع رقابت‌ها، جدول‌ها و بازی‌ها'); ?>
        <?php endif; ?>

        <aside class="f360ls-tabs-sidebar" aria-label="رقابت‌ها">
            <div class="f360ls-tabs-sidebar-title">رقابت‌ها</div>
            <button type="button" class="f360ls-league-slider-control is-next" data-f360ls-slide="next" aria-label="لیگ بعدی">‹</button>

            <?php foreach ($leagues as $i => $league):
                $id = sanitize_title($league['id'] ?? '');
                if (!$id) continue;
                ?>
                <button
                    type="button"
                    class="f360ls-tab <?php echo $i === $active ? 'is-active' : ''; ?>"
                    data-f360ls-tab="<?php echo esc_attr($id); ?>"
                    role="tab"
                >
                    <span><?php echo esc_html($this->tab_label((string) ($league['title'] ?? $id))); ?></span>
                    <small><?php echo esc_html($id); ?></small>
                </button>
            <?php endforeach; ?>
            <button type="button" class="f360ls-league-slider-control is-prev" data-f360ls-slide="prev" aria-label="لیگ قبلی">›</button>
        </aside>

        <div class="f360ls-tabbed-shell">
            <main class="f360ls-tabs-content">
                <?php echo $atts['show_filters'] === 'yes' ? $this->render_toolbar() : ''; ?>

                <div class="f360ls-refresh-target">
                    <?php foreach ($leagues as $i => $league):
                        $id = sanitize_title($league['id'] ?? '');
                        if (!$id) continue;

                        $is_active = ($i === $active);
                        ?>
                        <section
                            class="f360ls-panel <?php echo $is_active ? 'is-active' : ''; ?>"
                            data-f360ls-panel="<?php echo esc_attr($id); ?>"
                            data-f360ls-lazy-id="<?php echo esc_attr($id); ?>"
                            data-f360ls-loaded="<?php echo $is_active ? '1' : '0'; ?>"
                        >
                            <?php if ($is_active): ?>
                                <?php echo $this->render_league_content($repo->parse_league($id)); ?>
                            <?php else: ?>
                                <div class="f360ls-lazy-placeholder">
                                    برای نمایش این رقابت، روی تب آن کلیک کنید.
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            </main>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
    public function all_leagues($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts([
            'ids' => '',
            'theme' => $settings['default_theme'],
            'show_filters' => 'yes',
            'title' => 'همه لیگ‌ها',
            'refresh' => $settings['auto_refresh'],
        ], $atts, 'f360_all_leagues');

        $this->enqueue_front();
        $repo = F360LS_Repository::instance();
        $leagues = $this->filter_leagues($repo->get_enabled_leagues(), (string) $atts['ids']);
        if (empty($leagues)) return '<div class="f360ls-empty">هیچ لیگ فعالی ثبت نشده است.</div>';

        $uid = 'f360ls-' . wp_generate_uuid4();
        ob_start();
        ?>
        <div id="<?php echo esc_attr($uid); ?>" class="<?php echo esc_attr($this->wrap_classes('f360ls-all', $atts['theme'], $settings)); ?>" style="<?php echo esc_attr($this->wrap_style($settings)); ?>" dir="rtl" data-f360ls-refresh="<?php echo esc_attr($atts['refresh']); ?>" data-f360ls-module="all" data-f360ls-ids="<?php echo esc_attr($atts['ids']); ?>">
            <?php if ($settings['show_hero'] === '1') echo $this->render_global_header((string) $atts['title'], count($leagues), 'همه جدول‌ها و بازی‌ها در یک نمای یکپارچه'); ?>
            <div class="f360ls-league-jumps" aria-label="دسترسی سریع به لیگ‌ها">
                <?php foreach ($leagues as $league): ?><a href="#f360ls-league-<?php echo esc_attr($league['id']); ?>"><?php echo esc_html($league['title'] ?? $league['id']); ?></a><?php endforeach; ?>
            </div>
            <?php echo $atts['show_filters'] === 'yes' ? $this->render_toolbar() : ''; ?>
            <div class="f360ls-refresh-target f360ls-all-grid">
                <?php foreach ($leagues as $league): $data = $repo->parse_league($league['id']); ?>
                    <section id="f360ls-league-<?php echo esc_attr($league['id']); ?>" class="f360ls-panel f360ls-all-card is-active" data-f360ls-panel="<?php echo esc_attr($league['id']); ?>"><?php echo $this->render_league_content($data); ?></section>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function single_league($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts([
            'id' => '',
            'theme' => $settings['default_theme'],
            'show_filters' => 'yes',
            'refresh' => $settings['auto_refresh'],
        ], $atts, 'f360_league');
        $id = sanitize_title($atts['id']);
        if (!$id) return '<div class="f360ls-empty">شناسه لیگ وارد نشده است.</div>';

        $this->enqueue_front();
        $data = F360LS_Repository::instance()->parse_league($id);
        ob_start();
        ?>
        <div class="<?php echo esc_attr($this->wrap_classes('f360ls-single', $atts['theme'], $settings)); ?>" style="<?php echo esc_attr($this->wrap_style($settings)); ?>" dir="rtl" data-f360ls-refresh="<?php echo esc_attr($atts['refresh']); ?>" data-f360ls-module="league" data-f360ls-id="<?php echo esc_attr($id); ?>">
            <?php if ($atts['show_filters'] === 'yes') echo $this->render_toolbar(); ?>
            <section class="f360ls-panel is-active f360ls-refresh-target"><?php echo $this->render_league_content($data); ?></section>
        </div>
        <?php
        return ob_get_clean();
    }

    public function today_matches($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts(['ids'=>'','theme'=>$settings['default_theme'],'limit'=>80,'refresh'=>$settings['auto_refresh']], $atts, 'f360_today_matches');
        $this->enqueue_front();
        $items = $this->collect_matches((string) $atts['ids'], '', '', absint($atts['limit']));
        return $this->render_match_module('بازی‌های امروز', 'برنامه و نتایج امروز از لیگ‌های فعال', $items, 'today', $atts, $settings);
    }

    public function live_matches($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts(['ids'=>'','theme'=>$settings['default_theme'],'limit'=>60,'refresh'=>'1'], $atts, 'f360_live_matches');
        $this->enqueue_front();
        $items = $this->collect_matches((string) $atts['ids'], 'live', '', absint($atts['limit']));
        return $this->render_match_module('بازی‌های زنده', 'فقط مسابقاتی که در جریان هستند', $items, 'live', $atts, $settings);
    }

    public function featured_matches($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts(['ids'=>'','theme'=>$settings['default_theme'],'limit'=>12,'refresh'=>$settings['auto_refresh']], $atts, 'f360_featured_matches');
        $this->enqueue_front();
        $items = $this->collect_matches((string) $atts['ids'], '', '', absint($atts['limit']));
        usort($items, function($a, $b) {
            $rank = ['live'=>0,'scheduled'=>1,'finished'=>2];
            return ($rank[$a['status_type'] ?? 'finished'] ?? 9) <=> ($rank[$b['status_type'] ?? 'finished'] ?? 9);
        });
        return $this->render_match_module('بازی‌های مهم', 'اسلایدر مسابقات منتخب و مهم', array_slice($items, 0, absint($atts['limit'])), 'featured', $atts, $settings);
    }

    public function mini_table($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts(['id'=>'','theme'=>$settings['default_theme'],'limit'=>5,'title'=>'جدول خلاصه'], $atts, 'f360_mini_table');
        $id = sanitize_title($atts['id']);
        if (!$id) return '<div class="f360ls-empty">شناسه لیگ برای جدول خلاصه وارد نشده است.</div>';
        $this->enqueue_front();
        $data = F360LS_Repository::instance()->parse_league($id);
        $rows = array_slice($data['standings'] ?? [], 0, max(1, absint($atts['limit'])));
        ob_start(); ?>
        <div class="<?php echo esc_attr($this->wrap_classes('f360ls-mini-table-wrap', $atts['theme'], $settings)); ?>" style="<?php echo esc_attr($this->wrap_style($settings)); ?>" dir="rtl">
            <div class="f360ls-module-head"><strong><?php echo esc_html($atts['title']); ?></strong><span><?php echo esc_html($data['league']['title'] ?? ''); ?></span></div>
            <?php if (!$rows): ?><div class="f360ls-empty">جدولی برای این لیگ پیدا نشد.</div><?php else: ?>
            <div class="f360ls-mini-table">
                <?php foreach ($rows as $row): ?>
                    <div class="f360ls-mini-row" data-search="<?php echo esc_attr($row['team'] ?? ''); ?>"><b><?php echo esc_html($row['rank'] ?? ''); ?></b><?php if (!empty($row['logo'])): ?><img loading="lazy" decoding="async" src="<?php echo esc_url($row['logo']); ?>" alt="<?php echo esc_attr($row['team']); ?>"><?php endif; ?><span><?php echo esc_html($row['team'] ?? ''); ?></span><em><?php echo esc_html($row['points'] ?? ''); ?></em></div>
                <?php endforeach; ?>
            </div><?php endif; ?>
        </div><?php return ob_get_clean();
    }

    public function team_matches($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts(['ids'=>'','id'=>'','team'=>'','theme'=>$settings['default_theme'],'limit'=>20,'refresh'=>$settings['auto_refresh']], $atts, 'f360_team_matches');
        $ids = $atts['ids'] ?: $atts['id'];
        $team = $this->clean((string) $atts['team']);
        if (!$team) return '<div class="f360ls-empty">نام تیم را وارد کنید. مثال: [f360_team_matches team="پرسپولیس"]</div>';
        $this->enqueue_front();
        $items = $this->collect_matches((string) $ids, '', $team, absint($atts['limit']));
        return $this->render_match_module('بازی‌های ' . $team, 'نتایج و برنامه مسابقات تیم منتخب', $items, 'team', $atts, $settings);
    }

    public function favorite_teams($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts(['ids'=>'','theme'=>$settings['default_theme'],'limit'=>80], $atts, 'f360_favorite_teams');
        $this->enqueue_front();
        $teams = [];
        $matches = $this->collect_matches((string) $atts['ids'], '', '', absint($atts['limit']));
        foreach ($this->selected_league_data((string) $atts['ids']) as $data) {
            foreach (($data['standings'] ?? []) as $row) if (!empty($row['team'])) $teams[$row['team']] = $row['logo'] ?? '';
        }
        foreach ($matches as $m) { if (!empty($m['home'])) $teams[$m['home']] = $m['home_logo'] ?? ($teams[$m['home']] ?? ''); if (!empty($m['away'])) $teams[$m['away']] = $m['away_logo'] ?? ($teams[$m['away']] ?? ''); }
        ksort($teams, SORT_NATURAL);
        ob_start(); ?>
        <div class="<?php echo esc_attr($this->wrap_classes('f360ls-favorites-module', $atts['theme'], $settings)); ?>" style="<?php echo esc_attr($this->wrap_style($settings)); ?>" dir="rtl">
            <?php echo $this->render_global_header('تیم‌های محبوب', count($teams), 'تیم‌هایت را انتخاب کن تا بازی‌هایشان برجسته شود'); ?>
            <div class="f360ls-favorite-picker">
                <?php foreach ($teams as $team => $logo): ?><button type="button" data-f360ls-fav-team="<?php echo esc_attr($team); ?>"><?php if ($logo): ?><img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($team); ?>"><?php endif; ?><span><?php echo esc_html($team); ?></span></button><?php endforeach; ?>
            </div>
            <div class="f360ls-favorite-results"><?php echo $this->render_match_cards($matches, true); ?></div>
        </div><?php return ob_get_clean();
    }

    public function match_preview($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts(['id'=>'','home'=>'','away'=>'','theme'=>$settings['default_theme'],'limit'=>5], $atts, 'f360_match_preview');
        $id = sanitize_title($atts['id']); $home = $this->clean((string) $atts['home']); $away = $this->clean((string) $atts['away']);
        if (!$id || !$home || !$away) return '<div class="f360ls-empty">مثال: [f360_match_preview id="premier-league" home="تیم اول" away="تیم دوم"]</div>';
        $this->enqueue_front();
        $data = F360LS_Repository::instance()->parse_league($id);
        $homeRow = $this->find_standing($data, $home); $awayRow = $this->find_standing($data, $away);
        $homeForm = $this->team_form_array($data, $home, absint($atts['limit'])); $awayForm = $this->team_form_array($data, $away, absint($atts['limit']));
        ob_start(); ?>
        <div class="<?php echo esc_attr($this->wrap_classes('f360ls-preview-module', $atts['theme'], $settings)); ?>" style="<?php echo esc_attr($this->wrap_style($settings)); ?>" dir="rtl">
            <div class="f360ls-versus">
                <?php echo $this->render_preview_team($home, $homeRow, $homeForm); ?>
                <div class="f360ls-vs-badge">VS</div>
                <?php echo $this->render_preview_team($away, $awayRow, $awayForm); ?>
            </div>
        </div><?php return ob_get_clean();
    }

    public function team_form($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts(['id'=>'','team'=>'','theme'=>$settings['default_theme'],'limit'=>5], $atts, 'f360_team_form');
        $id = sanitize_title($atts['id']); $team = $this->clean((string) $atts['team']);
        if (!$id || !$team) return '<div class="f360ls-empty">مثال: [f360_team_form id="premier-league" team="آرسنال"]</div>';
        $this->enqueue_front();
        $data = F360LS_Repository::instance()->parse_league($id);
        $form = $this->team_form_array($data, $team, absint($atts['limit']));
        ob_start(); ?>
        <div class="<?php echo esc_attr($this->wrap_classes('f360ls-form-module', $atts['theme'], $settings)); ?>" style="<?php echo esc_attr($this->wrap_style($settings)); ?>" dir="rtl"><div class="f360ls-module-head"><strong>فرم <?php echo esc_html($team); ?></strong><span><?php echo esc_html($data['league']['title'] ?? ''); ?></span></div><?php echo $this->render_form_badges($form); ?></div>
        <?php return ob_get_clean();
    }

    public function top_scorers($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts(['id'=>'','theme'=>$settings['default_theme'],'limit'=>10], $atts, 'f360_top_scorers');
        $id = sanitize_title($atts['id']); if (!$id) return '<div class="f360ls-empty">شناسه لیگ را وارد کنید.</div>';
        $this->enqueue_front();
        $data = F360LS_Repository::instance()->parse_league($id);
        $rows = array_slice($data['top_scorers'] ?? [], 0, absint($atts['limit']));
        return $this->render_people_module('جدول گلزنان', $data['league']['title'] ?? '', $rows, $atts, $settings, 'هنوز داده گلزنان برای این لیگ پیدا نشد.');
    }

    public function league_news($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts(['id'=>'','theme'=>$settings['default_theme'],'limit'=>8], $atts, 'f360_league_news');
        $id = sanitize_title($atts['id']); if (!$id) return '<div class="f360ls-empty">شناسه لیگ را وارد کنید.</div>';
        $this->enqueue_front();
        $data = F360LS_Repository::instance()->parse_league($id);
        return $this->render_news_module('اخبار لیگ', $data['news'] ?? [], $atts, $settings);
    }

    public function team_news($atts): string {
        $settings = $this->get_settings();
        $atts = shortcode_atts(['ids'=>'','id'=>'','team'=>'','theme'=>$settings['default_theme'],'limit'=>8], $atts, 'f360_team_news');
        $team = $this->clean((string) $atts['team']); if (!$team) return '<div class="f360ls-empty">نام تیم را وارد کنید.</div>';
        $news = [];
        foreach ($this->selected_league_data((string) ($atts['ids'] ?: $atts['id'])) as $data) {
            foreach (($data['news'] ?? []) as $item) if (mb_stripos(($item['title'] ?? '') . ' ' . ($item['summary'] ?? ''), $team, 0, 'UTF-8') !== false) $news[] = $item;
        }
        $this->enqueue_front();
        return $this->render_news_module('اخبار ' . $team, $news, $atts, $settings);
    }

    public function ajax_refresh(): void {
        check_ajax_referer('f360ls_public', 'nonce');
        $module = sanitize_key($_POST['module'] ?? '');
        $atts = [
            'id' => sanitize_title($_POST['id'] ?? ''),
            'ids' => sanitize_text_field(wp_unslash($_POST['ids'] ?? '')),
            'team' => sanitize_text_field(wp_unslash($_POST['team'] ?? '')),
            'limit' => absint($_POST['limit'] ?? 80),
            'show_filters' => 'no',
            'refresh' => '0',
        ];
        if (!empty($_POST['force'])) F360LS_Repository::instance()->clear_all_caches();
        if ($module === 'league') {
            $html = $this->render_league_content(F360LS_Repository::instance()->parse_league($atts['id']));
        } elseif ($module === 'live') {
            $items = $this->collect_matches((string) $atts['ids'], 'live', '', $atts['limit']);
            $html = $items ? $this->render_match_cards($items) : '<div class="f360ls-empty">فعلاً بازی زنده‌ای پیدا نشد.</div>';
        } elseif ($module === 'today') {
            $items = $this->collect_matches((string) $atts['ids'], '', '', $atts['limit']);
            $html = $items ? $this->render_match_cards($items) : '<div class="f360ls-empty">فعلاً بازی برای نمایش پیدا نشد.</div>';
        } elseif ($module === 'team') {
            $items = $this->collect_matches((string) $atts['ids'], '', (string) $atts['team'], $atts['limit']);
            $html = $items ? $this->render_match_cards($items) : '<div class="f360ls-empty">فعلاً بازی برای این تیم پیدا نشد.</div>';
        } elseif ($module === 'featured') {
            $items = $this->collect_matches((string) $atts['ids'], '', '', $atts['limit']);
            $html = $items ? $this->render_match_cards($items) : '<div class="f360ls-empty">فعلاً بازی مهمی پیدا نشد.</div>';
        } elseif ($module === 'all') {
            $html = '';
            foreach ($this->filter_leagues(F360LS_Repository::instance()->get_enabled_leagues(), (string) $atts['ids']) as $league) {
                $data = F360LS_Repository::instance()->parse_league($league['id']);
                $html .= '<section id="f360ls-league-' . esc_attr($league['id']) . '" class="f360ls-panel f360ls-all-card is-active" data-f360ls-panel="' . esc_attr($league['id']) . '">' . $this->render_league_content($data) . '</section>';
            }
        } elseif ($module === 'tabs') {
            $html = '';
            $i = 0;
            foreach ($this->filter_leagues(F360LS_Repository::instance()->get_enabled_leagues(), (string) $atts['ids']) as $league) {
                $data = F360LS_Repository::instance()->parse_league($league['id']);
                $html .= '<section class="f360ls-panel ' . ($i === 0 ? 'is-active' : '') . '" data-f360ls-panel="' . esc_attr($league['id']) . '">' . $this->render_league_content($data) . '</section>';
                $i++;
            }
        } else $html = '';
        wp_send_json_success(['html' => $html, 'time' => current_time('mysql')]);
    }

    private function match_date_label(string $date): string {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $parts)) return '';
        $gregorian = $parts[1] . '-' . $parts[2] . '-' . $parts[3];
        $today = wp_date('Y-m-d', current_time('timestamp'));
        $tomorrow = wp_date('Y-m-d', current_time('timestamp') + DAY_IN_SECONDS);
        if ($gregorian === $today) return 'امروز';
        if ($gregorian === $tomorrow) return 'فردا';

        [$jy, $jm, $jd] = $this->gregorian_to_jalali((int) $parts[1], (int) $parts[2], (int) $parts[3]);
        return strtr(sprintf('%04d/%02d/%02d', $jy, $jm, $jd), ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    private function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
        $g_days = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_days[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) return [$jy, 1 + intdiv($days, 31), 1 + ($days % 31)];
        return [$jy, 7 + intdiv($days - 186, 30), 1 + (($days - 186) % 30)];
    }

    private function tab_label(string $title): string {
        // Saved league titles sometimes use a slug-style hyphen between Persian words.
        // This affects only the top league selector, not official team names or source data.
        return preg_replace('/(?<=[\x{0600}-\x{06FF}])\s*-\s*(?=[\x{0600}-\x{06FF}])/u', ' ', trim($title));
    }

    private function render_global_header(string $title, int $count, string $subtitle): string {
        ob_start(); ?>
        <div class="f360ls-hero"><div><span class="f360ls-kicker">Football Data Center</span><h2><?php echo esc_html($title); ?></h2><p><?php echo esc_html($subtitle); ?></p></div><div class="f360ls-hero-stats"><span><?php echo intval($count); ?></span><small>آیتم</small></div></div>
        <?php return ob_get_clean();
    }

    private function render_toolbar(): string {
        ob_start(); ?>
        <div class="f360ls-toolbar"><label class="f360ls-search"><span>جستجو</span><input type="search" class="f360ls-search-input" placeholder="نام تیم را جستجو کنید..."></label><div class="f360ls-filter-buttons"><button type="button" class="is-active" data-filter="all">همه</button><button type="button" data-filter="matches">بازی‌ها</button><button type="button" data-filter="standings">جدول</button></div></div>
        <?php return ob_get_clean();
    }

    private function render_league_content(array $data): string {
        ob_start();
        $title = $data['league']['title'] ?? ($data['configured_title'] ?? 'لیگ');
        $logo = $data['league']['logo'] ?? '';
        $matches = $data['matches'] ?? [];
        $weeks = $data['weeks'] ?? [];
        $standings = $data['standings'] ?? [];
        $top_scorers = $data['top_scorers'] ?? [];
        $source = $this->source_label($data);
        $settings = $this->get_settings();
        $subtitle = $this->display_subtitle((string) ($data['subtitle'] ?? ''));
        ?>
        <div class="f360ls-league-head f360ls-compact-head">
            <div class="f360ls-title-row">
                <div class="f360ls-logo-frame">
                    <?php if ($logo): ?><img class="f360ls-league-logo" loading="lazy" decoding="async" src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($title); ?>"><?php else: ?><span>⚽</span><?php endif; ?>
                </div>
                <div>
                    <h3><?php echo esc_html($title); ?></h3>
                    <?php if ($subtitle !== ''): ?><p><?php echo esc_html($subtitle); ?></p><?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($matches) || !empty($standings) || !empty($top_scorers)): ?>
            <div class="f360ls-footballi-board">
                <aside class="f360ls-board-col f360ls-board-matches" data-content-type="matches">
                    <div class="f360ls-board-title">برنامه بازی‌ها</div>
                    <?php echo !empty($matches) ? $this->render_matches_column($weeks) : '<div class="f360ls-empty">بازی‌ای برای نمایش پیدا نشد.</div>'; ?>
                </aside>

                <main class="f360ls-board-col f360ls-board-standings" data-content-type="standings">
                    <div class="f360ls-board-title">جدول رده‌بندی تیم‌ها</div>
                    <?php echo !empty($standings) ? $this->render_standings_table($standings) : '<div class="f360ls-empty">جدولی برای این رقابت پیدا نشد.</div>'; ?>
                </main>

                <aside class="f360ls-board-col f360ls-board-scorers" data-content-type="scorers">
                    <div class="f360ls-board-title">برترین‌های فصل</div>
                    <?php echo $this->render_top_scorers_box($top_scorers); ?>
                </aside>
            </div>
        <?php else: ?>
            <div class="f360ls-empty"><?php echo esc_html($data['message'] ?? 'برای این لیگ هنوز داده قابل نمایش پیدا نشد. لینک منبع یا فایل HTML/JSON را بروزرسانی کنید.'); ?></div>
        <?php endif; ?>

        <div class="f360ls-no-results">نتیجه‌ای برای جستجو/فیلتر در لیگ فعال پیدا نشد.</div>
        <?php if (!empty($data['description'])): ?><details class="f360ls-description"><summary>درباره لیگ</summary><p><?php echo esc_html($data['description']); ?></p></details><?php endif; ?>
        <?php return ob_get_clean();
    }

    private function render_standings_table(array $standings): string {
        $last_group = null;
        ob_start(); ?>
        <div class="f360ls-table-scroll"><table class="f360ls-standings-table"><thead><tr><th></th><th>تیم</th><th>بازی</th><th>برد</th><th>مساوی</th><th>باخت</th><th>تفاضل</th><th>گل +/-</th><th>امتیاز</th></tr></thead><tbody>
            <?php $display_rank = 0; $used_ranks = []; foreach ($standings as $row):
                $group = $row['group'] ?? '';
                if ($group && $group !== $last_group): $last_group = $group; $display_rank = 0; $used_ranks = []; ?>
                    <tr class="f360ls-group-row"><td colspan="9"><?php echo esc_html($group); ?></td></tr>
                <?php endif;
                $source_rank = intval($row['rank'] ?? 0);
                $display_rank++;
                $rank = ($source_rank > 0 && !isset($used_ranks[$source_rank])) ? $source_rank : $display_rank;
                $used_ranks[$rank] = true; ?>
                <tr class="<?php echo $rank > 0 && $rank <= 3 ? 'is-top-rank' : ''; ?>" data-search="<?php echo esc_attr($row['team'] ?? ''); ?>">
                    <td class="rank"><span><?php echo esc_html($rank); ?></span></td>
                    <td class="team"><?php if (!empty($row['logo'])): ?><img loading="lazy" decoding="async" src="<?php echo esc_url($row['logo']); ?>" alt="<?php echo esc_attr($row['team']); ?>"><?php endif; ?><span><?php echo esc_html($row['team'] ?? ''); ?></span><i class="move move-<?php echo esc_attr($row['movement'] ?? 'equal'); ?>"></i></td>
                    <td><?php echo esc_html($row['played'] ?? ''); ?></td><td><?php echo esc_html($row['won'] ?? ''); ?></td><td><?php echo esc_html($row['draw'] ?? ''); ?></td><td><?php echo esc_html($row['lost'] ?? ''); ?></td><td><?php echo esc_html($row['diff'] ?? ''); ?></td><td><?php echo esc_html($row['goals'] ?? ''); ?></td><td class="points"><?php echo esc_html($row['points'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div><?php return ob_get_clean();
    }

    private function render_matches_column(array $weeks): string {
        ob_start(); ?>
        <div class="f360ls-vertical-matches">
            <?php foreach ($weeks as $week): $shown_date = ''; ?>
                <div class="f360ls-vertical-week">
                    <?php foreach (($week['matches'] ?? []) as $m):
                        $match_date = $this->match_date_label((string) ($m['date'] ?? ''));
                        if ($match_date !== '' && $match_date !== $shown_date): $shown_date = $match_date; ?>
                            <h4><?php echo esc_html($match_date); ?></h4>
                        <?php elseif ($shown_date === '' && !empty($week['title'])): $shown_date = '__week__'; ?>
                            <h4><?php echo esc_html($week['title']); ?></h4>
                        <?php endif; ?>
                        <article class="f360ls-vertical-match" data-search="<?php echo esc_attr(($m['home'] ?? '') . ' ' . ($m['away'] ?? '')); ?>">
                            <div class="f360ls-match-date"><?php echo esc_html($m['status'] ?? ''); ?></div>
                            <div class="f360ls-match-teams-row">
                                <span><?php echo esc_html($m['home'] ?? ''); ?></span><?php if (!empty($m['home_logo'])): ?><img loading="lazy" decoding="async" src="<?php echo esc_url($m['home_logo']); ?>" alt="<?php echo esc_attr($m['home']); ?>"><?php endif; ?>
                            </div>
                            <strong><?php echo esc_html($m['score'] ?? '—'); ?></strong>
                            <div class="f360ls-match-teams-row is-away">
                                <?php if (!empty($m['away_logo'])): ?><img loading="lazy" decoding="async" src="<?php echo esc_url($m['away_logo']); ?>" alt="<?php echo esc_attr($m['away']); ?>"><?php endif; ?><span><?php echo esc_html($m['away'] ?? ''); ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div><?php return ob_get_clean();
    }
    
  private function render_top_scorers_box(array $rows): string {
    // فقط داده‌های گل نمایش داده می‌شود؛ تب پاس گل و مجموع حذف شده‌اند.
    $goal_rows = array_values(array_filter($rows, function($row) {
        $metric = $row['metric'] ?? 'goals';
        return $metric === 'goals' || empty($row['metric']);
    }));

    // اگر منبع فقط یک نوع داده داده بود، برای خالی نشدن بخش همان را نمایش بده.
    if (empty($goal_rows) && !empty($rows)) {
        $goal_rows = $rows;
    }

    ob_start();
    ?>
    <div class="f360ls-scorer-list f360ls-scorer-list-single">
        <?php if (empty($goal_rows)): ?>
            <div class="f360ls-empty">موردی پیدا نشد</div>
        <?php else: ?>
            <div class="f360ls-scorer-head">
                <span>رتبه</span>
                <span>بازیکن</span>
                <span>گل</span>
            </div>

            <?php foreach (array_slice($goal_rows, 0, 30) as $i => $row):
                $value = $row['goals'] ?? $row['value'] ?? '';
                ?>
                <div class="f360ls-scorer-row" data-search="<?php echo esc_attr($row['name'] ?? ''); ?>">
                    <b><?php echo esc_html($row['rank'] ?? (string) ($i + 1)); ?></b>

                    <?php if (!empty($row['photo'])): ?>
                        <img
                            loading="lazy"
                            decoding="async"
                            src="<?php echo esc_url($row['photo']); ?>"
                            alt="<?php echo esc_attr($row['name'] ?? ''); ?>"
                        >
                    <?php else: ?>
                        <span class="f360ls-player-placeholder">👤</span>
                    <?php endif; ?>

                    <span>
                        <strong><?php echo esc_html($row['name'] ?? ''); ?></strong>

                        <?php if (!empty($row['team'])): ?>
                            <small>
                                <?php if (!empty($row['team_logo'])): ?>
                                    <img
                                        loading="lazy"
                                        decoding="async"
                                        src="<?php echo esc_url($row['team_logo']); ?>"
                                        alt="<?php echo esc_attr($row['team']); ?>"
                                    >
                                <?php endif; ?>
                                <?php echo esc_html($row['team']); ?>
                            </small>
                        <?php endif; ?>
                    </span>

                    <em title="پنالتی: <?php echo esc_attr($row['penalty'] ?? '0'); ?>">
                        <?php echo esc_html($value); ?>
                    </em>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
    private function render_match_cards(array $matches, bool $favoriteAware = false): string {
        ob_start(); ?>
        <div class="f360ls-match-list">
            <?php foreach ($matches as $m): $teams = trim(($m['home'] ?? '') . '|' . ($m['away'] ?? '')); ?>
                <article class="f360ls-match-card" data-search="<?php echo esc_attr(($m['home'] ?? '') . ' ' . ($m['away'] ?? '')); ?>" <?php echo $favoriteAware ? 'data-f360ls-match-teams="' . esc_attr($teams) . '"' : ''; ?>><div class="team home"><?php if (!empty($m['home_logo'])): ?><img loading="lazy" decoding="async" src="<?php echo esc_url($m['home_logo']); ?>" alt="<?php echo esc_attr($m['home']); ?>"><?php endif; ?><span><?php echo esc_html($m['home'] ?? ''); ?></span></div><div class="scorebox"><span class="score"><?php echo esc_html($m['score'] ?? '—'); ?></span><small class="status status-<?php echo esc_attr($m['status_type'] ?? 'scheduled'); ?>"><i></i><?php echo esc_html($m['status'] ?? ''); ?></small><?php if (!empty($m['league_title'])): ?><small class="f360ls-match-league"><?php echo esc_html($m['league_title']); ?></small><?php endif; ?><?php if (!empty($m['href'])): ?><a class="f360ls-match-link" href="<?php echo esc_url($m['href']); ?>" target="_blank" rel="nofollow noopener">جزئیات</a><?php endif; ?></div><div class="team away"><?php if (!empty($m['away_logo'])): ?><img loading="lazy" decoding="async" src="<?php echo esc_url($m['away_logo']); ?>" alt="<?php echo esc_attr($m['away']); ?>"><?php endif; ?><span><?php echo esc_html($m['away'] ?? ''); ?></span></div></article>
            <?php endforeach; ?>
        </div><?php return ob_get_clean();
    }

    private function render_match_module(string $title, string $subtitle, array $items, string $module, array $atts, array $settings): string {
        ob_start(); ?>
        <div class="<?php echo esc_attr($this->wrap_classes('f360ls-match-module f360ls-module-' . $module, $atts['theme'] ?? $settings['default_theme'], $settings)); ?>" style="<?php echo esc_attr($this->wrap_style($settings)); ?>" dir="rtl" data-f360ls-refresh="<?php echo esc_attr($atts['refresh'] ?? '0'); ?>" data-f360ls-module="<?php echo esc_attr($module); ?>" data-f360ls-ids="<?php echo esc_attr($atts['ids'] ?? ''); ?>" data-f360ls-team="<?php echo esc_attr($atts['team'] ?? ''); ?>" data-f360ls-limit="<?php echo esc_attr($atts['limit'] ?? 80); ?>">
            <?php echo $this->render_global_header($title, count($items), $subtitle); ?>
            <div class="f360ls-refresh-target"><?php echo $items ? $this->render_match_cards($items) : '<div class="f360ls-empty">فعلاً موردی برای نمایش پیدا نشد.</div>'; ?></div>
        </div><?php return ob_get_clean();
    }

    private function render_people_module(string $title, string $subtitle, array $rows, array $atts, array $settings, string $empty): string {
        ob_start(); ?>
        <div class="<?php echo esc_attr($this->wrap_classes('f360ls-people-module', $atts['theme'] ?? $settings['default_theme'], $settings)); ?>" style="<?php echo esc_attr($this->wrap_style($settings)); ?>" dir="rtl"><div class="f360ls-module-head"><strong><?php echo esc_html($title); ?></strong><span><?php echo esc_html($subtitle); ?></span></div><?php if (!$rows): ?><div class="f360ls-empty"><?php echo esc_html($empty); ?></div><?php else: ?><div class="f360ls-people-list"><?php foreach ($rows as $i => $row): ?><div class="f360ls-person-row"><b><?php echo $i + 1; ?></b><?php if (!empty($row['photo'])): ?><img loading="lazy" decoding="async" src="<?php echo esc_url($row['photo']); ?>" alt="<?php echo esc_attr($row['name']); ?>"><?php endif; ?><span><?php echo esc_html($row['name'] ?? ''); ?></span><em><?php echo esc_html($row['goals'] ?? $row['value'] ?? ''); ?></em></div><?php endforeach; ?></div><?php endif; ?></div>
        <?php return ob_get_clean();
    }

    private function render_news_module(string $title, array $news, array $atts, array $settings): string {
        $news = array_slice($news, 0, absint($atts['limit'] ?? 8));
        ob_start(); ?>
        <div class="<?php echo esc_attr($this->wrap_classes('f360ls-news-module', $atts['theme'] ?? $settings['default_theme'], $settings)); ?>" style="<?php echo esc_attr($this->wrap_style($settings)); ?>" dir="rtl"><div class="f360ls-module-head"><strong><?php echo esc_html($title); ?></strong><span><?php echo count($news); ?> خبر</span></div><?php if (!$news): ?><div class="f360ls-empty">فعلاً خبری برای نمایش پیدا نشد.</div><?php else: ?><div class="f360ls-news-grid"><?php foreach ($news as $item): ?><a class="f360ls-news-card" href="<?php echo esc_url($item['href'] ?? '#'); ?>" target="_blank" rel="nofollow noopener"><?php if (!empty($item['image'])): ?><img loading="lazy" decoding="async" src="<?php echo esc_url($item['image']); ?>" alt="<?php echo esc_attr($item['title']); ?>"><?php endif; ?><strong><?php echo esc_html($item['title'] ?? ''); ?></strong><?php if (!empty($item['summary'])): ?><p><?php echo esc_html($item['summary']); ?></p><?php endif; ?></a><?php endforeach; ?></div><?php endif; ?></div>
        <?php return ob_get_clean();
    }

    private function selected_league_data(string $ids = ''): array {
        $repo = F360LS_Repository::instance();
        $leagues = $this->filter_leagues($repo->get_enabled_leagues(), $ids);
        $data = [];
        foreach ($leagues as $league) if (!empty($league['id'])) $data[] = $repo->parse_league($league['id']);
        return $data;
    }

    private function collect_matches(string $ids = '', string $status = '', string $team = '', int $limit = 80): array {
        $out = [];
        foreach ($this->selected_league_data($ids) as $data) {
            foreach (($data['matches'] ?? []) as $m) {
                if ($status && (($m['status_type'] ?? '') !== $status)) continue;
                if ($team && mb_stripos(($m['home'] ?? '') . ' ' . ($m['away'] ?? ''), $team, 0, 'UTF-8') === false) continue;
                $m['league_title'] = $data['league']['title'] ?? '';
                $out[] = $m;
                if ($limit && count($out) >= $limit) return $out;
            }
        }
        return $out;
    }

    private function find_standing(array $data, string $team): array {
        foreach (($data['standings'] ?? []) as $row) if (mb_strtolower($row['team'] ?? '', 'UTF-8') === mb_strtolower($team, 'UTF-8')) return $row;
        foreach (($data['standings'] ?? []) as $row) if (mb_stripos($row['team'] ?? '', $team, 0, 'UTF-8') !== false) return $row;
        return [];
    }

    private function team_form_array(array $data, string $team, int $limit = 5): array {
        $form = [];
        foreach (($data['matches'] ?? []) as $m) {
            if (($m['status_type'] ?? '') !== 'finished') continue;
            $home = $m['home'] ?? ''; $away = $m['away'] ?? '';
            if (mb_stripos($home . ' ' . $away, $team, 0, 'UTF-8') === false) continue;
            if (!preg_match('/(\d+)\s*[-:]\s*(\d+)/', $m['score'] ?? '', $score)) continue;
            $hs = (int) $score[1]; $as = (int) $score[2];
            $isHome = mb_stripos($home, $team, 0, 'UTF-8') !== false;
            $result = $hs === $as ? 'D' : (($isHome && $hs > $as) || (!$isHome && $as > $hs) ? 'W' : 'L');
            $form[] = $result;
            if (count($form) >= $limit) break;
        }
        return $form;
    }

    private function render_preview_team(string $team, array $row, array $form): string {
        ob_start(); ?>
        <div class="f360ls-preview-team"><?php if (!empty($row['logo'])): ?><img loading="lazy" decoding="async" src="<?php echo esc_url($row['logo']); ?>" alt="<?php echo esc_attr($team); ?>"><?php endif; ?><h3><?php echo esc_html($team); ?></h3><div class="f360ls-preview-stats"><span>رتبه <?php echo esc_html($row['rank'] ?? '-'); ?></span><span><?php echo esc_html($row['points'] ?? '-'); ?> امتیاز</span></div><?php echo $this->render_form_badges($form); ?></div>
        <?php return ob_get_clean();
    }

    private function render_form_badges(array $form): string {
        if (!$form) return '<div class="f360ls-form-badges"><span class="is-empty">بدون داده فرم</span></div>';
        $labels = ['W'=>'ب','D'=>'م','L'=>'ش'];
        $titles = ['W'=>'برد','D'=>'مساوی','L'=>'باخت'];
        $out = '<div class="f360ls-form-badges">';
        foreach ($form as $r) $out .= '<span class="form-' . esc_attr($r) . '" title="' . esc_attr($titles[$r] ?? $r) . '">' . esc_html($labels[$r] ?? $r) . '</span>';
        return $out . '</div>';
    }

    private function display_subtitle(string $subtitle): string {
        $subtitle = trim($subtitle);
        if ($subtitle === '') return '';
        if (preg_match('/Footballi|footballi|فوتبالی|کشف|اسکرپر|منبع/u', $subtitle)) return '';
        return $subtitle;
    }

    private function default_settings(): array {
        return ['default_theme'=>'light','accent_color'=>'#16a34a','accent2_color'=>'#22c55e','background_color'=>'#f4f7fb','card_color'=>'#ffffff','text_color'=>'#0f172a','radius'=>'32','density'=>'comfortable','show_source'=>'0','show_hero'=>'1','auto_refresh'=>'0','refresh_interval'=>'60','custom_font_url'=>''];
    }

    private function get_settings(): array {
        $settings = get_option(F360LS_OPTION_SETTINGS, []);
        return wp_parse_args(is_array($settings) ? $settings : [], $this->default_settings());
    }

    private function wrap_classes(string $extra, string $theme, array $settings): string {
        $theme = $theme === 'dark' ? 'dark' : 'light';
        $density = ($settings['density'] ?? 'comfortable') === 'compact' ? 'compact' : 'comfortable';
        $anim = ($settings['animations'] ?? '1') === '1' ? '' : ' f360ls-no-animations';
        return trim('f360ls-wrap ' . $extra . ' f360ls-theme-' . $theme . ' f360ls-density-' . $density . $anim);
    }

    private function wrap_style(array $settings): string {
        $pairs = ['--accent'=>$settings['accent_color'] ?? '#16a34a','--accent2'=>$settings['accent2_color'] ?? '#22c55e','--bg'=>$settings['background_color'] ?? '#f4f7fb','--surface'=>$settings['card_color'] ?? '#ffffff','--text'=>$settings['text_color'] ?? '#0f172a','--wrapRadius'=>max(12, min(44, absint($settings['radius'] ?? 32))) . 'px'];
        $style = [];
        foreach ($pairs as $key => $value) $style[] = $key . ':' . esc_attr($value);
        return implode(';', $style);
    }

    private function filter_leagues(array $leagues, string $ids): array {
        if ($ids === '') return $leagues;
        $wanted = array_filter(array_map('sanitize_title', explode(',', $ids)));
        return array_values(array_filter($leagues, fn($l) => in_array($l['id'] ?? '', $wanted, true)));
    }

    private function shortcode_alias(string $id): string {
        $alias = sanitize_key(str_replace('-', '_', $id));
        return preg_replace('/[^a-z0-9_]/', '', $alias);
    }

    private function source_label(array $data): string {
        foreach (($data['sources'] ?? []) as $source) {
            $url = (string) ($source['url'] ?? ''); $path = (string) ($source['path'] ?? '');
            if (strpos($url . $path, 'footballi') !== false) return 'منبع آنلاین';
            if (strpos($url . $path, 'football360') !== false) return 'Football360';
        }
        return 'Live Data';
    }

    private function clean(string $text): string {
        return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));
    }
}
