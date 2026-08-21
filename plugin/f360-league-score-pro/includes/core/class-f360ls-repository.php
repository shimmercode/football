<?php
if (!defined('ABSPATH')) { exit; }

class F360LS_Repository {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function ensure_upload_dir(): void {
        $dir = $this->upload_dir();
        if (!file_exists($dir)) wp_mkdir_p($dir);
        $plugin_dir = F360LS_PLUGIN_DIR . 'data/leagues/';
        if (!file_exists($plugin_dir)) wp_mkdir_p($plugin_dir);
    }

    public function upload_dir(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'f360-league-score-pro/';
    }

    public function get_leagues(): array {
        $leagues = get_option(F360LS_OPTION_LEAGUES, []);
        return is_array($leagues) ? $leagues : [];
    }

    public function save_leagues(array $leagues): void {
        update_option(F360LS_OPTION_LEAGUES, array_values($leagues), false);
    }

    public function get_enabled_leagues(): array {
        return array_values(array_filter($this->get_leagues(), function($league) {
            return !isset($league['enabled']) || (bool) $league['enabled'];
        }));
    }

    public function get_league(string $id): ?array {
        foreach ($this->get_leagues() as $league) {
            if (($league['id'] ?? '') === $id) return $league;
        }
        return null;
    }

    public function upsert_league(array $league): void {
        $league['id'] = sanitize_title($league['id'] ?? '');
        if (!$league['id']) return;

        $league = wp_parse_args($league, [
            'title' => $league['id'],
            'subtitle' => '',
            'source_url' => '',
            'games_url' => '',
            'table_url' => '',
            'file' => '',
            'games_file' => '',
            'table_file' => '',
            'json_file' => '',
            'is_plugin_file' => false,
            'enabled' => true,
            'created_at' => current_time('mysql'),
        ]);

        $leagues = $this->get_leagues();
        $found = false;
        foreach ($leagues as $idx => $item) {
            if (($item['id'] ?? '') === $league['id']) {
                $leagues[$idx] = array_merge($item, $league, ['updated_at' => current_time('mysql')]);
                $found = true;
                break;
            }
        }
        if (!$found) $leagues[] = $league;
        $this->save_leagues($leagues);
        $this->clear_cache($league['id']);
    }

    public function delete_league(string $id): void {
        $leagues = array_values(array_filter($this->get_leagues(), fn($l) => ($l['id'] ?? '') !== $id));
        $this->save_leagues($leagues);
        $this->clear_cache($id);
    }

