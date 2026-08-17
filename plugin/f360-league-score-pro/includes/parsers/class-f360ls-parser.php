<?php
if (!defined('ABSPATH')) { exit; }

class F360LS_Parser {
    private string $html;

    public function __construct($html) {
        $this->html = (string) $html;
    }

    public function parse(): array {
        if (!class_exists('DOMDocument')) {
            return $this->parse_with_regex();
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $html = $this->html;
        if (function_exists('mb_convert_encoding')) {
            $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        }
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $league = $this->parse_league_meta($xpath);
        $standings = $this->parse_standings($xpath);
        $weeks = $this->parse_match_weeks($xpath);

        $flat = [];
        foreach ($weeks as $week) {
            foreach ($week['matches'] as $m) {
                $flat[] = $m;
            }
        }

        $last_update = $this->text($xpath, "//*[contains(@class,'style_lastUpdate__')][1]", $dom->documentElement);
        $description = $this->text($xpath, "//*[contains(@class,'style_descBody__')][1]", $dom->documentElement);

        return $this->make_payload($league, $weeks, $flat, $standings, $last_update, $description);
    }

    private function parse_league_meta(DOMXPath $xpath): array {
        $title = $this->text($xpath, "//h1[contains(@class,'style_title__')][1] | //h1[1]", null);
        $logo = $this->attr($xpath, "//*[contains(@class,'style_header__') or contains(@class,'style_headerContainer__')]//img[1]", 'src', null);
        if (!$logo) {
            $logo = $this->attr($xpath, "//*[contains(@class,'style_header__') or contains(@class,'style_headerContainer__')]//img[1]", 'srcset', null);
            $logo = $this->first_src_from_srcset($logo);
        }

        return [
            'title' => $this->clean($title),
            'logo'  => $this->normalize_src($logo),
        ];
    }

    private function parse_standings(DOMXPath $xpath): array {
        $rows = $xpath->query("//div[contains(@class,'style_containerStandingTable__')]//tbody/tr | //table//tbody/tr[.//*[contains(@class,'style_name__')]]");
        $standings = [];

        if (!$rows || !$rows->length) {
            return [];
        }

        foreach ($rows as $row) {
            $rank = $this->text($xpath, ".//*[contains(@class,'style_number__')][1]", $row);
            $team = $this->text($xpath, ".//*[contains(@class,'style_name__')][1]", $row);
            if (!$team) {
                $team = $this->text($xpath, ".//td[1]//a[1]", $row);
                $team = preg_replace('/^\s*\d+\s*/u', '', $team);
            }

            if (!$team) { continue; }

            $logo = $this->attr($xpath, ".//img[1]", 'src', $row);
            if (!$logo) {
                $logo = $this->attr($xpath, ".//img[1]", 'srcset', $row);
                $logo = $this->first_src_from_srcset($logo);
            }

            $cells = [];
            $tds = $xpath->query("./td", $row);
            if ($tds) {
                foreach ($tds as $td) {
                    $txt = $this->clean($td->textContent);
                    if ($txt !== '') $cells[] = $txt;
                }
            }

            // Football360 table order: team | badge | played | won | draw | lost | diff | goals | points
            $numeric = [];
            foreach ($cells as $idx => $cell) {
                if ($idx === 0) continue;
                $cell = $this->clean($cell);
                if ($cell !== '' && !preg_match('/^[آ-یA-Za-z\s]+$/u', $cell)) {
                    $numeric[] = $cell;
                }
            }

            $played = $numeric[0] ?? '';
            $won    = $numeric[1] ?? '';
            $draw   = $numeric[2] ?? '';
            $lost   = $numeric[3] ?? '';
            $diff   = $numeric[4] ?? '';
            $goals  = $numeric[5] ?? '';
            $points = $numeric[6] ?? ($this->text($xpath, ".//*[contains(@class,'style_boldLastChild__')][1]", $row));

            if (!$rank && preg_match('/^\s*(\d+)/u', $cells[0] ?? '', $m)) {
                $rank = $m[1];
            }

            $movement = 'equal';
            $icon = $this->attr($xpath, ".//i[1]", 'class', $row);
            if (strpos($icon, 'up') !== false) $movement = 'up';
            if (strpos($icon, 'down') !== false) $movement = 'down';

            $standings[] = [
                'rank' => $this->clean($rank),
                'team' => $this->clean($team),
                'logo' => $this->normalize_src($logo),
                'played' => $played,
                'won' => $won,
                'draw' => $draw,
                'lost' => $lost,
                'diff' => $diff,
                'goals' => $goals,
                'points' => $points,
                'movement' => $movement,
            ];
        }

        return $standings;
    }

    private function parse_match_weeks(DOMXPath $xpath): array {
        $sections = $xpath->query("//*[contains(@class,'LeagueMatches_section__') or contains(@class,'LeagueMatches_sectionHome__')]");
        $weeks = [];

        if ($sections && $sections->length) {
            foreach ($sections as $section) {
                $title = $this->text($xpath, ".//*[contains(@class,'LeagueMatches_title__') or contains(@class,'LeagueMatches_header__') or self::h2 or self::h3][1]", $section);
                $items = $xpath->query(".//li[.//*[contains(@class,'style_HomeTeam__')] and .//*[contains(@class,'style_AwayTeam__')]] | .//a[contains(@class,'style_MatchItem__')]", $section);
                $matches = $this->parse_items($xpath, $items);
                if ($matches) {
                    $weeks[] = ['title' => $title ?: 'بازی‌ها', 'matches' => $matches];
                }
            }
        }

        if (empty($weeks)) {
            $items = $xpath->query("//a[contains(@class,'style_MatchItem__') or (.//*[contains(@class,'style_HomeTeam__')] and .//*[contains(@class,'style_AwayTeam__')])] | //li[.//*[contains(@class,'style_HomeTeam__')] and .//*[contains(@class,'style_AwayTeam__')]]");
            $matches = $this->parse_items($xpath, $items);
            if ($matches) {
                $weeks[] = ['title' => 'همه بازی‌ها', 'matches' => $matches];
            }
        }

        return $weeks;
    }

    private function parse_items(DOMXPath $xpath, $items): array {
        $matches = [];
        if (!$items) { return $matches; }

        foreach ($items as $node) {
            $homeNode = $this->first($xpath, ".//*[contains(@class,'style_HomeTeam__')]", $node);
            $awayNode = $this->first($xpath, ".//*[contains(@class,'style_AwayTeam__')]", $node);
            if (!$homeNode || !$awayNode) continue;

            $home = $this->text($xpath, ".//*[contains(@class,'style_title__')][1]", $homeNode) ?: trim($homeNode->textContent);
            $away = $this->text($xpath, ".//*[contains(@class,'style_title__')][1]", $awayNode) ?: trim($awayNode->textContent);
            $score = $this->text($xpath, ".//*[contains(@class,'style_match__')][1]", $node);
            $status = $this->text($xpath, ".//*[contains(@class,'style_date__')][1]", $node);
            $time = $this->text($xpath, ".//*[contains(@class,'style_time__')][1]", $node);
            if (!$status && $time) $status = $time;

            $href = '';
            if ($node instanceof DOMElement && $node->hasAttribute('href')) {
                $href = $node->getAttribute('href');
            }
            if (!$href) $href = $this->attr($xpath, ".//a[1]", 'href', $node);

            $homeLogo = $this->attr($xpath, ".//*[contains(@class,'style_HomeTeam__')]//img[1]", 'src', $node);
            $awayLogo = $this->attr($xpath, ".//*[contains(@class,'style_AwayTeam__')]//img[1]", 'src', $node);
            if (!$homeLogo) $homeLogo = $this->first_src_from_srcset($this->attr($xpath, ".//*[contains(@class,'style_HomeTeam__')]//img[1]", 'srcset', $node));
            if (!$awayLogo) $awayLogo = $this->first_src_from_srcset($this->attr($xpath, ".//*[contains(@class,'style_AwayTeam__')]//img[1]", 'srcset', $node));

            $home = $this->clean_team($home);
            $away = $this->clean_team($away);
            if (!$home || !$away) continue;

            $matches[] = [
                'home' => $home,
                'away' => $away,
                'score' => trim($score) ?: '—',
                'status' => trim($status) ?: ($score ? 'پایان' : 'زمان نامشخص'),
                'status_type' => $this->status_type($status, $score),
                'home_logo' => $this->normalize_src($homeLogo),
                'away_logo' => $this->normalize_src($awayLogo),
                'href' => $this->normalize_href($href),
            ];
        }

        return $matches;
    }

    private function make_payload(array $league, array $weeks, array $matches, array $standings, string $last_update = '', string $description = ''): array {
        return [
            'league' => $league,
            'weeks' => $weeks,
            'matches' => $matches,
            'standings' => $standings,
            'last_update' => $this->clean($last_update),
            'description' => $this->clean($description),
            'stats' => [
                'total' => count($matches),
                'finished' => count(array_filter($matches, fn($m) => ($m['status_type'] ?? '') === 'finished')),
                'live' => count(array_filter($matches, fn($m) => ($m['status_type'] ?? '') === 'live')),
                'scheduled' => count(array_filter($matches, fn($m) => ($m['status_type'] ?? '') === 'scheduled')),
                'teams' => count($standings),
                'has_matches' => !empty($matches),
                'has_table' => !empty($standings),
            ],
        ];
    }

    private function parse_with_regex(): array {
        $html = $this->html;
        $title = '';
        if (preg_match('/<h1[^>]*class="[^"]*style_title__[^"]*"[^>]*>(.*?)<\/h1>/isu', $html, $m)) {
            $title = $this->strip($m[1]);
        }

        $last_update = '';
        if (preg_match('/<[^>]*class="[^"]*style_lastUpdate__[^"]*"[^>]*>(.*?)<\/[^>]+>/isu', $html, $m)) {
            $last_update = $this->strip($m[1]);
        }

        $standings = $this->regex_standings($html);
        $weeks = $this->regex_weeks($html);
        $flat = [];
        foreach ($weeks as $week) foreach ($week['matches'] as $m) $flat[] = $m;

        return $this->make_payload(['title' => $title, 'logo' => ''], $weeks, $flat, $standings, $last_update, '');
    }

    private function regex_standings(string $html): array {
        $standings = [];
        if (!preg_match_all('/<tr[^>]*class="[^"]*style_content__[^"]*"[^>]*>(.*?)<\/tr>/isu', $html, $rows)) {
            return [];
        }
        foreach ($rows[1] as $row) {
            $rank = $this->regex_class_text($row, 'style_number__');
            $team = $this->regex_class_text($row, 'style_name__');
            if (!$team) continue;
            $logo = $this->regex_img($row);
            preg_match_all('/<td[^>]*>(.*?)<\/td>/isu', $row, $tds);
            $numeric = [];
            foreach ($tds[1] ?? [] as $idx => $td) {
                if ($idx === 0) continue;
                $txt = $this->strip($td);
                if ($txt !== '' && !preg_match('/^[آ-یA-Za-z\s]+$/u', $txt)) $numeric[] = $txt;
            }
            $standings[] = [
                'rank' => $rank,
                'team' => $team,
                'logo' => $this->normalize_src($logo),
                'played' => $numeric[0] ?? '',
                'won' => $numeric[1] ?? '',
                'draw' => $numeric[2] ?? '',
                'lost' => $numeric[3] ?? '',
                'diff' => $numeric[4] ?? '',
                'goals' => $numeric[5] ?? '',
                'points' => $numeric[6] ?? '',
                'movement' => (strpos($row, 'icon-up') !== false ? 'up' : (strpos($row, 'icon-down') !== false ? 'down' : 'equal')),
            ];
        }
        return $standings;
    }

    private function regex_weeks(string $html): array {
        $weeks = [];
        $matches = $this->regex_items($html);
        if ($matches) $weeks[] = ['title' => 'همه بازی‌ها', 'matches' => $matches];
        return $weeks;
    }

    private function regex_items(string $html): array {
        $matches = [];
        if (!preg_match_all('/<a[^>]*class="[^"]*style_MatchItem__[^"]*"[^>]*(?:href="([^"]*)")?[^>]*>(.*?)<\/a>/isu', $html, $items, PREG_SET_ORDER)) {
            preg_match_all('/<li[^>]*>(?=.*style_HomeTeam__)(?=.*style_AwayTeam__)(.*?)<\/li>/isu', $html, $items, PREG_SET_ORDER);
        }

        foreach ($items as $it) {
            $href = $it[1] ?? '';
            $body = $it[2] ?? ($it[1] ?? '');
            $homeBlock = $this->regex_block($body, 'style_HomeTeam__');
            $awayBlock = $this->regex_block($body, 'style_AwayTeam__');
            $home = $this->regex_title($homeBlock);
            $away = $this->regex_title($awayBlock);
            if (!$home || !$away) continue;

            $score = $this->regex_class_text($body, 'style_match__') ?: '—';
            $status = $this->regex_class_text($body, 'style_date__') ?: ($score !== '—' ? 'پایان' : 'زمان نامشخص');

            $matches[] = [
                'home' => $this->clean_team($home),
                'away' => $this->clean_team($away),
                'score' => trim($score),
                'status' => trim($status),
                'status_type' => $this->status_type($status, $score),
                'home_logo' => $this->normalize_src($this->regex_img($homeBlock)),
                'away_logo' => $this->normalize_src($this->regex_img($awayBlock)),
                'href' => $this->normalize_href($href),
            ];
        }

        return $matches;
    }

    private function first($xpath, $query, $context = null) {
        $n = $context ? $xpath->query($query, $context) : $xpath->query($query);
        return ($n && $n->length) ? $n->item(0) : null;
    }

    private function text($xpath, $query, $context = null): string {
        $n = $this->first($xpath, $query, $context);
        return $n ? $this->clean($n->textContent) : '';
    }

    private function attr($xpath, $query, $attr, $context = null): string {
        $n = $this->first($xpath, $query, $context);
        return ($n && $n instanceof DOMElement && $n->hasAttribute($attr)) ? trim($n->getAttribute($attr)) : '';
    }

    private function regex_block(string $html, string $classPart): string {
        if (preg_match('/<div[^>]*class="[^"]*'.preg_quote($classPart, '/').'[^"]*"[^>]*>(.*?)<\/div>/isu', $html, $m)) return $m[1];
        return '';
    }

    private function regex_title(string $html): string {
        return $this->regex_class_text($html, 'style_title__') ?: $this->strip($html);
    }

    private function regex_class_text(string $html, string $classPart): string {
        if (preg_match('/<[^>]*class="[^"]*'.preg_quote($classPart, '/').'[^"]*"[^>]*>(.*?)<\/[^>]+>/isu', $html, $m)) return $this->strip($m[1]);
        return '';
    }

    private function regex_img(string $html): string {
        if (preg_match('/<img[^>]+src="([^"]+)"/isu', $html, $m)) return $m[1];
        if (preg_match('/<img[^>]+srcset="([^"]+)"/isu', $html, $m)) return $this->first_src_from_srcset($m[1]);
        return '';
    }

    private function strip(string $html): string {
        return $this->clean(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    private function clean($text): string {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function clean_team($text): string {
        return $this->clean(str_replace(["\n","\r","\t"], ' ', $text));
    }

    private function status_type($status, $score): string {
        $s = trim((string) $status);
        $score = trim((string) $score);
        if (preg_match('/زنده|نیمه|در جریان|\d+\s*\'|\d+ دقیقه/u', $s)) return 'live';
        if (preg_match('/پایان|تمام|FT|بعد از پنالتی/u', $s) || preg_match('/\d+\s*-\s*\d+/u', $score)) return 'finished';
        return 'scheduled';
    }

    private function first_src_from_srcset($srcset): string {
        $srcset = trim((string) $srcset);
        if (!$srcset) return '';
        $parts = explode(',', $srcset);
        $first = trim($parts[0] ?? '');
        $first = preg_split('/\s+/', $first)[0] ?? '';
        return $first;
    }

    private function normalize_src($src): string {
        $src = trim((string) $src);
        if (!$src) return '';
        if (strpos($src, './') === 0) return '';
        if (strpos($src, '//') === 0) return 'https:' . $src;
        if (strpos($src, '/_next/image') === 0) return 'https://football360.ir' . $src;
        return $src;
    }

    private function normalize_href($href): string {
        $href = trim((string) $href);
        if (!$href) return '';
        if (strpos($href, 'http') === 0) return $href;
        if (strpos($href, '/') === 0) return 'https://football360.ir' . $href;
        return '';
    }
}
