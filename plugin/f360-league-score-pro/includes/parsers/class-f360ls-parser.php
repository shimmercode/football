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
        $dom->loadHTML('<?xml encoding="UTF-8">' . $this->html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $league = $this->parse_league_meta($xpath);
        $standings = $this->parse_standings($xpath);
        if (!$standings) $standings = $this->parse_standings_generic($xpath);
        $weeks = $this->parse_match_weeks($xpath);
        if (!$weeks) $weeks = $this->parse_matches_from_links($xpath);

        $flat = [];
        foreach ($weeks as $week) {
            foreach (($week['matches'] ?? []) as $m) {
                $flat[] = $m;
            }
        }

        $last_update = $this->text($xpath, "//*[contains(@class,'style_lastUpdate__')][1]", $dom->documentElement);
        $description = $this->text($xpath, "//*[contains(@class,'style_descBody__')][1]", $dom->documentElement);

        if (!$weeks) $weeks = $this->regex_matches_href($this->html);
        if (!$standings) $standings = $this->regex_standings_href($this->html);
        $standings = $this->renumber_standings(is_array($standings) ? $standings : []);

        $flat = [];
        foreach ($weeks as $week) {
            foreach (($week['matches'] ?? []) as $m) {
                $flat[] = $m;
            }
        }

        $payload = $this->make_payload($league, $weeks, $flat, $standings, $last_update, $description);
        $payload = $this->enrich_from_json($payload);
        if (!empty($payload['standings'])) $payload['standings'] = $this->renumber_standings($payload['standings']);
        if (empty($payload['transfers'])) $payload['transfers'] = $this->parse_transfers($xpath);
        if (empty($payload['statistics'])) $payload['statistics'] = $this->parse_statistics($xpath);
        if (empty($payload['top_scorers']) && !empty($payload['statistics'])) {
            $payload['top_scorers'] = $this->statistics_as_scorers($payload['statistics']);
        }
        return $payload;
    }

    private function parse_league_meta(DOMXPath $xpath): array {
        $title = $this->text($xpath, "//h1[contains(@class,'style_title__')][1] | //h1[1]", null);
        $logo = $this->attr($xpath, "//img[contains(@src,'/competitions/') or contains(@srcset,'/competitions/')][1]", 'src', null);
        if (!$logo) $logo = $this->first_src_from_srcset($this->attr($xpath, "//img[contains(@src,'/competitions/') or contains(@srcset,'/competitions/')][1]", 'srcset', null));
        if (!$logo) {
            $logo = $this->attr($xpath, "//*[contains(@class,'style_header__') or contains(@class,'style_headerContainer__')]//img[1]", 'src', null);
        }
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
        $rows = $xpath->query("//div[contains(@class,'style_containerStandingTable__')]//tr | //table//tr[.//*[contains(@class,'style_name__')] or .//a[contains(@href,'/team/')]]");
        $standings = [];

        if (!$rows || !$rows->length) {
            return [];
        }

        foreach ($rows as $row) {
            $rank = $this->text($xpath, ".//*[contains(@class,'style_number__')][1]", $row);
            $team = $this->text($xpath, ".//*[contains(@class,'style_name__')][1]", $row);
            if (!$team) {
                $team = $this->text($xpath, ".//a[contains(@href,'/team/')][1]", $row);
                $team = preg_replace('/^\s*\d+\s*/u', '', $team);
            }
            if (!$team) {
                $img = $xpath->query(".//img[@alt]", $row)->item(0);
                if ($img instanceof DOMElement) $team = $this->clean_team($img->getAttribute('alt'));
            }

            if (!$team || preg_match('/^(نام تیم|تیم|رتبه)$/u', $team)) { continue; }

            $logo = $this->attr($xpath, ".//img[1]", 'src', $row);
            if (!$logo) {
                $logo = $this->attr($xpath, ".//img[1]", 'srcset', $row);
                $logo = $this->first_src_from_srcset($logo);
            }

            $cells = [];
            $tds = $xpath->query("./td|./th", $row);
            if ($tds) {
                foreach ($tds as $td) {
                    $txt = $this->clean($td->textContent);
                    if ($txt !== '') $cells[] = $txt;
                }
            }
            if (count($cells) < 3) {
                if (preg_match_all('/[-+]?\d+(?:\s*[-–]\s*\d+)?|[۰-۹]+/u', $this->clean($row->textContent), $nm)) {
                    foreach ($nm[0] as $num) $cells[] = $this->fa_to_en($num);
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

        return $this->renumber_standings($standings);
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

    private function parse_standings_generic(DOMXPath $xpath): array {
        $standings = [];
        $seen = [];
        $nodes = $xpath->query("//a[contains(@href,'/team/')]");
        if (!$nodes) return [];
        foreach ($nodes as $teamNode) {
            if (!$teamNode instanceof DOMElement) continue;
            $team = $this->clean_team($teamNode->textContent);
            $img = $xpath->query('.//img', $teamNode)->item(0);
            $logo = '';
            if ($img instanceof DOMElement) {
                if (!$team) $team = $this->clean_team($img->getAttribute('alt'));
                $logo = $this->normalize_src($this->image_src($img));
            }
            if (!$team) continue;
            $box = $teamNode;
            $row_text = '';
            for ($i = 0; $i < 8 && $box; $i++, $box = $box->parentNode) {
                if (!$box instanceof DOMElement) continue;
                $text = $this->clean($box->textContent);
                $len = mb_strlen($text, 'UTF-8');
                if ($len < 8 || $len > 220) continue;
                if (preg_match_all('/[-+]?\d+(?:\s*[-–]\s*\d+)?|[۰-۹]+/u', $text, $nm) && count($nm[0]) >= 6) {
                    $row_text = $text;
                    break;
                }
            }
            if ($row_text === '') continue;
            $numbers = [];
            if (preg_match_all('/[-+]?\d+(?:\s*[-–]\s*\d+)?|[۰-۹]+(?:\s*[-–]\s*[۰-۹]+)?/u', $row_text, $nm)) {
                foreach ($nm[0] as $num) $numbers[] = $this->fa_to_en($num);
            }
            if (count($numbers) < 6) continue;
            $rank = array_shift($numbers);
            $points = array_pop($numbers);
            $key = mb_strtolower($team, 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $standings[] = [
                'rank' => $rank,
                'team' => $team,
                'logo' => $logo,
                'played' => $numbers[0] ?? '',
                'won' => $numbers[1] ?? '',
                'draw' => $numbers[2] ?? '',
                'lost' => $numbers[3] ?? '',
                'diff' => $numbers[4] ?? '',
                'goals' => $numbers[5] ?? '',
                'points' => $points,
                'movement' => 'equal',
            ];
        }
        return $this->renumber_standings($standings);
    }

    private function parse_matches_from_links(DOMXPath $xpath): array {
        $items = $xpath->query("//a[contains(@href,'/matches/') or contains(@href,'/match/')]");
        if (!$items || !$items->length) return [];
        $by_week = [];
        foreach ($items as $a) {
            if (!$a instanceof DOMElement) continue;
            $match = $this->match_from_anchor($xpath, $a);
            if (!$match) continue;
            $week = 'بازی‌ها';
            $node = $a->parentNode;
            for ($i = 0; $i < 8 && $node; $i++, $node = $node->parentNode) {
                if (!$node instanceof DOMElement) continue;
                $head = $xpath->query('./preceding-sibling::*[self::h2 or self::h3 or self::h4][1]', $node)->item(0);
                if ($head) {
                    $title = $this->clean($head->textContent);
                    if ($title !== '') { $week = $title; break; }
                }
            }
            $by_week[$week][] = $match;
        }
        $weeks = [];
        foreach ($by_week as $title => $matches) {
            $weeks[] = ['title' => $title, 'matches' => $matches];
        }
        return $weeks;
    }

    private function match_from_anchor(DOMXPath $xpath, DOMElement $a): ?array {
        $href = $this->normalize_href($a->getAttribute('href'));
        $names = [];
        $logos = [];
        foreach ($xpath->query('.//img', $a) ?: [] as $img) {
            if (!$img instanceof DOMElement) continue;
            $alt = $this->clean_team($img->getAttribute('alt'));
            $src = $this->normalize_src($this->image_src($img));
            if ($alt && !in_array($alt, $names, true)) {
                $names[] = $alt;
                $logos[] = $src;
            } elseif ($src && count($logos) < 2) {
                $logos[] = $src;
            }
        }
        $text = $this->clean($a->textContent);
        $score = '—';
        $status = '';
        if (preg_match('/(\d+)\s*[-–]\s*(\d+)/u', $text, $m)) $score = $this->fa_to_en($m[1]) . ' - ' . $this->fa_to_en($m[2]);
        if (preg_match('/زنده|نیمه|پایان|تمام|وقت|لغو|تعویق|\d{1,2}:\d{2}|\d+\s*[\'′]|\d+\s*دقیقه/u', $text, $sm)) $status = $sm[0];
        if (count($names) < 2) {
            $stripped = $text;
            $stripped = preg_replace('/\d+\s*[-–]\s*\d+/u', ' ', $stripped);
            $stripped = preg_replace('/زنده|نیمه|پایان|تمام|وقت اضافه|لغو|تعویق|\d{1,2}:\d{2}|\d+\s*[\'′]|\d+\s*دقیقه/u', ' ', $stripped);
            $parts = array_values(array_filter(array_map([$this, 'clean_team'], preg_split('/\s{2,}|\s+-\s+/u', $stripped))));
            foreach ($parts as $part) {
                if (mb_strlen($part, 'UTF-8') < 2) continue;
                if (!in_array($part, $names, true)) $names[] = $part;
                if (count($names) >= 2) break;
            }
        }
        if (count($names) < 2) return null;
        if ($status === '') $status = ($score !== '—') ? 'پایان' : 'زمان نامشخص';
        return [
            'home' => $names[0],
            'away' => $names[1],
            'score' => $score,
            'status' => $status,
            'status_type' => $this->status_type($status, $score),
            'minute' => $this->extract_minute($status),
            'date' => $this->extract_date($status),
            'home_logo' => $logos[0] ?? '',
            'away_logo' => $logos[1] ?? '',
            'href' => $href,
        ];
    }

    private function parse_items(DOMXPath $xpath, $items): array {
        $matches = [];
        if (!$items) { return $matches; }

        foreach ($items as $node) {
            $homeNode = $this->first($xpath, ".//*[contains(@class,'style_HomeTeam__')]", $node);
            $awayNode = $this->first($xpath, ".//*[contains(@class,'style_AwayTeam__')]", $node);
            if (!$homeNode || !$awayNode) {
                if ($node instanceof DOMElement) {
                    $fallback_match = $this->match_from_anchor($xpath, $node);
                    if ($fallback_match) $matches[] = $fallback_match;
                }
                continue;
            }

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

            $status = trim($status) ?: ($score ? 'پایان' : 'زمان نامشخص');
            $score = trim($score) ?: '—';
            $matches[] = [
                'home' => $home,
                'away' => $away,
                'score' => $score,
                'status' => $status,
                'status_type' => $this->status_type($status, $score),
                'minute' => $this->extract_minute($status),
                'date' => $this->extract_date($status),
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
            'top_scorers' => [],
            'statistics' => [],
            'transfers' => [],
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

        $standings = $this->renumber_standings($this->regex_standings($html));
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
        if (preg_match('/زنده|live|نیمه|در جریان|در حال|\d+\s*[\'′]|\d+\s*دقیقه|وقت اضافه/iu', $s)) return 'live';
        if (preg_match('/پایان|تمام|FT|بعد از پنالتی/u', $s)) return 'finished';
        if (preg_match('/\d+\s*-\s*\d+/u', $score) && !preg_match('/\d{1,2}:\d{2}/', $s)) return 'finished';
        return 'scheduled';
    }

    private function extract_minute(string $status): string {
        if (preg_match('/(\d{1,3})\s*[\'′]|(\d{1,3})\s*دقیقه/u', $status, $m)) {
            return ($m[1] ?? '') !== '' ? $m[1] : (string) ($m[2] ?? '');
        }
        return '';
    }

    private function extract_date(string $status): string {
        return preg_match('/\d{4}-\d{2}-\d{2}/', $status, $m) ? $m[0] : '';
    }

    private function parse_transfers(DOMXPath $xpath): array {
        $out = [];
        $nodes = $xpath->query("//a[contains(@href,'/player/')]");
        if (!$nodes) return [];
        foreach ($nodes as $a) {
            if (!$a instanceof DOMElement) continue;
            $box = $a;
            for ($i = 0; $i < 8 && $box; $i++, $box = $box->parentNode) {
                if (!$box instanceof DOMElement) continue;
                $text = $this->clean($box->textContent);
                $team_count = $xpath->query('.//a[contains(@href,"/team/")]', $box)->length;
                if (!preg_match('/انتقال|قرضی|آزاد|قطعی/u', $text) && $team_count < 2) continue;
                $player = $this->clean_team($this->clean($a->textContent) ?: $a->getAttribute('title'));
                $img = $xpath->query('.//img', $a)->item(0);
                $photo = ($img instanceof DOMElement) ? $this->normalize_src($this->image_src($img)) : '';
                if (!$player && $img instanceof DOMElement) $player = $this->clean_team($img->getAttribute('alt'));
                $teams = [];
                $logos = [];
                foreach ($xpath->query('.//a[contains(@href,"/team/")]', $box) ?: [] as $teamNode) {
                    if (!$teamNode instanceof DOMElement) continue;
                    $name = $this->clean_team($teamNode->textContent);
                    $tImg = $xpath->query('.//img', $teamNode)->item(0);
                    if (!$name && $tImg instanceof DOMElement) $name = $this->clean_team($tImg->getAttribute('alt'));
                    if ($name) {
                        $teams[] = $name;
                        $logos[] = ($tImg instanceof DOMElement) ? $this->normalize_src($this->image_src($tImg)) : '';
                    }
                }
                if (!$player || count($teams) < 1) continue;
                $type = 'انتقال';
                if (preg_match('/انتقال آزاد|آزاد/u', $text)) $type = 'انتقال آزاد';
                elseif (preg_match('/قرضی/u', $text)) $type = 'قرضی';
                elseif (preg_match('/قطعی/u', $text)) $type = 'انتقال قطعی';
                $when = '';
                if (preg_match('/(\d+\s*(?:سال|ماه|هفته|روز|ساعت|دقیقه)\s*پیش)/u', $text, $tm)) $when = $tm[1];
                $out[] = [
                    'player' => $player,
                    'photo' => $photo,
                    'from' => $teams[0] ?? '',
                    'from_logo' => $logos[0] ?? '',
                    'to' => $teams[1] ?? '',
                    'to_logo' => $logos[1] ?? '',
                    'type' => $type,
                    'date' => $when,
                    'href' => $this->normalize_href($a->getAttribute('href')),
                ];
                break;
            }
            if (count($out) >= 80) break;
        }
        $seen = [];
        $clean = [];
        foreach ($out as $row) {
            $key = md5(($row['player'] ?? '') . '|' . ($row['from'] ?? '') . '|' . ($row['to'] ?? ''));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $clean[] = $row;
        }
        return $clean;
    }

    private function parse_statistics(DOMXPath $xpath): array {
        $groups = [];
        $titles = [
            'گل' => 'گل',
            'پاس گل' => 'پاس گل',
            'گل + پاس گل' => 'گل + پاس گل',
            'گل+پاس گل' => 'گل + پاس گل',
            'کلین‌شیت' => 'کلین‌شیت',
            'کلین شیت' => 'کلین‌شیت',
            'نمره متریکا' => 'نمره متریکا',
            'امید گل' => 'امید گل',
        ];
        foreach ($xpath->query("//*[self::h2 or self::h3 or self::h4 or self::strong or contains(@class,'title') or contains(@class,'header')]") ?: [] as $head) {
            $title = $this->clean($head->textContent);
            $matched = '';
            foreach ($titles as $needle => $label) {
                if ($title === $needle || mb_stripos($title, $needle, 0, 'UTF-8') !== false) {
                    $matched = $label;
                    break;
                }
            }
            if (!$matched) continue;
            $container = $head->parentNode;
            if (!$container instanceof DOMElement) continue;
            $rows = $this->statistic_rows_from_node($xpath, $container);
            if (!$rows && $container->parentNode instanceof DOMElement) $rows = $this->statistic_rows_from_node($xpath, $container->parentNode);
            if (!$rows) continue;
            $key = sanitize_key($matched);
            $groups[$key] = ['key' => $key, 'title' => $matched, 'rows' => $rows];
        }
        if (!$groups) {
            $rows = $this->statistic_rows_from_node($xpath, null);
            if ($rows) $groups['goals'] = ['key' => 'goals', 'title' => 'گل', 'rows' => $rows];
        }
        return array_values($groups);
    }

    private function statistic_rows_from_node(DOMXPath $xpath, $context): array {
        $rows = [];
        $query = ".//a[contains(@href,'/player/')]";
        $nodes = $context ? $xpath->query($query, $context) : $xpath->query("//a[contains(@href,'/player/')]");
        if (!$nodes) return [];
        $rank = 0;
        foreach ($nodes as $a) {
            if (!$a instanceof DOMElement) continue;
            $parent = $a->parentNode;
            $parent_text = $parent ? $this->clean($parent->textContent) : '';
            if (preg_match('/انتقال|قرضی/u', $parent_text)) continue;
            $name = $this->clean_team($a->textContent);
            $img = $xpath->query('.//img', $a)->item(0);
            $photo = ($img instanceof DOMElement) ? $this->normalize_src($this->image_src($img)) : '';
            if (!$name && $img instanceof DOMElement) $name = $this->clean_team($img->getAttribute('alt'));
            $text = $this->clean($a->textContent);
            $value = '';
            if (preg_match('/(\d+(?:\.\d+)?|[۰-۹]+(?:[٫.]\d+)?)\s*$/u', $text, $m)) $value = $this->fa_to_en($m[1]);
            if ($value === '' && preg_match('/(\d+(?:\.\d+)?|[۰-۹]+(?:[٫.]\d+)?)\s*$/u', $parent_text, $m)) $value = $this->fa_to_en($m[1]);
            if (!$name || $value === '') continue;
            $name = preg_replace('/\s+\d+(?:\.\d+)?$/u', '', $name);
            $team = '';
            $parent = $a->parentNode;
            if ($parent) {
                $teamNode = $xpath->query('.//a[contains(@href,"/team/")]', $parent)->item(0);
                if ($teamNode) $team = $this->clean_team($teamNode->textContent);
            }
            $rank++;
            $rows[] = [
                'rank' => (string) $rank,
                'name' => $this->clean_team($name),
                'team' => $team,
                'value' => $value,
                'goals' => $value,
                'photo' => $photo,
                'href' => $this->normalize_href($a->getAttribute('href')),
            ];
            if (count($rows) >= 30) break;
        }
        return $rows;
    }

    private function statistics_as_scorers(array $statistics): array {
        foreach ($statistics as $group) {
            if (($group['title'] ?? '') === 'گل' || ($group['key'] ?? '') === 'goals') return $group['rows'] ?? [];
        }
        return $statistics[0]['rows'] ?? [];
    }

    private function enrich_from_json(array $payload): array {
        if (!preg_match('~<script[^>]*id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>~isu', $this->html, $m)) return $payload;
        $decoded = json_decode(html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8'), true);
        if (!is_array($decoded)) return $payload;
        $found = ['standings' => [], 'matches' => [], 'transfers' => [], 'statistics' => [], 'logo' => '', 'title' => ''];
        $this->walk_json($decoded, $found);
        if (empty($payload['league']['title']) && $found['title']) $payload['league']['title'] = $found['title'];
        if ((empty($payload['league']['logo']) || !$this->is_f360_asset($payload['league']['logo'])) && $found['logo']) $payload['league']['logo'] = $this->normalize_src($found['logo']);
        if (empty($payload['standings']) && $found['standings']) $payload['standings'] = $found['standings'];
        if (empty($payload['matches']) && $found['matches']) {
            $payload['matches'] = $found['matches'];
            $payload['weeks'] = [['title' => 'بازی‌ها', 'matches' => $found['matches']]];
        }
        if (empty($payload['transfers']) && $found['transfers']) $payload['transfers'] = $found['transfers'];
        if (empty($payload['statistics']) && $found['statistics']) $payload['statistics'] = $found['statistics'];
        return $payload;
    }

    private function walk_json($node, array &$found, int $depth = 0): void {
        if (!is_array($node) || $depth > 18) return;
        $standing = $this->normalize_standing_row($node);
        if ($standing) $found['standings'][] = $standing;
        $match = $this->normalize_json_match($node);
        if ($match) $found['matches'][] = $match;
        $transfer = $this->normalize_json_transfer($node);
        if ($transfer) $found['transfers'][] = $transfer;

        if (empty($found['logo'])) {
            foreach (['logo', 'image', 'emblem', 'competitionLogo'] as $key) {
                if (!empty($node[$key]) && is_string($node[$key]) && $this->is_f360_asset($node[$key])) {
                    $found['logo'] = $node[$key];
                    break;
                }
            }
        }
        if (empty($found['title'])) {
            foreach (['faName', 'persianName', 'title', 'name'] as $key) {
                if (!empty($node[$key]) && is_string($node[$key]) && preg_match('/لیگ|جام|لالیگا|بوندس|سری/u', $node[$key])) {
                    $found['title'] = $this->clean($node[$key]);
                    break;
                }
            }
        }

        foreach ($node as $child) {
            if (is_array($child)) $this->walk_json($child, $found, $depth + 1);
        }
    }

    private function normalize_standing_row($row): ?array {
        if (!is_array($row)) return null;
        $team = $this->json_value($row, ['team.name','team.title','team.faName','club.name','participant.name','name','title']);
        $points = $this->json_value($row, ['points','point','pts','stats.points']);
        $played = $this->json_value($row, ['played','matches','games','overall.played','stats.played']);
        if (!$team || ($points === '' && $played === '')) return null;
        if (preg_match('/لیگ|جدول|امتیاز/u', $team)) return null;
        $logo = $this->json_value($row, ['team.logo','team.image','club.logo','logo','image']);
        return [
            'rank' => $this->json_value($row, ['rank','position','pos','place']),
            'team' => $this->clean_team($team),
            'logo' => $this->normalize_src($logo),
            'played' => $played,
            'won' => $this->json_value($row, ['won','wins','overall.won']),
            'draw' => $this->json_value($row, ['draw','draws','overall.draw']),
            'lost' => $this->json_value($row, ['lost','losses','overall.lost']),
            'diff' => $this->json_value($row, ['diff','goalDifference','gd']),
            'goals' => $this->json_value($row, ['goals','goalsForAgainst','gfga']),
            'points' => $points,
            'movement' => 'equal',
        ];
    }

    private function normalize_json_match($item): ?array {
        if (!is_array($item)) return null;
        $home = $this->json_team_name($item['home'] ?? $item['homeTeam'] ?? $item['host'] ?? null) ?: $this->json_value($item, ['home.name','homeTeam.name','homeTitle']);
        $away = $this->json_team_name($item['away'] ?? $item['awayTeam'] ?? $item['guest'] ?? null) ?: $this->json_value($item, ['away.name','awayTeam.name','awayTitle']);
        if (!$home || !$away) return null;
        $homeScore = $this->json_value($item, ['homeScore','home_score','home.score','score.home']);
        $awayScore = $this->json_value($item, ['awayScore','away_score','away.score','score.away']);
        $score = $this->json_value($item, ['score','result']);
        if ($score === '' && $homeScore !== '' && $awayScore !== '') $score = $homeScore . ' - ' . $awayScore;
        if ($score === '') $score = '—';
        $status = $this->json_value($item, ['status','statusTitle','minute','date','startDate']);
        if ($status === '') $status = ($score !== '—') ? 'پایان' : 'زمان نامشخص';
        return [
            'home' => $this->clean_team($home),
            'away' => $this->clean_team($away),
            'score' => $score,
            'status' => $status,
            'status_type' => $this->status_type($status, $score),
            'minute' => $this->extract_minute($status),
            'date' => $this->extract_date((string) $this->json_value($item, ['startDate','date','kickoff'])),
            'home_logo' => $this->normalize_src($this->json_team_logo($item['home'] ?? $item['homeTeam'] ?? null)),
            'away_logo' => $this->normalize_src($this->json_team_logo($item['away'] ?? $item['awayTeam'] ?? null)),
            'href' => $this->normalize_href($this->json_value($item, ['href','url','link'])),
        ];
    }

    private function normalize_json_transfer($item): ?array {
        if (!is_array($item)) return null;
        $player = $this->json_value($item, ['player.name','player.faName','person.name','name']);
        $from = $this->json_team_name($item['from'] ?? $item['fromTeam'] ?? $item['origin'] ?? null) ?: $this->json_value($item, ['from.name','fromTeam.name']);
        $to = $this->json_team_name($item['to'] ?? $item['toTeam'] ?? $item['destination'] ?? null) ?: $this->json_value($item, ['to.name','toTeam.name']);
        $type = $this->json_value($item, ['type','transferType','feeType','moveType']);
        if (!$player || (!$from && !$to)) return null;
        if ($type === '' && empty($item['from']) && empty($item['to']) && empty($item['fromTeam'])) return null;
        return [
            'player' => $this->clean_team($player),
            'photo' => $this->normalize_src($this->json_value($item, ['player.image','player.photo','photo','image'])),
            'from' => $this->clean_team($from),
            'from_logo' => $this->normalize_src($this->json_team_logo($item['from'] ?? $item['fromTeam'] ?? null)),
            'to' => $this->clean_team($to),
            'to_logo' => $this->normalize_src($this->json_team_logo($item['to'] ?? $item['toTeam'] ?? null)),
            'type' => $type ?: 'انتقال',
            'date' => $this->json_value($item, ['date','announcedAt','time']),
            'href' => $this->normalize_href($this->json_value($item, ['href','url'])),
        ];
    }

    private function json_team_name($team): string {
        if (is_string($team) || is_numeric($team)) return (string) $team;
        if (!is_array($team)) return '';
        return $this->json_value($team, ['name','title','faName','persianName','shortName']);
    }

    private function json_team_logo($team): string {
        if (!is_array($team)) return '';
        return $this->json_value($team, ['logo','image','icon','badge']);
    }

    private function json_value(array $arr, array $paths): string {
        foreach ($paths as $path) {
            $current = $arr;
            foreach (explode('.', $path) as $part) {
                if (!is_array($current) || !array_key_exists($part, $current)) { $current = null; break; }
                $current = $current[$part];
            }
            if ($current !== null && $current !== '' && !is_array($current)) return (string) $current;
        }
        return '';
    }

    private function image_src(DOMElement $img): string {
        foreach (['src','data-src','data-lazy-src','srcset','data-srcset'] as $attr) {
            $value = trim($img->getAttribute($attr));
            if (!$value) continue;
            if ($attr === 'srcset' || $attr === 'data-srcset') $value = $this->first_src_from_srcset($value);
            if ($value && strpos($value, 'data:image') !== 0 && strpos($value, 'missing_') === false) return $value;
        }
        return '';
    }

    private function is_f360_asset(string $url): bool {
        return strpos($url, 'football360.ir') !== false || strpos($url, 'static.football360') !== false;
    }

    private function fa_to_en(string $text): string {
        return strtr($text, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    }


    private function renumber_standings(array $standings): array {
        if (!$standings) return [];
        $out = [];
        $rank = 0;
        $last_group = null;
        foreach ($standings as $row) {
            if (!is_array($row) || ($row['team'] ?? '') === '') continue;
            $group = (string) ($row['group'] ?? '');
            if ($group !== (string) $last_group) {
                $rank = 0;
                $last_group = $group;
            }
            $rank++;
            $row['rank'] = (string) $rank;
            $out[] = $row;
        }
        return $out;
    }

    private function regex_matches_href(string $html): array {
        $matches = [];
        if (!preg_match_all('/<a[^>]+href=["\']([^"\']*\/matches\/[^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $html, $items, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($items as $it) {
            $href = $this->normalize_href($it[1] ?? '');
            $body = $it[2] ?? '';
            $alts = [];
            $logos = [];
            if (preg_match_all('/<img[^>]+>/isu', $body, $imgs)) {
                foreach ($imgs[0] as $tag) {
                    $alt = '';
                    $src = '';
                    if (preg_match('/alt=["\']([^"\']*)["\']/', $tag, $am)) $alt = $this->clean_team($am[1]);
                    if (preg_match('/src=["\']([^"\']+)["\']/', $tag, $sm)) $src = $this->normalize_src($sm[1]);
                    if ($alt) { $alts[] = $alt; $logos[] = $src; }
                }
            }
            $text = $this->strip($body);
            $score = '—';
            $status = '';
            if (preg_match('/(\d+)\s*[-–]\s*(\d+)/u', $text, $m)) $score = $this->fa_to_en($m[1]) . ' - ' . $this->fa_to_en($m[2]);
            if (preg_match('/زنده|نیمه|پایان|تمام|\d{1,2}:\d{2}/u', $text, $sm)) $status = $sm[0];
            if (count($alts) < 2) continue;
            if ($status === '') $status = ($score !== '—') ? 'پایان' : 'زمان نامشخص';
            $matches[] = [
                'home' => $alts[0],
                'away' => $alts[1],
                'score' => $score,
                'status' => $status,
                'status_type' => $this->status_type($status, $score),
                'minute' => $this->extract_minute($status),
                'date' => '',
                'home_logo' => $logos[0] ?? '',
                'away_logo' => $logos[1] ?? '',
                'href' => $href,
            ];
        }
        return $matches ? [['title' => 'بازی‌ها', 'matches' => $matches]] : [];
    }

    private function regex_standings_href(string $html): array {
        return [];
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
        if (!$src || strpos($src, 'data:image') === 0) return '';
        if (preg_match('~missing_team_logo|missing_person_image|placeholder|ic_default~i', $src)) return '';
        if (strpos($src, './') === 0) return '';
        if (strpos($src, '//') === 0) return 'https:' . $src;
        if (strpos($src, '/_next/image') === 0) return 'https://football360.ir' . $src;
        if (strpos($src, '/') === 0) return 'https://football360.ir' . $src;
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
