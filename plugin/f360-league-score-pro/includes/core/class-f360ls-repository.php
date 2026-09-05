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
            'statistics_url' => '',
            'transfers_url' => '',
            'fallback_url' => '',
            'logo' => '',
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
        $payload['statistics_url'] = $league['statistics_url'] ?? '';
        $payload['transfers_url'] = $league['transfers_url'] ?? '';
        $payload['fallback_url'] = $league['fallback_url'] ?? '';
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
                    $source_url = (string) ($source['url'] ?? '');
                    $use_footballi = (strpos($source_url, 'football360.ir') === false)
                        && class_exists('F360LS_Footballi_Parser')
                        && F360LS_Footballi_Parser::looks_like($html, $source);
                    if ($use_footballi) {
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

        if (empty($payload['standings']) || empty($payload['matches'])) {
            $resolved = $this->resolve_remote_urls($league);
            foreach ($resolved['fallback'] as $kind => $url) {
                $html = $this->fetch_allowed_league_url($url);
                if (!$html) continue;
                try {
                    $use_footballi = class_exists('F360LS_Footballi_Parser') && F360LS_Footballi_Parser::looks_like($html, ['url' => $url]);
                    $data = $use_footballi ? (new F360LS_Footballi_Parser($html, ['url' => $url]))->parse($league) : (new F360LS_Parser($html))->parse();
                    $parsed_any = true;
                    $payload = $this->merge_payloads($payload, $data, ['type' => 'url', 'kind' => $kind, 'url' => $url, 'path' => '']);
                } catch (Throwable $e) {
                    continue;
                }
            }
        }
        $payload = $this->fill_league_table($payload, $league);
        $payload = $this->fill_league_statistics($payload, $league);
        $payload = $this->repair_payload($payload);

        if (!$parsed_any) {
            $payload['message'] = 'منبع لیگ قابل خواندن نبود. اگر از لینک مستقیم استفاده می‌کنید، مطمئن شوید سرور شما به سایت مرجع دسترسی دارد.';
            if (class_exists('F360LS_Logger')) F360LS_Logger::log('error', 'هیچ منبعی قابل parse نبود.', ['league_id' => $id]);
        }

        if (empty($payload['league']['title'])) {
            $payload['league']['title'] = $league['title'] ?? $league['id'];
        }

        if (empty($payload['league']['logo'])) {
            $catalog = $this->catalog_by_id($id);
            if (!empty($catalog['logo'])) $payload['league']['logo'] = $catalog['logo'];
        }
        $payload = $this->prefer_football360_logos($payload);
        $payload = $this->apply_logo_overrides($payload);
        $payload['stats'] = $this->build_stats($payload);
        if (empty($payload['last_update'])) {
            $payload['last_update'] = $this->fallback_last_update($league, $sources);
        }

        if (empty($payload['matches']) && empty($payload['standings']) && empty($payload['transfers']) && empty($payload['statistics']) && empty($payload['message'])) {
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

        $resolved = $this->resolve_remote_urls($league);
        $got_primary = false;
        foreach ($resolved['primary'] as $kind => $url) {
            $html = $this->fetch_allowed_league_url($url);
            if ($html) {
                $got_primary = true;
                $sources[] = ['type' => 'url', 'kind' => $kind, 'url' => $url, 'html' => $html, 'mtime' => time()];
            }
        }

        if (!$got_primary) {
            foreach ($resolved['fallback'] as $kind => $url) {
                $html = $this->fetch_allowed_league_url($url);
                if ($html) {
                    $sources[] = ['type' => 'url', 'kind' => $kind, 'url' => $url, 'html' => $html, 'mtime' => time()];
                }
            }
        }

        return $sources;
    }

    private function fetch_allowed_league_url(string $url): string {
        if (!$url || !wp_http_validate_url($url)) return '';
        if ($this->is_generic_mixed_feed($url)) return '';
        return $this->fetch_url($url);
    }

    private function is_generic_mixed_feed(string $url): bool {
        if (preg_match('~football360\\.ir/league/matches\\?id=[0-9a-f-]{36}~i', $url)) return false;
        if (preg_match('~football360\\.ir/league/statistics\\?id=[0-9a-f-]{36}~i', $url)) return false;
        if (preg_match('~football360\\.ir/league/table\\?id=[0-9a-f-]{36}~i', $url)) return false;
        if (preg_match('~football360\\.ir/league/table~i', $url) && !preg_match('~[?&]id=[0-9a-f-]{36}~i', $url)) return true;
        return (bool) preg_match('~footballi\\.net/live-scores/?(?:[?#].*)?$~i', $url)
            || (bool) preg_match('~football360\\.ir/(?:results|league/(?:table|statistics|matches|transfer))/?$~i', $url);
    }

    private function resolve_remote_urls(array $league): array {
        $catalog = $this->catalog_by_id((string) ($league['id'] ?? ''));
        $source = esc_url_raw((string) ($league['source_url'] ?? ''));
        $games = esc_url_raw((string) ($league['games_url'] ?? ''));
        $table = esc_url_raw((string) ($league['table_url'] ?? ''));
        $statistics = esc_url_raw((string) ($league['statistics_url'] ?? ''));
        $transfers = esc_url_raw((string) ($league['transfers_url'] ?? ''));
        $fallback = esc_url_raw((string) ($league['fallback_url'] ?? ''));

        if ($this->is_footballi_host($source) && empty($fallback)) $fallback = $source;
        if ($this->is_football360_host($source) && $this->is_generic_mixed_feed($source)) $source = '';

        $f360_base = '';
        if ($this->is_football360_host($source) && !$this->is_generic_mixed_feed($source)) {
            $f360_base = $this->football360_base($source);
        } elseif (!empty($catalog['url'])) {
            $f360_base = $this->football360_base($catalog['url']);
        }

        if (!$fallback && !empty($catalog['fallback'])) $fallback = $catalog['fallback'];
        if (!$fallback && $this->is_footballi_host($games)) $fallback = $games;

        $primary = [];
        if ($f360_base) {
            $table_feed = $this->catalog_table_url($catalog, $table);
            if ($table_feed) $primary['table_url'] = $table_feed;
            $primary['source_url'] = $f360_base;
            if (empty($primary['table_url'])) {
                $primary['table_url'] = ($table && $this->is_football360_host($table) && !$this->is_generic_mixed_feed($table)) ? $table : $f360_base;
            }
            $primary['transfers_url'] = ($transfers && $this->is_football360_host($transfers)) ? $transfers : ($f360_base . '/transfers');
            $schedule = $this->catalog_matches_url($catalog, $games);
            $primary['games_url'] = $schedule ?: (($games && $this->is_football360_host($games) && !$this->is_generic_mixed_feed($games)) ? $games : ($f360_base . '/games'));
            $primary['statistics_url'] = $this->catalog_statistics_url($catalog, $statistics, $f360_base, 'players');
            $primary['statistics_teams_url'] = $this->catalog_statistics_url($catalog, '', $f360_base, 'teams');
        } else {
            foreach (['source_url' => $source, 'games_url' => $games, 'table_url' => $table, 'statistics_url' => $statistics, 'transfers_url' => $transfers] as $kind => $url) {
                if ($url && $this->is_football360_host($url) && !$this->is_generic_mixed_feed($url)) $primary[$kind] = $url;
            }
        }

        $fallback_urls = [];
        if ($fallback && $this->is_footballi_host($fallback) && !$this->is_generic_mixed_feed($fallback)) {
            $fallback_urls['fallback_url'] = $fallback;
            $fallback_urls['fallback_table'] = rtrim($fallback, '/') . '/standing';
        }

        return [
            'primary' => $this->unique_url_map($primary),
            'fallback' => $this->unique_url_map($fallback_urls),
        ];
    }

    private function catalog_statistics_url(array $catalog, string $configured, string $base, string $kind): string {
        $kind = ($kind === 'teams') ? 'teams' : 'players';
        if ($configured && $this->is_football360_host($configured) && !$this->is_generic_mixed_feed($configured)) {
            if (preg_match('~/statistics(?:/players|/teams)?/?$~i', $configured)) {
                return rtrim(preg_replace('~/statistics(?:/players|/teams)?/?$~i', '', $configured), '/') . '/statistics/' . $kind;
            }
            return $configured;
        }
        $from_catalog = $this->football360_base((string) ($catalog['url'] ?? ''));
        $root = $base ?: $from_catalog;
        if (!$root) return '';
        return rtrim($root, '/') . '/statistics/' . $kind;
    }

    private function catalog_table_url(array $catalog, string $configured): string {
        if ($configured && $this->is_football360_host($configured) && preg_match('~league/table\\?id=([0-9a-f-]{36})~i', $configured, $m)) {
            return 'https://football360.ir/league/table?id=' . strtolower($m[1]) . '&query=full';
        }
        $id = strtolower(trim((string) ($catalog['matches_id'] ?? '')));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return 'https://football360.ir/league/table?id=' . $id . '&query=full';
        }
        return '';
    }

    private function catalog_matches_url(array $catalog, string $games): string {
        if ($games && $this->is_football360_host($games) && preg_match('~league/matches\\?id=([0-9a-f-]{36})~i', $games, $m)) {
            return 'https://football360.ir/league/matches?id=' . strtolower($m[1]);
        }
        $id = strtolower(trim((string) ($catalog['matches_id'] ?? '')));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return 'https://football360.ir/league/matches?id=' . $id;
        }
        return '';
    }

    private function unique_url_map(array $urls): array {
        $out = [];
        $seen = [];
        foreach ($urls as $kind => $url) {
            $url = esc_url_raw((string) $url);
            if (!$url) continue;
            $key = strtolower(rtrim($url, '/'));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[$kind] = $url;
        }
        return $out;
    }

    private function football360_base(string $url): string {
        $url = preg_replace('/[?#].*$/', '', trim($url));
        $url = preg_replace('~/(games|table|statistics(?:/players|/teams)?|transfers|transfer|matches|post)/?$~', '', $url);
        return rtrim((string) $url, '/');
    }

    private function is_football360_host(string $url): bool {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        return in_array($host, ['football360.ir', 'www.football360.ir', 'static.football360.ir'], true);
    }

    private function is_footballi_host(string $url): bool {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        return in_array($host, ['footballi.net', 'www.footballi.net', 'cdn.oddrun.ir'], true);
    }

    public function catalog_by_id(string $id): array {
        foreach ($this->catalog_leagues() as $item) {
            if (($item['id'] ?? '') === $id) return $item;
        }
        return [];
    }

    public function catalog_leagues(): array {
        return [
            ['id'=>'premier-league','matches_id'=>'e46149be-892c-4afa-adb2-61d116b3d723','title'=>'لیگ برتر انگلیس','url'=>'https://football360.ir/league/fcec7abb-dead-49c3-a907-1948e33fa438/20262027-Premier-League','fallback'=>'https://footballi.net/competition/9','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%84%DB%8C%DA%AF_%D8%A8%D8%B1_QZ0Pc5c.png'],
            ['id'=>'laliga','matches_id'=>'dda690d5-9e66-45ca-8ebb-e01a86e38fda','title'=>'لالیگا اسپانیا','url'=>'https://football360.ir/league/a0807949-8c10-42c3-a6c1-4a976faf2403/20262027-La-Liga','fallback'=>'https://footballi.net/competition/21','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2023/08/01/87.png'],
            ['id'=>'serie-a','matches_id'=>'bd566d88-e5b8-4ccf-aa77-c5044a2def92','title'=>'سری آ ایتالیا','url'=>'https://football360.ir/league/9b0bf5c1-a71a-4381-af8c-a8e17c832903/20262027-Serie-A','fallback'=>'https://footballi.net/competition/17','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D8%B3%D8%B1%DB%8C_%D8%A2__XRBG0Aj.png'],
            ['id'=>'bundesliga','matches_id'=>'c3667deb-67ab-4350-b6db-7cae14133dc3','title'=>'بوندس لیگای آلمان','url'=>'https://football360.ir/league/1f198942-2871-4759-a9b1-e89799b3b241/20262027-Bundesliga','fallback'=>'https://footballi.net/competition/12','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D8%A8%D9%88%D9%86%D8%AF%D8%B3%D9%84_8XGRH47.png'],
            ['id'=>'persian-gulf-pro-league','matches_id'=>'93187c91-9b17-492b-954c-cef238ca6a32','title'=>'لیگ برتر ایران','url'=>'https://football360.ir/league/a904ddf6-5df3-43b8-b5fb-15601e4a78ac/Persian-Gulf-20262027','fallback'=>'https://footballi.net/competition/14','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%84%DB%8C%DA%AF_%D8%A8%D8%B1_E1ywpuF.png'],
            ['id'=>'ligue-1','matches_id'=>'4108732f-b018-4cad-bb89-c01026ec6ac0','title'=>'لیگ 1 فرانسه','url'=>'https://football360.ir/league/860450b3-ec43-41ee-a54f-0c4f58154168/20262027-Ligue-1','fallback'=>'https://footballi.net/competition/11','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2024/08/11/leauge1.png'],
            ['id'=>'champions-league','matches_id'=>'43bc1231-96d9-47e3-b91d-22ae7c174273','title'=>'لیگ قهرمانان اروپا','url'=>'https://football360.ir/league/cee530a2-f168-4476-b4af-fed1aea1ec77/20262027-Champions-League','fallback'=>'https://footballi.net/competition/3','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%84%DB%8C%DA%AF_%D9%82%D9%87_YxUu6cD.png'],
            ['id'=>'europa-league','matches_id'=>'1f1cc616-8699-4571-80ec-560d7c955f5f','title'=>'لیگ اروپا','url'=>'https://football360.ir/league/9d57d1c4-7ee2-462d-9cb5-2d081bc491ce/20262027-Europa-League','fallback'=>'https://footballi.net/competition/4','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%84%DB%8C%DA%AF_%D8%A7%D8%B1%D9%88%D9%BE%D8%A7-min.png'],
            ['id'=>'conference-league','matches_id'=>'0729138a-ee0b-4cbf-bb85-2be187974501','title'=>'لیگ کنفرانس اروپا','url'=>'https://football360.ir/league/1460bb64-551c-4f02-a1f7-aad6966d2ec8/20262027-Europa-Conference-League','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%84%DB%8C%DA%AF_%DA%A9%D9%86_yKydPgj.png'],
            ['id'=>'elite-asia','matches_id'=>'51eb29d3-010e-40c8-a31c-8c64ed26468f','title'=>'لیگ نخبگان آسیا','url'=>'https://football360.ir/league/8fa68064-6620-49c9-85ba-61d5abef1804/20262027-AFC-Champions-League-Elite','fallback'=>'https://footballi.net/competition/25','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2024/07/27/AFC_ELITE.png'],
            ['id'=>'acl-two','matches_id'=>'30eacdcc-4e7a-4ccf-b89b-fc6126848bb0','title'=>'لیگ قهرمانان 2 آسیا','url'=>'https://football360.ir/league/49f36cc3-6398-484e-af71-dbe1a1cf4f34/20262027-AFC-Champions-League-Two','fallback'=>'https://footballi.net/competition/147','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2024/07/27/9469.png'],
            ['id'=>'championship','matches_id'=>'82211b99-330f-47e6-9c6a-98bd4c02392e','title'=>'چمپیونشیپ انگلیس','url'=>'https://football360.ir/league/7ba82869-3e89-40bd-b555-231344c867db/20262027-Championship','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2023/04/25/championship.png'],
            ['id'=>'bundesliga-2','title'=>'بوندس لیگای 2 آلمان','url'=>'','fallback'=>'','logo'=>''],
            ['id'=>'scottish-premiership','title'=>'پریمیرشیپ اسکاتلند','url'=>'','fallback'=>'','logo'=>''],
            ['id'=>'primeira-liga','matches_id'=>'15ff197d-7d50-4103-aaf5-0f10650798c6','title'=>'لیگ برتر پرتغال','url'=>'https://football360.ir/league/029ebb8a-e0a1-4d1a-9880-5294f5489a64/20262027-Liga-Portugal','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%84%DB%8C%DA%AF_%D8%A8%D8%B1_OwiYAfm.png'],
            ['id'=>'eredivisie','matches_id'=>'11514c78-2d93-4e17-95f2-60eb41ed9982','title'=>'اردیویسه هلند','url'=>'https://football360.ir/league/702eba10-ecda-4237-8be1-d651a144e92d/20262027-Eredivisie','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%84%DB%8C%DA%AF_%D8%A8%D8%B1_hPDSb6R.png'],
            ['id'=>'fa-cup','matches_id'=>'d50a7893-40d7-421c-a1d2-02a4368abc6d','title'=>'جام حذفی انگلیس','url'=>'https://football360.ir/league/1c838474-e568-47b5-89d9-cbff60f691d8/20262027-FA-Cup','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D8%AC%D8%A7%D9%85_%D8%AD%D8%B0_eS1Jvfr.png'],
            ['id'=>'efl-cup','matches_id'=>'b03dd5b3-05bd-432b-9a1d-b714622800df','title'=>'جام اتحادیه انگلیس','url'=>'https://football360.ir/league/8745632f-6fcd-423e-861c-11f4afa43970/20262027-Carabao-Cup','fallback'=>'https://footballi.net/competition/60','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2023/02/26/%D9%84%D9%88%DA%AF%D9%88_%D8%A7_AkjvxCj.png'],
            ['id'=>'dfb-pokal','matches_id'=>'8ca2e5ac-1ad1-48b4-b542-d4b2146b9485','title'=>'جام حذفی آلمان','url'=>'https://football360.ir/league/2314167d-652b-4feb-bbf0-03cc5d0bd0ab/20262027-DFB-Pokal','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D8%AC%D8%A7%D9%85_%D8%AD%D8%B0_MXRsDea.png'],
            ['id'=>'copa-del-rey','matches_id'=>'7ec49e0d-b7fe-4b4b-b0e1-a4c860571c45','title'=>'جام حذفی اسپانیا','url'=>'https://football360.ir/league/e217d643-8255-48a6-bdba-946057e1cd7f/20262027-Copa-Del-Rey','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D8%AC%D8%A7%D9%85_%D8%AC%D8%B0_8DD3oIf.png'],
            ['id'=>'coppa-italia','matches_id'=>'1f5aebd2-6ba7-46fa-80d7-bfedcbcbf187','title'=>'جام حذفی ایتالیا','url'=>'https://football360.ir/league/bf6c32b0-4bfb-4f77-8882-3d70086435b2/20262027-Coppa-Italia','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D8%AC%D8%A7%D9%85_%D8%AD%D8%B0_47rLyjY.png'],
            ['id'=>'coupe-de-france','matches_id'=>'470f6653-0328-4e5a-bdd4-754b9c2b5791','title'=>'جام حذفی فرانسه','url'=>'https://football360.ir/league/5078dfc1-74e0-419c-8d95-4eedee619c68/20262027-Coupe-de-France','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D8%AC%D8%A7%D9%85_%D8%AD%D8%B0_cFisMG2.png'],
            ['id'=>'saudi-pro-league','matches_id'=>'746045ee-289d-4b80-94aa-34a39d6a59c6','title'=>'لیگ برتر عربستان','url'=>'https://football360.ir/league/fc24c7c0-4fea-4765-9997-e626a3e831da/20262027-Pro-League','fallback'=>'https://footballi.net/competition/104','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2023/01/08/%D9%84%DB%8C%DA%AF_%D8%A8%D8%B1_GhhpvCj.png'],
            ['id'=>'uae-pro-league','matches_id'=>'b6fe4226-7ca3-4bdf-827c-352e34594d6e','title'=>'لیگ برتر امارات','url'=>'https://football360.ir/league/e9571733-6c36-46e7-8385-d31d20b886f3/20262027-UAE-Pro-League','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%84%DB%8C%DA%AF_%D8%A8%D8%B1_VXYwJwp.png'],
            ['id'=>'qatar-stars-league','matches_id'=>'c8cc7738-7e4c-47d4-afb1-0aeda7fcd4b2','title'=>'لیگ ستارگان قطر','url'=>'https://football360.ir/league/e18fdec7-75fe-4b29-bf48-5ff0c2dd7627/20262027-Stars-League','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%84%DB%8C%DA%AF_%D8%B3%D8%AA_otmL6Gk.png'],
            ['id'=>'world-cup-qualifying-asia','matches_id'=>'962729cb-b70a-48ae-8df2-255e0a577eb9','title'=>'انتخابی جام جهانی آسیا','url'=>'https://football360.ir/league/8b19325e-e692-44bb-b5b4-886d9c502094/2026-WC-Qualification-Asia','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2023/10/24/%D9%85%D9%82%D8%AF%D9%85%D8%A7%D8%AA_3A1m4lN.png'],
            ['id'=>'world-cup-qualifying-europe','matches_id'=>'c2453761-5552-4772-b784-18111335e799','title'=>'انتخابی جام جهانی اروپا','url'=>'https://football360.ir/league/ccb863ef-f736-469b-8790-9380c0fdd6f1/2030-WC-Qualification-Europe','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%85%D9%82%D8%AF%D9%85%D8%A7%D8%AA_UP7Grsn.png'],
            ['id'=>'world-cup-qualifying-africa','matches_id'=>'f47e382b-f652-437f-bfba-d8509fa5a5ed','title'=>'انتخابی جام جهانی آفریقا','url'=>'https://football360.ir/league/31092ead-62ba-4444-a0c8-420bb8e70992/2026-WC-Qualification-Africa','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%85%D9%82%D8%AF%D9%85%D8%A7%D8%AA_Z3pzMVj.png'],
            ['id'=>'world-cup-qualifying-south-america','matches_id'=>'31511519-1d26-4db8-ac77-152530716583','title'=>'انتخابی جام جهانی آمریکای جنوبی','url'=>'https://football360.ir/league/e26d856c-9d70-48f9-be8c-2e5df74507d5/2026-WC-Qualification-South-America','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2023/10/24/%D9%85%D9%82%D8%AF%D9%85%D8%A7%D8%AA_3A1m4lN.png'],
            ['id'=>'world-cup-qualifying-north-america','matches_id'=>'fb45d477-485c-4e34-be5e-fdc16ce716b5','title'=>'انتخابی جام جهانی آمریکای شمالی','url'=>'https://football360.ir/league/162185b8-8b59-4260-841f-7f05bf506453/2026-WC-Qualification-Concacaf','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%85%D9%82%D8%AF%D9%85%D8%A7%D8%AA_MKtMv3x.png'],
            ['id'=>'uefa-nations-league','matches_id'=>'905a4eef-b04a-4c61-ad09-19bfe96cbcc6','title'=>'لیگ ملت های اروپا','url'=>'https://football360.ir/league/aabf810f-7e63-48a4-a963-402518fdfb71/20262027-UEFA-Nations-League','fallback'=>'https://footballi.net/competition/83','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2022/12/20/%D9%84%DB%8C%DA%AF_%D9%85%D9%84_sVVrqSK.png'],
            ['id'=>'afc-asian-cup','matches_id'=>'81c3b609-dc85-4af2-add9-78d32820b365','title'=>'جام ملت های آسیا','url'=>'https://football360.ir/league/4c62308a-e813-46e0-b451-891a1ff7de66/2023-Asian-Cup','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2023/12/31/UI.png'],
            ['id'=>'uefa-euro','matches_id'=>'42bd547e-7c7b-4515-bc43-289132f5a2ba','title'=>'جام ملت های اروپا','url'=>'https://football360.ir/league/b3547cab-8434-4acb-bcdf-759525405a37/2024-European-Championship','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2024/06/02/X.png'],
            ['id'=>'africa-cup-of-nations','matches_id'=>'5f5e38b0-adfc-4f17-adbb-18c29563b674','title'=>'جام ملت های آفریقا','url'=>'https://football360.ir/league/b00db526-4618-4a53-b7db-ef28673f5126/2025-Africa-Cup-of-Nations','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2023/07/24/289.png'],
            ['id'=>'copa-america','matches_id'=>'8e9a19dc-ff5b-4609-a827-07830840a6ec','title'=>'کوپا آمریکا','url'=>'https://football360.ir/league/c51b8e00-a6e8-447c-97dc-c47fe628a751/2024-Copa-America','fallback'=>'','logo'=>'https://static.football360.ir/nesta2/media/uploads/competitions/2024/06/02/copa.png'],
        ];
    }

    private function fetch_url(string $url): string {
        if (!$this->is_url_allowed($url)) {
            if (class_exists('F360LS_Logger')) F360LS_Logger::log('warning', 'دامنه منبع در لیست مجاز نیست.', ['url' => $url]);
            return '';
        }
        $args = [
            'timeout' => 12,
            'redirection' => 5,
            'sslverify' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; F360LeagueScorePro/' . F360LS_VERSION . '; WordPress)',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'fa-IR,fa;q=0.9,en-US;q=0.6,en;q=0.5',
            ],
        ];
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
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
        $allowed = $settings['allowed_domains'] ?? "footballi.net\nfootball360.ir\ncdn.oddrun.ir\nstatic.football360.ir";
        $domains = array_filter(array_map('trim', preg_split('/\R+|,/', (string) $allowed)));
        foreach ($domains as $domain) {
            $domain = strtolower(ltrim($domain, '.'));
            if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) return true;
        }
        return false;
    }

    private function merge_payloads(array $base, array $data, array $source): array {
        $from_f360 = $this->is_football360_host((string) ($source['url'] ?? '')) || strpos((string) ($source['url'] ?? ''), 'football360.ir') !== false;
        $stats_only = (bool) preg_match('/statistics/i', (string) ($source['kind'] ?? ''))
            || (bool) preg_match('~/statistics(?:/players|/teams)?(?:[?#].*)?$~i', (string) ($source['url'] ?? ''));
        $table_feed = (($source['kind'] ?? '') === 'table_url')
            || (bool) preg_match('~/league/table~i', (string) ($source['url'] ?? ''));

        if (!$stats_only && !$table_feed && !empty($data['league']['title']) && (empty($base['league']['title']) || $source['kind'] !== 'derived_games_url')) {
            $base['league']['title'] = $data['league']['title'];
        }
        if (!empty($data['league']['logo']) && (empty($base['league']['logo']) || ($from_f360 && $this->is_football360_logo($data['league']['logo'])))) {
            $base['league']['logo'] = $data['league']['logo'];
        }
        if (!empty($data['last_update'])) {
            $base['last_update'] = $data['last_update'];
        }
        if (!empty($data['description']) && empty($base['description'])) {
            $base['description'] = $data['description'];
        }

        if (!$stats_only && !empty($data['standings'])) {
            $incoming = $data['standings'];
            $incoming_ok = count($incoming) >= 2 && !$this->standings_look_cloned($incoming);
            $current_bad = empty($base['standings']) || $this->standings_look_cloned($base['standings'] ?? []);
            if ($table_feed && $incoming_ok) {
                $base['standings'] = $this->dedupe_standings($incoming);
            } elseif ($current_bad && $incoming_ok) {
                $base['standings'] = $this->dedupe_standings($incoming);
            } else {
                $base['standings'] = $this->dedupe_standings(array_merge($base['standings'] ?? [], $incoming));
            }
        }
        if (!$stats_only && !$table_feed && !empty($data['matches'])) {
            $base['matches'] = $this->dedupe_matches(array_merge($base['matches'] ?? [], $data['matches']));
        }
        if (!$stats_only && !$table_feed && !empty($data['weeks'])) {
            $base['weeks'] = $this->merge_weeks($base['weeks'] ?? [], $data['weeks']);
        }
        if (!$stats_only && !$table_feed && !empty($data['news'])) {
            $base['news'] = $this->dedupe_items(array_merge($base['news'] ?? [], $data['news']), ['href','title']);
        }
        if (!empty($data['top_scorers'])) {
            $base['top_scorers'] = $this->dedupe_items(array_merge($base['top_scorers'] ?? [], $data['top_scorers']), ['name','goals']);
        }
        if (!empty($data['statistics'])) {
            $base['statistics'] = $this->merge_statistics($base['statistics'] ?? [], $data['statistics']);
        }
        if (!$stats_only && !$table_feed && !empty($data['transfers'])) {
            $base['transfers'] = $this->dedupe_items(array_merge($base['transfers'] ?? [], $data['transfers']), ['player','from','to','type']);
        }

        $base['sources'][] = [
            'type' => $source['type'] ?? '',
            'kind' => $source['kind'] ?? '',
            'url' => $source['url'] ?? '',
            'path' => $source['path'] ?? '',
        ];
        return $base;
    }

    private function merge_statistics(array $old, array $new): array {
        $by_key = [];
        foreach (array_merge($old, $new) as $group) {
            if (!is_array($group)) continue;
            $title = trim((string) ($group['title'] ?? ''));
            $kind = sanitize_key((string) ($group['kind'] ?? 'player')) ?: 'player';
            $key = sanitize_key((string) ($group['key'] ?? ''));
            if (!$key) $key = ($kind === 'team' ? 'team_' : 'stat_') . substr(md5($title !== '' ? $title : serialize($group['rows'] ?? [])), 0, 12);
            $rows = $this->dedupe_items(array_merge($by_key[$key]['rows'] ?? [], $group['rows'] ?? []), ['name','value']);
            if (!$rows) continue;
            $by_key[$key] = [
                'key' => $key,
                'kind' => $kind,
                'title' => $title !== '' ? $title : ($by_key[$key]['title'] ?? $key),
                'rows' => $rows,
            ];
        }
        return array_values($by_key);
    }

    private function merge_weeks(array $old, array $new): array {
        foreach ($new as $week) {
            $matches = $this->dedupe_matches($week['matches'] ?? []);
            if (!$matches) continue;
            $old[] = ['title' => $week['title'] ?? 'بازی‌ها', 'matches' => $matches];
        }
        $seen = [];
        foreach ($old as $wi => $week) {
            $clean = [];
            foreach (($week['matches'] ?? []) as $m) {
                $href = (string) ($m['href'] ?? '');
                $key = $href !== '' ? ('href:' . md5($href)) : md5(($m['home'] ?? '') . '|' . ($m['away'] ?? '') . '|' . ($m['score'] ?? '') . '|' . ($m['status'] ?? ''));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $clean[] = $m;
            }
            $old[$wi]['matches'] = $clean;
        }
        $numbered = array_values(array_filter($old, fn($w) => preg_match('/هفته\\s*\\d+/u', (string) ($w['title'] ?? '')) && !empty($w['matches'])));
        if (count($numbered) >= 2) return $numbered;
        return array_values(array_filter($old, fn($w) => !empty($w['matches'])));
    }

    private function dedupe_matches(array $matches): array {
        $out = [];
        $positions = [];
        foreach ($matches as $m) {
            $key = md5(mb_strtolower(trim(($m['home'] ?? '') . '|' . ($m['away'] ?? '')), 'UTF-8'));
            if (!isset($positions[$key])) {
                $positions[$key] = count($out);
                $out[] = $m;
                continue;
            }
            $current = $out[$positions[$key]];
            $current_live = (($current['status_type'] ?? '') === 'live');
            $incoming_live = (($m['status_type'] ?? '') === 'live');
            if ($incoming_live && !$current_live) {
                $out[$positions[$key]] = $m;
            } elseif (($current['score'] ?? '—') === '—' && ($m['score'] ?? '—') !== '—') {
                $out[$positions[$key]] = $m;
            } elseif ($this->is_football360_logo($m['home_logo'] ?? '') || $this->is_football360_logo($m['away_logo'] ?? '')) {
                if (empty($current['home_logo']) || $this->is_football360_logo($m['home_logo'] ?? '')) $current['home_logo'] = $m['home_logo'] ?? $current['home_logo'];
                if (empty($current['away_logo']) || $this->is_football360_logo($m['away_logo'] ?? '')) $current['away_logo'] = $m['away_logo'] ?? $current['away_logo'];
                $out[$positions[$key]] = $current;
            }
        }
        return $out;
    }

    private function dedupe_standings(array $rows): array {
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            if (empty($r['team'])) continue;
            $key = md5(mb_strtolower(trim((string) ($r['team'] ?? '')), 'UTF-8'));
            if (isset($seen[$key])) {
                $idx = $seen[$key];
                if (empty($out[$idx]['logo']) || $this->is_football360_logo($r['logo'] ?? '')) {
                    if (!empty($r['logo'])) $out[$idx]['logo'] = $r['logo'];
                }
                continue;
            }
            $seen[$key] = count($out);
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

    private function is_football360_logo(string $url): bool {
        $url = strtolower(trim($url));
        return $url !== '' && (strpos($url, 'football360.ir') !== false || strpos($url, 'static.football360') !== false);
    }

    private function prefer_football360_logos(array $payload): array {
        $map = [];
        foreach (($payload['standings'] ?? []) as $row) {
            if (!empty($row['team']) && $this->is_football360_logo((string) ($row['logo'] ?? ''))) {
                $map[$this->logo_key($row['team'])] = $row['logo'];
            }
        }
        foreach (($payload['matches'] ?? []) as $match) {
            if (!empty($match['home']) && $this->is_football360_logo((string) ($match['home_logo'] ?? ''))) $map[$this->logo_key($match['home'])] = $match['home_logo'];
            if (!empty($match['away']) && $this->is_football360_logo((string) ($match['away_logo'] ?? ''))) $map[$this->logo_key($match['away'])] = $match['away_logo'];
        }
        foreach (($payload['standings'] ?? []) as $i => $row) {
            $key = $this->logo_key((string) ($row['team'] ?? ''));
            if ($key && !empty($map[$key])) $payload['standings'][$i]['logo'] = $map[$key];
        }
        foreach (($payload['matches'] ?? []) as $i => $match) {
            $home_key = $this->logo_key((string) ($match['home'] ?? ''));
            $away_key = $this->logo_key((string) ($match['away'] ?? ''));
            if ($home_key && !empty($map[$home_key])) $payload['matches'][$i]['home_logo'] = $map[$home_key];
            if ($away_key && !empty($map[$away_key])) $payload['matches'][$i]['away_logo'] = $map[$away_key];
        }
        foreach (($payload['weeks'] ?? []) as $wi => $week) {
            foreach (($week['matches'] ?? []) as $mi => $match) {
                $home_key = $this->logo_key((string) ($match['home'] ?? ''));
                $away_key = $this->logo_key((string) ($match['away'] ?? ''));
                if ($home_key && !empty($map[$home_key])) $payload['weeks'][$wi]['matches'][$mi]['home_logo'] = $map[$home_key];
                if ($away_key && !empty($map[$away_key])) $payload['weeks'][$wi]['matches'][$mi]['away_logo'] = $map[$away_key];
            }
        }
        foreach (($payload['transfers'] ?? []) as $i => $row) {
            $from_key = $this->logo_key((string) ($row['from'] ?? ''));
            $to_key = $this->logo_key((string) ($row['to'] ?? ''));
            if ($from_key && !empty($map[$from_key])) $payload['transfers'][$i]['from_logo'] = $map[$from_key];
            if ($to_key && !empty($map[$to_key])) $payload['transfers'][$i]['to_logo'] = $map[$to_key];
        }
        return $payload;
    }

    private function logo_key(string $name): string {
        return mb_strtolower(preg_replace('/\s+/u', '', trim($name)), 'UTF-8');
    }

    private function apply_logo_overrides(array $payload): array {
        $overrides = get_option(F360LS_OPTION_LOGO_OVERRIDES, []);
        if (!is_array($overrides)) return $payload;
        $league_id = $payload['id'] ?? '';
        if (!empty($overrides['leagues'][$league_id])) $payload['league']['logo'] = $overrides['leagues'][$league_id];
        foreach (['standings'] as $key) foreach (($payload[$key] ?? []) as $i => $row) if (!empty($overrides['teams'][$row['team'] ?? ''])) $payload[$key][$i]['logo'] = $overrides['teams'][$row['team']];
        foreach (['matches'] as $key) foreach (($payload[$key] ?? []) as $i => $match) { if (!empty($overrides['teams'][$match['home'] ?? ''])) $payload[$key][$i]['home_logo']=$overrides['teams'][$match['home']]; if (!empty($overrides['teams'][$match['away'] ?? ''])) $payload[$key][$i]['away_logo']=$overrides['teams'][$match['away']]; }
        foreach (($payload['weeks'] ?? []) as $wi => $week) foreach (($week['matches'] ?? []) as $mi => $match) { if (!empty($overrides['teams'][$match['home'] ?? ''])) $payload['weeks'][$wi]['matches'][$mi]['home_logo']=$overrides['teams'][$match['home']]; if (!empty($overrides['teams'][$match['away'] ?? ''])) $payload['weeks'][$wi]['matches'][$mi]['away_logo']=$overrides['teams'][$match['away']]; }
        foreach (($payload['transfers'] ?? []) as $i => $row) {
            if (!empty($overrides['teams'][$row['from'] ?? ''])) $payload['transfers'][$i]['from_logo'] = $overrides['teams'][$row['from']];
            if (!empty($overrides['teams'][$row['to'] ?? ''])) $payload['transfers'][$i]['to_logo'] = $overrides['teams'][$row['to']];
        }
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
            'has_transfers' => !empty($payload['transfers']),
            'has_statistics' => !empty($payload['statistics']) || !empty($payload['top_scorers']),
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

    private function repair_payload(array $payload): array {
        $payload = $this->rebuild_cloned_standings($payload);
        $payload = $this->ensure_week_pages($payload);
        $payload = $this->sanitize_statistic_teams($payload);
        return $payload;
    }

    private function rebuild_cloned_standings(array $payload): array {
        $have = $payload['standings'] ?? [];
        $cloned = $this->standings_look_cloned($have);
        if (!$cloned && count($have) >= 3) return $payload;
        $finished = [];
        foreach ($payload['matches'] ?? [] as $m) {
            if (($m['status_type'] ?? '') !== 'finished') continue;
            if (!preg_match('/(\\d+)\\s*[-–]\\s*(\\d+)/u', (string) ($m['score'] ?? ''), $s)) continue;
            $m['_hg'] = (int) $s[1];
            $m['_ag'] = (int) $s[2];
            $finished[] = $m;
        }
        if (!$finished) return $payload;
        $teams = [];
        $touch = function(string $name, string $logo) use (&$teams) {
            $key = $this->logo_key($name);
            if ($key === '') return;
            if (!isset($teams[$key])) {
                $teams[$key] = ['team' => $name, 'logo' => $logo, 'played' => 0, 'won' => 0, 'draw' => 0, 'lost' => 0, 'gf' => 0, 'ga' => 0, 'points' => 0];
            } elseif ($logo && empty($teams[$key]['logo'])) {
                $teams[$key]['logo'] = $logo;
            }
        };
        foreach ($have as $row) {
            if (!empty($row['team'])) $touch((string) $row['team'], (string) ($row['logo'] ?? ''));
        }
        foreach ($finished as $m) {
            $touch((string) ($m['home'] ?? ''), (string) ($m['home_logo'] ?? ''));
            $touch((string) ($m['away'] ?? ''), (string) ($m['away_logo'] ?? ''));
            $hk = $this->logo_key((string) ($m['home'] ?? ''));
            $ak = $this->logo_key((string) ($m['away'] ?? ''));
            if (!isset($teams[$hk], $teams[$ak])) continue;
            $hg = $m['_hg'];
            $ag = $m['_ag'];
            $teams[$hk]['played']++;
            $teams[$ak]['played']++;
            $teams[$hk]['gf'] += $hg;
            $teams[$hk]['ga'] += $ag;
            $teams[$ak]['gf'] += $ag;
            $teams[$ak]['ga'] += $hg;
            if ($hg > $ag) {
                $teams[$hk]['won']++;
                $teams[$hk]['points'] += 3;
                $teams[$ak]['lost']++;
            } elseif ($ag > $hg) {
                $teams[$ak]['won']++;
                $teams[$ak]['points'] += 3;
                $teams[$hk]['lost']++;
            } else {
                $teams[$hk]['draw']++;
                $teams[$ak]['draw']++;
                $teams[$hk]['points']++;
                $teams[$ak]['points']++;
            }
        }
        $rows = array_values($teams);
        usort($rows, function($a, $b) {
            if ($a['points'] !== $b['points']) return $b['points'] <=> $a['points'];
            $ad = $a['gf'] - $a['ga'];
            $bd = $b['gf'] - $b['ga'];
            if ($ad !== $bd) return $bd <=> $ad;
            return $b['gf'] <=> $a['gf'];
        });
        $out = [];
        $rank = 0;
        foreach ($rows as $r) {
            $rank++;
            $out[] = [
                'rank' => (string) $rank,
                'team' => $r['team'],
                'logo' => $r['logo'],
                'played' => (string) $r['played'],
                'won' => (string) $r['won'],
                'draw' => (string) $r['draw'],
                'lost' => (string) $r['lost'],
                'diff' => (string) ($r['gf'] - $r['ga']),
                'goals' => $r['ga'] . '-' . $r['gf'],
                'points' => (string) $r['points'],
                'movement' => 'equal',
            ];
        }
        if (count($out) >= 2 && !$this->standings_look_cloned($out)) {
            $payload['standings'] = $out;
        }
        return $payload;
    }

    private function ensure_week_pages(array $payload): array {
        $weeks = $payload['weeks'] ?? [];
        $numbered = array_values(array_filter($weeks, function($w) {
            return preg_match('/هفته\\s*\\d+/u', (string) ($w['title'] ?? '')) && !empty($w['matches']);
        }));
        if (count($numbered) >= 2) {
            $payload['weeks'] = $numbered;
            return $payload;
        }
        $matches = $payload['matches'] ?? [];
        if (count($matches) < 2) return $payload;
        $by_week = [];
        foreach ($matches as $m) {
            $label = (string) ($m['date_label'] ?? '');
            $week_no = 0;
            if (preg_match('/هفته\\s*(\\d+)/u', $label, $wm)) $week_no = (int) $wm[1];
            if ($week_no < 1) {
                foreach ($weeks as $w) {
                    if (preg_match('/هفته\\s*(\\d+)/u', (string) ($w['title'] ?? ''), $wm2)) {
                        foreach ($w['matches'] ?? [] as $wm) {
                            if (($wm['href'] ?? '') !== '' && ($wm['href'] ?? '') === ($m['href'] ?? '')) {
                                $week_no = (int) $wm2[1];
                                break 2;
                            }
                        }
                    }
                }
            }
            $key = $week_no > 0 ? $week_no : 0;
            $by_week[$key][] = $m;
        }
        if (isset($by_week[0]) && count($by_week) === 1) {
            $per = count($payload['standings'] ?? []) >= 10 ? (int) max(6, intdiv(count($payload['standings']), 2)) : 10;
            $chunks = array_chunk($matches, $per);
            $payload['weeks'] = [];
            foreach ($chunks as $i => $chunk) {
                $payload['weeks'][] = ['title' => 'هفته ' . ($i + 1), 'matches' => $chunk];
            }
            return $payload;
        }
        ksort($by_week, SORT_NUMERIC);
        $out = [];
        foreach ($by_week as $num => $ms) {
            if (!$ms) continue;
            $out[] = ['title' => $num > 0 ? ('هفته ' . $num) : 'بازی‌ها', 'matches' => $ms];
        }
        if (count($out) >= 2) $payload['weeks'] = $out;
        return $payload;
    }

    private function sanitize_statistic_teams(array $payload): array {
        foreach ($payload['statistics'] ?? [] as $gi => $group) {
            $rows = $group['rows'] ?? [];
            if (count($rows) < 2) continue;
            $names = [];
            foreach ($rows as $row) $names[] = mb_strtolower((string) ($row['name'] ?? ''), 'UTF-8');
            $team_counts = [];
            foreach ($rows as $row) {
                $team = trim((string) ($row['team'] ?? ''));
                if ($team === '') continue;
                $team_counts[$team] = ($team_counts[$team] ?? 0) + 1;
            }
            $wipe = false;
            if ($team_counts) {
                arsort($team_counts);
                $top = (string) array_key_first($team_counts);
                if ($top !== '' && $team_counts[$top] >= max(3, (int) ceil(count($rows) / 2)) && in_array(mb_strtolower($top, 'UTF-8'), $names, true)) {
                    $wipe = true;
                }
            }
            foreach ($rows as $ri => $row) {
                $team = trim((string) ($row['team'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));
                if ($wipe || $team === '' || mb_strtolower($team, 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                    $payload['statistics'][$gi]['rows'][$ri]['team'] = '';
                }
            }
        }
        return $payload;
    }

    private function standings_look_cloned(array $rows): bool {
        if (count($rows) < 3) return false;
        $sigs = [];
        foreach ($rows as $row) {
            $sigs[] = ($row['played'] ?? '') . '|' . ($row['won'] ?? '') . '|' . ($row['draw'] ?? '') . '|' . ($row['lost'] ?? '') . '|' . ($row['points'] ?? '') . '|' . ($row['goals'] ?? '');
        }
        return count(array_unique($sigs)) === 1;
    }

    private function fill_league_table(array $payload, array $league): array {
        $catalog = $this->catalog_by_id((string) ($league['id'] ?? ''));
        $url = $this->catalog_table_url($catalog, (string) ($league['table_url'] ?? ''));
        if (!$url) return $payload;
        $have = $payload['standings'] ?? [];
        if (count($have) >= 3 && !$this->standings_look_cloned($have)) return $payload;
        $html = $this->fetch_url($url);
        if (!$html) return $payload;
        try {
            $data = (new F360LS_Parser($html))->parse();
        } catch (Throwable $e) {
            return $payload;
        }
        if (empty($data['standings']) || $this->standings_look_cloned($data['standings'])) return $payload;
        return $this->merge_payloads($payload, $data, ['type' => 'url', 'kind' => 'table_url', 'url' => $url, 'path' => '']);
    }

    private function fill_league_statistics(array $payload, array $league): array {
        $seen = [];
        foreach ($payload['sources'] ?? [] as $source) {
            $url = strtolower(rtrim((string) ($source['url'] ?? ''), '/'));
            if ($url !== '') $seen[$url] = true;
        }
        $catalog = $this->catalog_by_id((string) ($league['id'] ?? ''));
        $base = $this->football360_base((string) ($catalog['url'] ?? ($league['source_url'] ?? '')));
        $urls = [];
        if ($base) {
            $urls[] = ['url' => rtrim($base, '/') . '/statistics/players', 'kind' => 'statistics_players'];
            $urls[] = ['url' => rtrim($base, '/') . '/statistics/teams', 'kind' => 'statistics_teams'];
        }
        if ((string) ($league['id'] ?? '') === 'persian-gulf-pro-league') {
            $urls[] = ['url' => 'https://football360.ir/league/statistics', 'kind' => 'statistics_generic'];
        }
        foreach ($urls as $item) {
            $url = $item['url'];
            $key = strtolower(rtrim($url, '/'));
            if (isset($seen[$key])) continue;
            $html = $this->fetch_url($url);
            if (!$html) continue;
            try {
                $data = (new F360LS_Parser($html))->parse();
            } catch (Throwable $e) {
                continue;
            }
            if (empty($data['statistics']) && empty($data['top_scorers'])) continue;
            $payload = $this->merge_payloads($payload, $data, ['type' => 'url', 'kind' => $item['kind'], 'url' => $url, 'path' => '']);
            $seen[$key] = true;
        }
        return $payload;
    }

    private function merge_live_results(array $payload): array {
        $teams = [];
        foreach (($payload['standings'] ?? []) as $row) {
            $key = $this->logo_key((string) ($row['team'] ?? ''));
            if ($key) $teams[$key] = true;
        }
        foreach (($payload['matches'] ?? []) as $match) {
            $home = $this->logo_key((string) ($match['home'] ?? ''));
            $away = $this->logo_key((string) ($match['away'] ?? ''));
            if ($home) $teams[$home] = true;
            if ($away) $teams[$away] = true;
        }
        if (!$teams) return $payload;
        $html = $this->fetch_url('https://football360.ir/results');
        if (!$html) return $payload;
        try {
            $data = (new F360LS_Parser($html))->parse();
        } catch (Throwable $e) {
            return $payload;
        }
        $picked = [];
        foreach (($data['matches'] ?? []) as $match) {
            $home = $this->logo_key((string) ($match['home'] ?? ''));
            $away = $this->logo_key((string) ($match['away'] ?? ''));
            if (!isset($teams[$home]) && !isset($teams[$away])) continue;
            $picked[] = $match;
        }
        if (!$picked) return $payload;
        return $this->merge_payloads($payload, [
            'matches' => $picked,
            'weeks' => [['title' => 'امروز', 'matches' => $picked]],
        ], ['type' => 'url', 'kind' => 'live_results', 'url' => 'https://football360.ir/results', 'path' => '']);
    }

    public function empty_payload(array $league, string $message = ''): array {
        return [
            'id' => $league['id'] ?? '',
            'configured_title' => $league['title'] ?? '',
            'league' => ['title' => $league['title'] ?? '', 'logo' => $league['logo'] ?? ''],
            'subtitle' => $league['subtitle'] ?? '',
            'weeks' => [],
            'matches' => [],
            'standings' => [],
            'top_scorers' => [],
            'statistics' => [],
            'transfers' => [],
            'news' => [],
            'last_update' => '',
            'description' => '',
            'stats' => ['total'=>0,'finished'=>0,'live'=>0,'scheduled'=>0,'teams'=>0,'has_matches'=>false,'has_table'=>false,'has_transfers'=>false,'has_statistics'=>false],
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