    public function parse_league(string $id): array {
        $cache_key = F360LS_CACHE_PREFIX . md5($id);
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        $league = $this->get_league($id);
        if (!$league) {
            if (class_exists('F360LS_Logger')) F360LS_Logger::log('warning', 'لیگ پیدا نشد.', ['league_id' => $id]);
            return $this->empty_payload(['id' => $id, 'title' => $id], 'لیگ پیدا نشد.');
        }

        $lock_key = F360LS_CACHE_PREFIX . 'lock_' . md5($id);
        $last_key = F360LS_CACHE_PREFIX . 'last_' . md5($id);
        if (get_transient($lock_key)) {
            $last = get_option($last_key, []);
            if (is_array($last) && !empty($last)) return $last;
            return $this->empty_payload($league, 'داده این لیگ در حال بروزرسانی است. چند لحظه دیگر دوباره تلاش کنید.');
        }
        set_transient($lock_key, 1, 45);

        $sources = $this->collect_sources($league);
        if (empty($sources)) {
            delete_transient($lock_key);
            if (class_exists('F360LS_Logger')) F360LS_Logger::log('warning', 'هیچ منبع معتبری برای لیگ پیدا نشد.', ['league_id' => $id]);
            return $this->empty_payload($league, 'هیچ فایل یا لینک معتبری برای این لیگ ثبت نشده است. لینک منبع یا فایل HTML/JSON لیگ را وارد کنید.');
        }

        $payload = $this->empty_payload($league, '');
        $payload['id'] = $league['id'];
        $payload['configured_title'] = $league['title'] ?? $league['id'];
        $payload['subtitle'] = $league['subtitle'] ?? '';
        $payload['source_url'] = $league['source_url'] ?? '';
        $payload['games_url'] = $league['games_url'] ?? '';
        $payload['table_url'] = $league['table_url'] ?? '';
        $payload['file'] = $league['file'] ?? '';
        $payload['json_file'] = $league['json_file'] ?? '';
        $payload['source_messages'] = [];

        $parsed_any = false;
        foreach ($sources as $source) {
            $data = [];
            try {
                if (($source['type'] ?? '') === 'json_file') {
                    $json = $source['json'] ?? '';
                    if (!$json) continue;
                    $parser = new F360LS_JSON_Parser($json);
                    $data = $parser->parse($league);
                } else {
                    $html = $source['html'] ?? '';
                    if (!$html) continue;
                    if (class_exists('F360LS_Footballi_Parser') && F360LS_Footballi_Parser::looks_like($html, $source)) {
                        $parser = new F360LS_Footballi_Parser($html, $source);
                        $data = $parser->parse($league);
                    } else {
                        $parser = new F360LS_Parser($html);
                        $data = $parser->parse();
                    }
                }
            } catch (Throwable $e) {
                if (class_exists('F360LS_Logger')) F360LS_Logger::log('error', 'خطا هنگام parse منبع.', ['league_id' => $id, 'error' => $e->getMessage()]);
                continue;
            }
            $parsed_any = true;
            $payload = $this->merge_payloads($payload, $data, $source);
        }

        if (!$parsed_any) {
            $payload['message'] = 'منبع لیگ قابل خواندن نبود. اگر از لینک مستقیم استفاده می‌کنید، مطمئن شوید سرور شما به سایت مرجع دسترسی دارد.';
            if (class_exists('F360LS_Logger')) F360LS_Logger::log('error', 'هیچ منبعی قابل parse نبود.', ['league_id' => $id]);
        }

        if (empty($payload['league']['title'])) {
            $payload['league']['title'] = $league['title'] ?? $league['id'];
        }

        $payload = $this->apply_logo_overrides($payload);
        $payload['stats'] = $this->build_stats($payload);
        if (empty($payload['last_update'])) {
            $payload['last_update'] = $this->fallback_last_update($league, $sources);
        }

        if (empty($payload['matches']) && empty($payload['standings']) && empty($payload['message'])) {
            $payload['message'] = 'داده قابل نمایش پیدا نشد. لینک/فایل لیگ یا نتایج زنده را بروزرسانی کنید.';
        }

        set_transient($cache_key, $payload, $this->cache_ttl($payload));
        update_option($last_key, $payload, false);
        delete_transient($lock_key);
        if (class_exists('F360LS_Logger')) {
            F360LS_Logger::log('info', 'داده لیگ بروزرسانی شد.', [
                'league_id' => $id,
                'matches' => count($payload['matches'] ?? []),
                'standings' => count($payload['standings'] ?? []),
            ]);
        }
        return $payload;
    }

    private function collect_sources(array $league): array {
        $sources = [];

        $main_file = $league['file'] ?? '';
        $table_file = $league['table_file'] ?? '';
        $games_file = $league['games_file'] ?? '';
        $json_file = $league['json_file'] ?? '';

        if (!empty($json_file) && file_exists($json_file) && is_readable($json_file)) {
            $json = file_get_contents($json_file);
            if ($json) $sources[] = ['type' => 'json_file', 'kind' => 'json_file', 'path' => $json_file, 'json' => $json, 'mtime' => filemtime($json_file)];
        }

        foreach ([
            ['kind' => 'main_file', 'path' => $main_file],
            ['kind' => 'table_file', 'path' => $table_file],
            ['kind' => 'games_file', 'path' => $games_file],
        ] as $item) {
            if (!empty($item['path']) && file_exists($item['path']) && is_readable($item['path'])) {
                $html = file_get_contents($item['path']);
                if ($html) $sources[] = ['type' => 'file', 'kind' => $item['kind'], 'path' => $item['path'], 'html' => $html, 'mtime' => filemtime($item['path'])];
            }
        }

        $urls = [];
        foreach (['source_url', 'games_url', 'table_url'] as $key) {
            if (!empty($league[$key])) $urls[$key] = esc_url_raw($league[$key]);
        }

        if (!empty($league['source_url'])) {
            $derived_games = $this->derive_games_url($league['source_url']);
            if ($derived_games) $urls['derived_games_url'] = $derived_games;
        }

        // Built-in fallback for the common Premier League tab, so old installations
        // that only uploaded the table HTML can still load matches from the /games page.
        if (empty($urls) && !empty($league['id']) && $league['id'] === 'premier-league') {
            $urls['builtin_premier_games'] = 'https://football360.ir/league/fcec7abb-dead-49c3-a907-1948e33fa438/20252026-Premier-League/games';
        }

        foreach ($urls as $kind => $url) {
            if (!$url || !wp_http_validate_url($url)) continue;
            // The public live-scores page contains every competition. It must never
            // be treated as a source for one particular league.
            if (preg_match('~footballi\.net/live-scores/?(?:[?#].*)?$~i', $url)) continue;
            $html = $this->fetch_url($url);
            if ($html) {
                $sources[] = ['type' => 'url', 'kind' => $kind, 'url' => $url, 'html' => $html, 'mtime' => time()];
            }
        }

        return $sources;
    }

    private function fetch_url(string $url): string {
        if (!$this->is_url_allowed($url)) {
            if (class_exists('F360LS_Logger')) F360LS_Logger::log('warning', 'دامنه منبع در لیست مجاز نیست.', ['url' => $url]);
            return '';
        }
        $args = [
            'timeout' => 20,
            'redirection' => 5,
            'sslverify' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; F360LeagueScorePro/' . F360LS_VERSION . '; WordPress)',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'fa-IR,fa;q=0.9,en-US;q=0.6,en;q=0.5',
            ],
        ];
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response) && strpos(strtolower($url), 'footballi.net') !== false) {
            // Some hosts have outdated CA/cipher bundles; retry without SSL verification for allowed sources.
            $args['sslverify'] = false;
            $response = wp_remote_get($url, $args);
        }
        if (is_wp_error($response)) {
            if (class_exists('F360LS_Logger')) F360LS_Logger::log('error', 'خطای اتصال به منبع.', ['url' => $url, 'error' => $response->get_error_message()]);
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            if (class_exists('F360LS_Logger')) F360LS_Logger::log('warning', 'کد HTTP ناموفق از منبع دریافت شد.', ['url' => $url, 'code' => $code]);
            return '';
        }
        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            if (class_exists('F360LS_Logger')) F360LS_Logger::log('warning', 'بدنه پاسخ منبع خالی بود.', ['url' => $url]);
        }
        return $body;
    }

    private function is_url_allowed(string $url): bool {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host) return false;
        $host = strtolower((string) $host);
        $settings = get_option(F360LS_OPTION_SETTINGS, []);
        $allowed = $settings['allowed_domains'] ?? "footballi.net\nfootball360.ir\ncdn.oddrun.ir";
        $domains = array_filter(array_map('trim', preg_split('/\R+|,/', (string) $allowed)));
        foreach ($domains as $domain) {
            $domain = strtolower(ltrim($domain, '.'));
            if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) return true;
        }
        return false;
    }

    private function derive_games_url(string $url): string {
        $url = trim($url);
        if (!$url) return '';
        if (strpos(strtolower($url), 'footballi.net') !== false) {
            // Some source pages do not use the /games suffix.
            return '';
        }
        if (preg_match('~/games(?:[/?#]|$)~', $url)) return $url;
        $url = preg_replace('~/(post|statistics(?:/players|/teams)?|transfers)/?$~', '', $url);
        return rtrim($url, '/') . '/games';
    }

    private function merge_payloads(array $base, array $data, array $source): array {
        if (!empty($data['league']['title']) && (empty($base['league']['title']) || $source['kind'] !== 'derived_games_url')) {
            $base['league']['title'] = $data['league']['title'];
        }
        if (!empty($data['league']['logo']) && empty($base['league']['logo'])) {
            $base['league']['logo'] = $data['league']['logo'];
        }
        if (!empty($data['last_update'])) {
            $base['last_update'] = $data['last_update'];
        }
        if (!empty($data['description']) && empty($base['description'])) {
            $base['description'] = $data['description'];
        }

        if (!empty($data['standings'])) {
            $base['standings'] = $this->dedupe_standings(array_merge($base['standings'] ?? [], $data['standings']));
        }
        if (!empty($data['matches'])) {
            $base['matches'] = $this->dedupe_matches(array_merge($base['matches'] ?? [], $data['matches']));
        }
        if (!empty($data['weeks'])) {
            $base['weeks'] = $this->merge_weeks($base['weeks'] ?? [], $data['weeks']);
        }
        if (!empty($data['news'])) {
            $base['news'] = $this->dedupe_items(array_merge($base['news'] ?? [], $data['news']), ['href','title']);
        }
        if (!empty($data['top_scorers'])) {
            $base['top_scorers'] = $this->dedupe_items(array_merge($base['top_scorers'] ?? [], $data['top_scorers']), ['name','goals']);
        }

        $base['sources'][] = [
            'type' => $source['type'] ?? '',
            'kind' => $source['kind'] ?? '',
            'url' => $source['url'] ?? '',
            'path' => $source['path'] ?? '',
        ];
        return $base;
    }

    private function merge_weeks(array $old, array $new): array {
        foreach ($new as $week) {
            $matches = $this->dedupe_matches($week['matches'] ?? []);
            if (!$matches) continue;
            $old[] = ['title' => $week['title'] ?? 'بازی‌ها', 'matches' => $matches];
        }
        // rebuild each week after global dedupe to avoid duplicates on repeated sources
        $seen = [];
        foreach ($old as $wi => $week) {
            $clean = [];
            foreach (($week['matches'] ?? []) as $m) {
                $key = md5(($m['home'] ?? '') . '|' . ($m['away'] ?? '') . '|' . ($m['score'] ?? '') . '|' . ($m['status'] ?? ''));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $clean[] = $m;
            }
            $old[$wi]['matches'] = $clean;
        }
        return array_values(array_filter($old, fn($w) => !empty($w['matches'])));
    }

    private function dedupe_matches(array $matches): array {
        $out = [];
        $positions = [];
        foreach ($matches as $m) {
            // A fixture and its later final result are the same match. Keep the final
            // score instead of retaining the older scheduled “—” entry.
            $key = md5(mb_strtolower(trim(($m['home'] ?? '') . '|' . ($m['away'] ?? '')), 'UTF-8'));
            if (!isset($positions[$key])) {
                $positions[$key] = count($out);
                $out[] = $m;
                continue;
            }
            $current = $out[$positions[$key]];
            if (($current['score'] ?? '—') === '—' && ($m['score'] ?? '—') !== '—') {
                $out[$positions[$key]] = $m;
            }
        }
        return $out;
    }

    private function dedupe_standings(array $rows): array {
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $key = md5(($r['rank'] ?? '') . '|' . ($r['team'] ?? ''));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $r;
        }
        return $out;
    }

    private function dedupe_items(array $items, array $keys): array {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $parts = [];
            foreach ($keys as $key) $parts[] = $item[$key] ?? '';
            $hash = md5(implode('|', $parts));
            if (isset($seen[$hash])) continue;
            $seen[$hash] = true;
            $out[] = $item;
        }
        return $out;
    }

    private function apply_logo_overrides(array $payload): array {
        $overrides = get_option(F360LS_OPTION_LOGO_OVERRIDES, []);
        if (!is_array($overrides)) return $payload;
        $league_id = $payload['id'] ?? '';
        if (!empty($overrides['leagues'][$league_id])) $payload['league']['logo'] = $overrides['leagues'][$league_id];
        foreach (['standings'] as $key) foreach (($payload[$key] ?? []) as $i => $row) if (!empty($overrides['teams'][$row['team'] ?? ''])) $payload[$key][$i]['logo'] = $overrides['teams'][$row['team']];
        foreach (['matches'] as $key) foreach (($payload[$key] ?? []) as $i => $match) { if (!empty($overrides['teams'][$match['home'] ?? ''])) $payload[$key][$i]['home_logo']=$overrides['teams'][$match['home']]; if (!empty($overrides['teams'][$match['away'] ?? ''])) $payload[$key][$i]['away_logo']=$overrides['teams'][$match['away']]; }
        foreach (($payload['weeks'] ?? []) as $wi => $week) foreach (($week['matches'] ?? []) as $mi => $match) { if (!empty($overrides['teams'][$match['home'] ?? ''])) $payload['weeks'][$wi]['matches'][$mi]['home_logo']=$overrides['teams'][$match['home']]; if (!empty($overrides['teams'][$match['away'] ?? ''])) $payload['weeks'][$wi]['matches'][$mi]['away_logo']=$overrides['teams'][$match['away']]; }
        return $payload;
    }

    private function build_stats(array $payload): array {
        $matches = $payload['matches'] ?? [];
        $standings = $payload['standings'] ?? [];
        return [
            'total' => count($matches),
            'finished' => count(array_filter($matches, fn($m) => ($m['status_type'] ?? '') === 'finished')),
            'live' => count(array_filter($matches, fn($m) => ($m['status_type'] ?? '') === 'live')),
            'scheduled' => count(array_filter($matches, fn($m) => ($m['status_type'] ?? '') === 'scheduled')),
            'teams' => count($standings),
            'has_matches' => !empty($matches),
            'has_table' => !empty($standings),
        ];
    }

    private function cache_ttl(array $payload): int {
        $settings = get_option(F360LS_OPTION_SETTINGS, []);
        $settings = is_array($settings) ? $settings : [];
        $live_ttl = max(15, min(900, absint($settings['live_cache_ttl'] ?? 45)));
        $default_ttl = max(300, min(86400, absint($settings['default_cache_ttl'] ?? F360LS_CACHE_TTL)));
        if (!empty($payload['stats']['live'])) return $live_ttl;
        return $default_ttl;
    }

    private function fallback_last_update(array $league, array $sources): string {
        $latest = 0;
        foreach ($sources as $s) $latest = max($latest, (int) ($s['mtime'] ?? 0));
        if (!$latest && !empty($league['file']) && file_exists($league['file'])) $latest = filemtime($league['file']);
        if ($latest) return 'آخرین بروزرسانی داده: ' . date_i18n(get_option('date_format') . ' - ' . get_option('time_format'), $latest);
        return '';
    }

    public function empty_payload(array $league, string $message = ''): array {
        return [
            'id' => $league['id'] ?? '',
            'configured_title' => $league['title'] ?? '',
            'league' => ['title' => $league['title'] ?? '', 'logo' => ''],
            'subtitle' => $league['subtitle'] ?? '',
            'weeks' => [],
            'matches' => [],
            'standings' => [],
            'top_scorers' => [],
            'news' => [],
            'last_update' => '',
            'description' => '',
            'stats' => ['total'=>0,'finished'=>0,'live'=>0,'scheduled'=>0,'teams'=>0,'has_matches'=>false,'has_table'=>false],
            'message' => $message,
            'sources' => [],
        ];
    }

    public function clear_cache(string $id): void {
        delete_transient(F360LS_CACHE_PREFIX . md5($id));
    }

    public function clear_all_caches(): void {
        foreach ($this->get_leagues() as $league) {
            if (!empty($league['id'])) $this->clear_cache($league['id']);
        }
    }


    public function cleanup_legacy_builtin_json_leagues(): void {
        $legacy_ids = ['bundesliga','laliga','ligue-1','nokhbegan','premier-league','serie-a','champions-league'];
        $changed = false;
        $leagues = [];
        foreach ($this->get_leagues() as $league) {
            $id = sanitize_title($league['id'] ?? '');
            $json_file = (string) ($league['json_file'] ?? '');
            $subtitle = (string) ($league['subtitle'] ?? '');
            $is_legacy = in_array($id, $legacy_ids, true) && (
                strpos($json_file, F360LS_PLUGIN_DIR . 'data/matches/') === 0 ||
                $subtitle === 'JSON بازی‌ها داخل پوشه افزونه'
            );
            if ($is_legacy) {
                $this->clear_cache($id);
                $changed = true;
                continue;
            }
            $leagues[] = $league;
        }
        if ($changed) {
            $this->save_leagues($leagues);
            if (class_exists('F360LS_Logger')) F360LS_Logger::log('info', 'لیگ‌های JSON داخلی قدیمی حذف شدند.', ['count' => count($legacy_ids)]);
        }
    }

    public function scan_plugin_json_files(): void {
        $dir = F360LS_PLUGIN_DIR . 'data/matches/';
        if (!is_dir($dir)) wp_mkdir_p($dir);
        foreach (glob($dir . '*') ?: [] as $file) {
            if (!is_file($file) || !is_readable($file)) continue;
            $first = trim((string) file_get_contents($file, false, null, 0, 1));
            if ($first !== '{' && $first !== '[') continue;
            $id = sanitize_title(pathinfo($file, PATHINFO_FILENAME));
            if (!$id) continue;
            $existing = $this->get_league($id);
            if ($existing) {
                if (empty($existing['json_file']) || $existing['json_file'] !== $file) {
                    $existing['json_file'] = $file;
                    $this->upsert_league($existing);
                }
                continue;
            }
            $title = ucwords(str_replace(['-', '_'], ' ', $id));
            $this->upsert_league([
                'id' => $id,
                'title' => $title,
                'subtitle' => 'فایل JSON داخلی',
                'source_url' => '',
                'json_file' => $file,
                'is_plugin_file' => true,
                'enabled' => true,
            ]);
        }
    }

    public function scan_plugin_html_files(): void {
        $dir = F360LS_PLUGIN_DIR . 'data/leagues/';
        if (!is_dir($dir)) return;
        foreach (glob($dir . '*.{html,htm}', GLOB_BRACE) ?: [] as $file) {
            $id = sanitize_title(pathinfo($file, PATHINFO_FILENAME));
            if (!$id) continue;
            $existing = $this->get_league($id);
            if ($existing) continue;
            $title = ucwords(str_replace(['-', '_'], ' ', $id));
            $this->upsert_league([
                'id' => $id,
                'title' => $title,
                'subtitle' => 'فایل داخل پوشه افزونه',
                'source_url' => '',
                'file' => $file,
                'is_plugin_file' => true,
                'enabled' => true,
            ]);
        }
    }
}
