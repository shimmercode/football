<?php
if (!defined('ABSPATH')) { exit; }

class F360LS_Parser {
    private string $html;

    public function __construct($html) {
        $this->html = (string) $html;
    }

    public function parse(): array {
        $standings = $this->regex_table_from_cells($this->html);
        if (!$standings || $this->standings_are_cloned($standings)) $standings = $this->regex_standings_near_teams($this->html);
        if (!$standings || $this->standings_are_cloned($standings)) $standings = $this->regex_official_table($this->html);
        if (!$standings || $this->standings_are_cloned($standings)) $standings = $this->regex_standings($this->html);
        if (!$standings || $this->standings_are_cloned($standings)) $standings = $this->regex_standings_href($this->html);
        if ($standings && $this->standings_are_cloned($standings)) $standings = [];

        $weeks = $this->regex_schedule_page($this->html);
        if (!$weeks) $weeks = $this->regex_matches_href($this->html);

        $last_update = '';
        if (preg_match('/آخرین به‌روزرسانی:\s*([^<\n]+)/u', $this->html, $um)) $last_update = $this->clean($um[1]);

        $statistics = $this->regex_statistics($this->html);
        $transfers = method_exists($this, 'regex_transfers') ? $this->regex_transfers($this->html) : [];

        $looks_table = (bool) preg_match('/امتیاز|style_name__|style_game__/u', $this->html);
        $looks_matches = (bool) preg_match('/هفته\s*\d+/u', $this->html) && (bool) preg_match('~/matches/~', $this->html);
        $looks_transfer = (bool) preg_match('/انتقال (?:قطعی|قرضی|آزاد)/u', $this->html);
        $looks_stats = (bool) preg_match('/پاس گل|کلین|نمره متریکا/u', $this->html);

        $need_dom = class_exists('DOMDocument') && (
            ((!$standings || $this->standings_are_cloned($standings)) && $looks_table) ||
            (!$weeks && $looks_matches) ||
            (empty($transfers) && $looks_transfer) ||
            (empty($statistics) && $looks_stats)
        );

        $league = ['title' => '', 'logo' => ''];
        if ($need_dom) {
            $dom = new DOMDocument('1.0', 'UTF-8');
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">' . $this->html);
            libxml_clear_errors();
            $xpath = new DOMXPath($dom);
            $league = $this->parse_league_meta($xpath);
            if (!$standings || $this->standings_are_cloned($standings)) $standings = $this->parse_official_table($xpath);
            if (!$standings || $this->standings_are_cloned($standings)) $standings = $this->parse_standings($xpath);
            if ($standings && $this->standings_are_cloned($standings)) $standings = [];
            if (!$weeks) $weeks = $this->parse_match_weeks($xpath);
            if (!$weeks) $weeks = $this->parse_matches_from_links($xpath);
            if ($last_update === '') $last_update = $this->text($xpath, "//*[contains(@class,'style_lastUpdate__')][1]", $dom->documentElement);
            if (empty($transfers)) $transfers = $this->parse_transfers($xpath);
            if (empty($statistics)) $statistics = $this->parse_statistics($xpath);
        }

        $standings = $this->renumber_standings(is_array($standings) ? $standings : []);
        $flat = [];
        foreach ($weeks as $week) {
            foreach (($week['matches'] ?? []) as $m) $flat[] = $m;
        }
        $payload = $this->make_payload($league, $weeks, $flat, $standings, $last_update, '');
        $payload = $this->enrich_from_json($payload);
        if (!empty($payload['standings'])) $payload['standings'] = $this->renumber_standings($payload['standings']);
        if (!empty($transfers)) $payload['transfers'] = $this->merge_transfer_lists($payload['transfers'] ?? [], is_array($transfers) ? $transfers : []);
        if (empty($payload['statistics']) && !empty($statistics)) $payload['statistics'] = $statistics;
        if (empty($payload['top_scorers']) && !empty($payload['statistics'])) {
            $payload['top_scorers'] = $this->statistics_as_scorers($payload['statistics']);
        }
        return $payload;
    }

    public function parse_monthly_best_public(): array {
        return $this->parse_monthly_best();
    }

    private function parse_monthly_best(): array {
        $out = [];
        $seen = [];
        $html = $this->html;
        $plain = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~isu', ' ', $html);
        $plain = $this->strip($plain);
        $plain = preg_replace('/معرفی نامزدها|معرفی نفر منتخب/u', "\n", $plain);
        $re = '/(بهترین (?:بازیکن|سرمربی|گل|مهار))\s*((?:فروردین|اردیبهشت|خرداد|تیر|مرداد|شهریور|مهر|آبان|آذر|دی|بهمن|اسفند)(?:\s+و\s+(?:فروردین|اردیبهشت|خرداد|تیر|مرداد|شهریور|مهر|آبان|آذر|دی|بهمن|اسفند))?\s+[0-9۰-۹]{4})\s+(.+?)(?=بهترین |$)/u';
        if (preg_match_all($re, $plain, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $row) {
                $bits = preg_split('/\s+/u', trim($row[3]));
                $bits = array_values(array_filter($bits, function($b) { return $b !== '' && !preg_match('/معرفی|نامزد|scroll/u', $b); }));
                if (count($bits) < 2) continue;
                $name = $this->clean_team(implode(' ', array_slice($bits, 0, 2)));
                $team = $this->clean_team(implode(' ', array_slice($bits, 2)));
                if ($name === '' || preg_match('/معرفی|نامزد|جایزه|فصل|نوع/u', $name)) continue;
                $key = md5(mb_strtolower($row[1] . '|' . $row[2] . '|' . $name, 'UTF-8'));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $photo = '';
                if (preg_match('~best-of-month/[^"\']+\.(?:jpg|jpeg|png)[^"\']*~i', $html, $pm)) {
                    $photo = $this->normalize_src($pm[0]);
                }
                $out[] = [
                    'category' => $this->clean($row[1]),
                    'period' => $this->clean($row[2]),
                    'name' => $name,
                    'team' => $team,
                    'team_logo' => '',
                    'photo' => $photo,
                ];
                if (count($out) >= 24) break;
            }
        }
        if ($out) return $out;
        if (!preg_match_all('~<img[^>]+src=["\']([^"\']*best-of-month/[^"\']+\.(?:jpg|jpeg|png)[^"\']*)["\'][^>]*>~i', $html, $imgs, PREG_SET_ORDER)) return [];
        foreach ($imgs as $im) {
            $src = $this->normalize_src($im[1]);
            if ($src === '' || stripos($src, 'Icon_') !== false) continue;
            $alt = '';
            if (preg_match('/alt=["\']([^"\']+)["\']/', $im[0], $am)) $alt = $this->clean_team($am[1]);
            if ($alt === '' || preg_match('/بهترین|معرفی/u', $alt)) continue;
            $key = md5(mb_strtolower($alt, 'UTF-8'));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = [
                'category' => 'بهترین‌های ماه',
                'period' => '',
                'name' => $alt,
                'team' => '',
                'team_logo' => '',
                'photo' => $src,
            ];
            if (count($out) >= 24) break;
        }
        return $out;
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
        $rows = $xpath->query("//table//tr[.//a[contains(@href,'/team/')]] | //*[@role='row'][.//a[contains(@href,'/team/')]]");
        $standings = [];
        $seen = [];
        if ($rows && $rows->length) {
            foreach ($rows as $row) {
                $mapped = $this->standing_from_row($xpath, $row);
                if (!$mapped) continue;
                $key = mb_strtolower($mapped['team'], 'UTF-8');
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $standings[] = $mapped;
            }
        }
        if ($this->standings_are_cloned($standings)) $standings = [];
        return $this->renumber_standings($standings);
    }

    private function standing_from_row(DOMXPath $xpath, $row): ?array {
        $teamLinks = $xpath->query(".//a[contains(@href,'/team/')]", $row);
        if (!$teamLinks || $teamLinks->length !== 1) return null;
        $teamNode = $teamLinks->item(0);
        if (!$teamNode instanceof DOMElement) return null;
        $team = $this->clean_team($teamNode->textContent);
        $img = $xpath->query('.//img', $teamNode)->item(0) ?: $xpath->query('.//img', $row)->item(0);
        if (!$team && $img instanceof DOMElement) $team = $this->clean_team($img->getAttribute('alt'));
        $team = preg_replace('/^\s*\d+\s*/u', '', (string) $team);
        if (!$team || preg_match('/^(نام تیم|تیم|رتبه)$/u', $team)) return null;
        $logo = ($img instanceof DOMElement) ? $this->normalize_src($this->image_src($img)) : '';
        $mapped = $this->map_row_numeric_cells($xpath, $row);
        if (!$mapped) {
            $cells = [];
            foreach ($xpath->query('./td|./th', $row) ?: [] as $td) {
                $txt = $this->clean($td->textContent);
                if ($txt !== '') $cells[] = $txt;
            }
            $blob = $cells ? implode(' | ', $cells) : $this->clean($row->textContent);
            $tokens = $this->extract_stat_tokens($blob);
            if (count($tokens) < 6) return null;
            $mapped = $this->map_stat_tokens($tokens);
        }
        $mapped['team'] = $this->clean($team);
        $mapped['logo'] = $logo;
        $html = '';
        if (method_exists($row, 'C14N')) $html = (string) $row->C14N();
        $mapped['movement'] = (strpos($html, 'icon-up') !== false ? 'up' : (strpos($html, 'icon-down') !== false ? 'down' : 'equal'));
        return $mapped;
    }

    private function regex_standings_near_teams(string $html): array {
        $standings = [];
        $seen = [];
        if (!preg_match_all('~<a[^>]+href=["\'][^"\']*/team/[^"\']+["\'][^>]*>(.*?)</a>~isu', $html, $items, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [];
        foreach ($items as $it) {
            $chunk = substr($html, (int) $it[0][1], 700);
            $team = $this->strip($it[1][0]);
            if ($team === '' && preg_match('/alt=["\']([^"\']+)["\']/', $chunk, $am)) $team = $this->clean_team($am[1]);
            $team = preg_replace('/^\s*\d+\s*/u', '', $this->clean_team($team));
            if (!$team || preg_match('/^(نام تیم|تیم|رتبه)$/u', $team)) continue;
            $key = mb_strtolower($team, 'UTF-8');
            if (isset($seen[$key])) continue;
            $tokens = $this->extract_stat_tokens($this->strip($chunk));
            if (count($tokens) < 6) continue;
            $mapped = $this->map_stat_tokens($tokens);
            $mapped['team'] = $team;
            $mapped['logo'] = $this->normalize_src($this->regex_img($chunk));
            $mapped['movement'] = 'equal';
            $seen[$key] = true;
            $standings[] = $mapped;
            if (count($standings) >= 24) break;
        }
        if ($this->standings_are_cloned($standings)) return [];
        return $this->renumber_standings($standings);
    }

    private function regex_table_from_cells(string $html): array {
        $standings = [];
        $seen = [];
        if (!preg_match_all('~<tr[^>]*>(.*?)</tr>~isu', $html, $rows)) return [];
        foreach ($rows[1] as $row) {
            if (stripos($row, '/team/') === false) continue;
            $team = '';
            $logo = $this->normalize_src($this->regex_img($row));
            if (preg_match('~<a[^>]+href=["\'][^"\']*/team/[^"\']+["\'][^>]*>(.*?)</a>~isu', $row, $tm)) {
                $team = $this->strip($tm[1]);
            }
            if ($team === '' && preg_match('/alt=["\']([^"\']+)["\']/', $row, $am)) $team = $this->clean_team($am[1]);
            $team = preg_replace('/^\s*\d+\s*/u', '', $this->clean_team($team));
            if (!$team || preg_match('/^(نام تیم|تیم|رتبه)$/u', $team)) continue;
            $nums = [];
            if (preg_match_all('~<td[^>]*>(.*?)</td>~isu', $row, $tds)) {
                foreach ($tds[1] as $i => $td) {
                    if ($i === 0) continue;
                    $txt = preg_replace('/\s+/', '', $this->fa_to_en($this->strip($td)));
                    if ($txt === '') continue;
                    if (!preg_match('/^[-+]?\d+(?:[-–]\d+)?$/', $txt)) continue;
                    $nums[] = $txt;
                }
            }
            if (count($nums) < 6) continue;
            $vals = array_slice($nums, -7);
            while (count($vals) < 7) array_unshift($vals, '');
            $key = mb_strtolower($team, 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $standings[] = [
                'rank' => '',
                'team' => $team,
                'logo' => $logo,
                'played' => $vals[0],
                'won' => $vals[1],
                'draw' => $vals[2],
                'lost' => $vals[3],
                'diff' => $vals[4],
                'goals' => $vals[5],
                'points' => $vals[6],
                'movement' => (strpos($row, 'icon-up') !== false ? 'up' : (strpos($row, 'icon-down') !== false ? 'down' : 'equal')),
            ];
        }
        if ($this->standings_are_cloned($standings)) return [];
        return $this->renumber_standings($standings);
    }

    private function regex_official_table(string $html): array {
        $standings = [];
        $seen = [];
        $re = '/style_name__[^>]*>([^<]+)<[\\s\\S]{0,1600}?style_game__[^>]*>([^<]+)<\\/td>\\s*<td[^>]*>([^<]+)<\\/td>\\s*<td[^>]*>([^<]+)<\\/td>\\s*<td[^>]*>([^<]+)<\\/td>\\s*<td[^>]*>([^<]+)<\\/td>\\s*<td[^>]*>([^<]+)<\\/td>\\s*<td[^>]*style_boldLastChild__[^>]*>([^<]+)/iu';
        if (!preg_match_all($re, $html, $rows, PREG_SET_ORDER)) return [];
        foreach ($rows as $row) {
            $team = $this->clean_team($this->strip($row[1]));
            $team = preg_replace('/^\\s*\\d+\\s*/u', '', (string) $team);
            if (!$team) continue;
            $key = mb_strtolower($team, 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $logo = $this->normalize_src($this->regex_img($row[0]));
            $vals = [];
            for ($i = 2; $i <= 8; $i++) $vals[] = preg_replace('/\\s+/', '', $this->fa_to_en($this->strip($row[$i])));
            $standings[] = [
                'rank' => '',
                'team' => $team,
                'logo' => $logo,
                'played' => $vals[0],
                'won' => $vals[1],
                'draw' => $vals[2],
                'lost' => $vals[3],
                'diff' => $vals[4],
                'goals' => $vals[5],
                'points' => $vals[6],
                'movement' => (strpos($row[0], 'icon-up') !== false ? 'up' : (strpos($row[0], 'icon-down') !== false ? 'down' : 'equal')),
            ];
        }
        if ($this->standings_are_cloned($standings)) return [];
        return $this->renumber_standings($standings);
    }

    private function regex_schedule_page(string $html): array {
        $heads = $this->regex_week_heads($html);
        $items = $this->regex_match_anchors($html);
        if (!$items) return [];
        $by_week = [];
        $seen = [];
        $head_i = 0;
        $head_n = count($heads);
        $chosen = null;
        foreach ($items as $it) {
            $pos = (int) ($it['pos'] ?? 0);
            while ($head_i < $head_n && $heads[$head_i]['pos'] <= $pos) {
                $chosen = $heads[$head_i];
                $head_i++;
            }
            $week_no = (int) ($chosen['week'] ?? 0);
            $date_label = (string) ($chosen['date_label'] ?? '');
            if ($week_no < 1) {
                $back = substr($html, max(0, $pos - 1600), 1600);
                if (preg_match_all('~هفته(?:</?[^>]+>|&nbsp;|\s)*([0-9۰-۹]{1,2})~u', $back, $wm)) {
                    $week_no = (int) $this->fa_to_en((string) end($wm[1]));
                    $date_label = 'هفته ' . $week_no;
                }
            }
            if ($week_no < 1 || $week_no > 60) $week_no = 0;
            $href = $this->normalize_href((string) ($it['href'] ?? ''));
            if ($href !== '' && isset($seen[$href])) continue;
            if ($href !== '') $seen[$href] = true;
            $match = $this->match_from_anchor_html($href, (string) ($it['body'] ?? ''), $date_label);
            if (!$match) continue;
            $key = $week_no > 0 ? $week_no : 0;
            $by_week[$key]['title'] = $week_no > 0 ? ('هفته ' . $week_no) : 'بازی‌ها';
            $by_week[$key]['matches'][] = $match;
        }
        if (!$by_week) return [];
        ksort($by_week, SORT_NUMERIC);
        $weeks = [];
        foreach ($by_week as $week) {
            if (!empty($week['matches'])) $weeks[] = $week;
        }
        return $weeks;
    }

    private function regex_week_heads(string $html): array {
        $heads = [];
        $scripts = $this->script_ranges($html);
        if (!preg_match_all('~هفته(?:</?[^>]+>|&nbsp;|\s)*([0-9۰-۹]{1,2})~u', $html, $hm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $days = 'جمعه|شنبه|یک‌شنبه|یکشنبه|دوشنبه|سه‌شنبه|چهارشنبه|پنج‌شنبه|پنجشنبه';
        foreach ($hm as $h) {
            $week_no = (int) $this->fa_to_en((string) $h[1][0]);
            if ($week_no < 1 || $week_no > 60) continue;
            $pos = (int) $h[0][1];
            if ($this->pos_in_ranges($scripts, $pos)) continue;
            $back = substr($html, max(0, $pos - 140), 140);
            $back_plain = $this->strip($back);
            if (!preg_match('~(?:' . $days . ')~u', $back_plain)) continue;
            $date_label = $this->clean($back_plain);
            if (!preg_match('~هفته~u', $date_label)) $date_label .= ' - هفته ' . $week_no;
            $heads[] = ['pos' => $pos, 'week' => $week_no, 'date_label' => $date_label];
        }
        return $heads;
    }

    private function regex_match_anchors(string $html): array {
        $out = [];
        $scripts = $this->script_ranges($html);
        if (!preg_match_all('~<a\b[^>]*href=["\']([^"\']*/matches/[^"\']+)["\'][^>]*>~i', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($m as $row) {
            $pos = (int) $row[0][1];
            if ($this->pos_in_ranges($scripts, $pos)) continue;
            $from = $pos + strlen($row[0][0]);
            $end = strpos($html, '</a>', $from);
            if ($end === false) continue;
            $body_len = $end - $from;
            if ($body_len < 20 || $body_len > 8000) continue;
            $out[] = [
                'pos' => $pos,
                'href' => $row[1][0],
                'body' => substr($html, $from, $body_len),
            ];
        }
        return $out;
    }

    private function match_from_anchor_html(string $href, string $body, string $date_label = ''): ?array {
        $alts = [];
        $logos = [];
        if (preg_match_all('~<img[^>]+>~iu', $body, $imgs)) {
            foreach ($imgs[0] as $tag) {
                $alt = '';
                $src = '';
                if (preg_match('~alt=["\']([^"\']*)["\']~', $tag, $am)) $alt = $this->clean_team($am[1]);
                if (preg_match('~src=["\']([^"\']+)["\']~', $tag, $sm)) $src = $this->normalize_src($sm[1]);
                if ($alt !== '') {
                    $alts[] = $alt;
                    $logos[] = $src;
                }
            }
        }
        if (count($alts) < 2) return null;
        $text = $this->strip($body);
        $plain = $this->fa_to_en($text);
        $score = '—';
        $status = '';
        $kickoff = $this->extract_kickoff($plain);
        if (preg_match('~(\d+)\s*[-–]\s*(\d+)~u', $plain, $sm)) $score = $sm[1] . ' - ' . $sm[2];
        if (preg_match('~زنده|نیمه|پایان|تمام~u', $text, $stm)) $status = $stm[0];
        if ($status === '' && $kickoff !== '') $status = $kickoff;
        if ($status === '') $status = ($score !== '—') ? 'پایان' : 'زمان نامشخص';
        return [
            'home' => $alts[0],
            'away' => $alts[1],
            'score' => $score,
            'status' => $status,
            'status_type' => $this->status_type($status, $score),
            'minute' => $this->extract_minute($status),
            'date' => '',
            'date_label' => $date_label,
            'home_logo' => $logos[0] ?? '',
            'away_logo' => $logos[1] ?? '',
            'href' => $href,
        ];
    }

    private function script_ranges(string $html): array {
        $ranges = [];
        $offset = 0;
        $len = strlen($html);
        while ($offset < $len) {
            $open = stripos($html, '<script', $offset);
            if ($open === false) break;
            $close = stripos($html, '</script', $open);
            $end = ($close === false) ? $len : ($close + 9);
            $ranges[] = [$open, $end];
            $offset = $end;
        }
        return $ranges;
    }

    private function pos_in_ranges(array $ranges, int $pos): bool {
        foreach ($ranges as $r) {
            if ($pos >= $r[0] && $pos < $r[1]) return true;
        }
        return false;
    }

    private function parse_official_table(DOMXPath $xpath): array {
        $standings = [];
        $seen = [];
        $group = '';
        $rows = $xpath->query("//tr[contains(@class,'style_content__')] | //*[contains(@class,'style_containerStandingTable__')]//tr[.//a[contains(@href,'/team/')]] | //table//tr[.//*[contains(@class,'style_name__')]]");
        if (!$rows || !$rows->length) return [];
        foreach ($rows as $row) {
            if (!$row instanceof DOMElement) continue;
            $team_links = $xpath->query(".//a[contains(@href,'/team/')]", $row);
            if (!$team_links || !$team_links->length) {
                $label = $this->clean($row->textContent);
                if ($label !== '' && mb_strlen($label, 'UTF-8') <= 40 && preg_match('/گروه|Group|مرحله/u', $label)) $group = $label;
                continue;
            }
            $mapped = $this->official_standing_from_row($xpath, $row);
            if (!$mapped) $mapped = $this->standing_from_row($xpath, $row);
            if (!$mapped) continue;
            if ($group !== '') $mapped['group'] = $group;
            $key = mb_strtolower($mapped['team'], 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $standings[] = $mapped;
        }
        if ($this->standings_are_cloned($standings)) return [];
        return $this->renumber_standings($standings);
    }

    private function official_standing_from_row(DOMXPath $xpath, $row): ?array {
        $name = $this->text($xpath, ".//*[contains(@class,'style_name__')]", $row);
        $rank = $this->text($xpath, ".//*[contains(@class,'style_number__')]", $row);
        $teamNode = $xpath->query(".//a[contains(@href,'/team/')]", $row)->item(0);
        $img = $xpath->query('.//img', $row)->item(0);
        if (!$name && $teamNode) $name = $this->clean_team($teamNode->textContent);
        if (!$name && $img instanceof DOMElement) $name = $this->clean_team($img->getAttribute('alt'));
        $name = preg_replace('/^\s*\d+\s*/u', '', (string) $name);
        $name = $this->clean_team($name);
        if (!$name || preg_match('/^(نام تیم|تیم|رتبه)$/u', $name)) return null;
        $mapped = $this->map_row_numeric_cells($xpath, $row);
        if (!$mapped) return null;
        $html = '';
        if (method_exists($row, 'C14N')) $html = (string) $row->C14N();
        $mapped['rank'] = $this->fa_to_en($rank);
        $mapped['team'] = $name;
        $mapped['logo'] = ($img instanceof DOMElement) ? $this->normalize_src($this->image_src($img)) : '';
        $mapped['movement'] = (strpos($html, 'icon-up') !== false ? 'up' : (strpos($html, 'icon-down') !== false ? 'down' : 'equal'));
        return $mapped;
    }

    private function map_row_numeric_cells(DOMXPath $xpath, $row): ?array {
        $nums = [];
        $tds = $xpath->query('./td', $row);
        if (!$tds || !$tds->length) return null;
        foreach ($tds as $i => $td) {
            if ($i === 0) continue;
            $txt = $this->fa_to_en($this->clean($td->textContent));
            if ($txt === '') continue;
            if (!preg_match('/^[-+]?\d+(?:\s*[-–]\s*\d+)?$/', $txt)) continue;
            $nums[] = preg_replace('/\s+/', '', $txt);
        }
        if (count($nums) < 6) return null;
        $vals = array_slice($nums, -7);
        while (count($vals) < 7) array_unshift($vals, '');
        return [
            'rank' => '',
            'played' => (string) $vals[0],
            'won' => (string) $vals[1],
            'draw' => (string) $vals[2],
            'lost' => (string) $vals[3],
            'diff' => (string) $vals[4],
            'goals' => (string) $vals[5],
            'points' => (string) $vals[6],
        ];
    }

    private function extract_stat_tokens(string $text): array {
        $text = $this->fa_to_en($text);
        if (!preg_match_all('/[-+]?\d+\s*[-–]\s*\d+|[-+]?\d+/u', $text, $m)) return [];
        $tokens = [];
        foreach ($m[0] as $tok) $tokens[] = preg_replace('/\s+/', '', $tok);
        return $tokens;
    }

    private function map_stat_tokens(array $tokens): array {
        $tokens = array_values($tokens);
        $points = array_pop($tokens);
        $goals = array_pop($tokens) ?? '';
        $diff = array_pop($tokens) ?? '';
        $lost = array_pop($tokens) ?? '';
        $draw = array_pop($tokens) ?? '';
        $won = array_pop($tokens) ?? '';
        $played = array_pop($tokens) ?? '';
        $rank = array_pop($tokens) ?? '';
        return [
            'rank' => (string) $rank,
            'played' => (string) $played,
            'won' => (string) $won,
            'draw' => (string) $draw,
            'lost' => (string) $lost,
            'diff' => (string) $diff,
            'goals' => (string) $goals,
            'points' => (string) $points,
        ];
    }

    private function standings_are_cloned(array $rows): bool {
        if (count($rows) < 3) return false;
        $sigs = [];
        foreach ($rows as $row) {
            $sigs[] = ($row['played'] ?? '') . '|' . ($row['won'] ?? '') . '|' . ($row['draw'] ?? '') . '|' . ($row['lost'] ?? '') . '|' . ($row['points'] ?? '') . '|' . ($row['goals'] ?? '');
        }
        return count(array_unique($sigs)) === 1;
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
            $box = $teamNode;
            $row = null;
            for ($i = 0; $i < 8 && $box; $i++, $box = $box->parentNode) {
                if (!$box instanceof DOMElement) continue;
                $team_count = $xpath->query('.//a[contains(@href,"/team/")]', $box)->length;
                if ($team_count !== 1) continue;
                $mapped = $this->standing_from_row($xpath, $box);
                if ($mapped) { $row = $mapped; break; }
            }
            if (!$row) continue;
            $key = mb_strtolower($row['team'], 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $standings[] = $row;
        }
        if ($this->standings_are_cloned($standings)) return [];
        return $this->renumber_standings($standings);
    }

    private function parse_schedule_page(DOMXPath $xpath): array {
        $heads = [];
        foreach ($xpath->query("//*[contains(., 'هفته')]") ?: [] as $el) {
            if (!$el instanceof DOMElement) continue;
            $title = $this->clean($el->textContent);
            if (preg_match('/(.{0,40}هفته\\s*\\d+)/u', $title, $short)) $title = $this->clean($short[1]);
            if ($title === '' || mb_strlen($title, 'UTF-8') > 80) continue;
            if (preg_match('/^هفته\\s*\\d+$/u', $title)) continue;
            if (!preg_match('/هفته\\s*(\\d+)/u', $title, $wm)) continue;
            $week_no = (int) $wm[1];
            if ($week_no < 1 || $week_no > 60) continue;
            $date_label = trim(preg_replace('/\\s*[-–]\\s*هفته\\s*\\d+\\s*$/u', '', $title));
            $heads[] = [
                'node' => $el,
                'week' => $week_no,
                'title' => 'هفته ' . $week_no,
                'date_label' => $date_label !== $title ? $date_label : '',
            ];
        }
        if (!$heads) return [];

        $by_week = [];
        $seen = [];
        foreach ($xpath->query("//a[contains(@href,'/matches/') or contains(@href,'/match/')]") ?: [] as $a) {
            if (!$a instanceof DOMElement) continue;
            $match = $this->match_from_anchor($xpath, $a);
            if (!$match) continue;
            $href = (string) ($match['href'] ?? '');
            if ($href !== '' && isset($seen[$href])) continue;
            if ($href !== '') $seen[$href] = true;
            $chosen = null;
            foreach ($heads as $head) {
                $pos = $head['node']->compareDocumentPosition($a);
                if ($pos & XML_DOCUMENT_POSITION_FOLLOWING) $chosen = $head;
            }
            if (!$chosen) continue;
            if (!empty($chosen['date_label'])) $match['date_label'] = $chosen['date_label'];
            $by_week[$chosen['week']]['title'] = $chosen['title'];
            $by_week[$chosen['week']]['matches'][] = $match;
        }
        if (!$by_week) return [];
        ksort($by_week, SORT_NUMERIC);
        $weeks = [];
        foreach ($by_week as $week) {
            if (!empty($week['matches'])) $weeks[] = $week;
        }
        return $weeks;
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
        $plain = $this->fa_to_en($text);
        $score = '—';
        $status = '';
        $kickoff = $this->extract_kickoff($plain);
        if (preg_match('/(\d+)\s*[-–]\s*(\d+)/u', $plain, $m)) $score = $m[1] . ' - ' . $m[2];
        if (preg_match('/زنده|نیمه|پایان|تمام|وقت|لغو|تعویق/u', $text, $sm)) $status = $sm[0];
        if ($status === '' && $kickoff !== '') $status = $kickoff;
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
                $txt = $this->fa_to_en($this->strip($td));
                if ($txt === '') continue;
                if (!preg_match('/^[-+]?\d+(?:\s*[-–]\s*\d+)?$/', $txt)) continue;
                $numeric[] = preg_replace('/\s+/', '', $txt);
            }
            if (count($numeric) < 6) continue;
            $vals = array_slice($numeric, -7);
            while (count($vals) < 7) array_unshift($vals, '');
            $standings[] = [
                'rank' => $this->fa_to_en($rank),
                'team' => $team,
                'logo' => $this->normalize_src($logo),
                'played' => $vals[0],
                'won' => $vals[1],
                'draw' => $vals[2],
                'lost' => $vals[3],
                'diff' => $vals[4],
                'goals' => $vals[5],
                'points' => $vals[6],
                'movement' => (strpos($row, 'icon-up') !== false ? 'up' : (strpos($row, 'icon-down') !== false ? 'down' : 'equal')),
            ];
        }
        if ($this->standings_are_cloned($standings)) return [];
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
        if (preg_match('/زنده|live|نیمه|در جریان|در حال|وقت اضافه/iu', $s)) return 'live';
        if ($this->extract_kickoff($s) !== '') return 'scheduled';
        if (preg_match('/پایان|تمام|FT|بعد از پنالتی/u', $s)) return 'finished';
        if (preg_match('/\d+\s*-\s*\d+/u', $score) && $this->extract_kickoff($s) === '') return 'finished';
        return 'scheduled';
    }

    private function extract_kickoff(string $text): string {
        $plain = $this->fa_to_en($text);
        if (!preg_match('/(?<!\d)(\d{1,2})\s*[:：]\s*(\d{2})(?!\d)/u', $plain, $m)) return '';
        $a = (int) $m[1];
        $b = (int) $m[2];
        if ($a > 23 && $b <= 23) {
            $hour = $b;
            $minute = $a;
        } elseif ($a <= 23 && $b <= 59) {
            if (in_array($a, [0, 15, 30, 45], true) && $b <= 23) {
                $hour = $b;
                $minute = $a;
            } else {
                $hour = $a;
                $minute = $b;
            }
        } else {
            return '';
        }
        if ($hour > 23 || $minute > 59) return '';
        return sprintf('%02d:%02d', $hour, $minute);
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
            $picked = null;
            $picked_text = '';
            for ($i = 0; $i < 8 && $box; $i++, $box = $box->parentNode) {
                if (!$box instanceof DOMElement) continue;
                $player_count = $xpath->query('.//a[contains(@href,"/player/")]', $box)->length;
                if ($player_count !== 1) continue;
                $text = $this->clean($box->textContent);
                if (mb_strlen($text, 'UTF-8') > 420) continue;
                $team_count = $xpath->query('.//a[contains(@href,"/team/")]', $box)->length;
                if (!preg_match('/انتقال|قرضی|آزاد|قطعی/u', $text) && $team_count < 1) continue;
                $picked = $box;
                $picked_text = $text;
                break;
            }
            if (!$picked) continue;
            $player = $this->clean_team($this->clean($a->textContent) ?: $a->getAttribute('title'));
            $img = $xpath->query('.//img', $a)->item(0);
            $photo = ($img instanceof DOMElement) ? $this->normalize_src($this->image_src($img)) : '';
            if (!$player && $img instanceof DOMElement) $player = $this->clean_team($img->getAttribute('alt'));
            $player = $this->clean_team($player);
            if (!$player || mb_strlen($player, 'UTF-8') > 60) continue;
            $teams = [];
            $logos = [];
            foreach ($xpath->query('.//a[contains(@href,"/team/")]', $picked) ?: [] as $teamNode) {
                if (!$teamNode instanceof DOMElement) continue;
                $name = $this->clean_team($teamNode->textContent);
                $tImg = $xpath->query('.//img', $teamNode)->item(0);
                if (!$name && $tImg instanceof DOMElement) $name = $this->clean_team($tImg->getAttribute('alt'));
                $name = $this->clean_team($name);
                if ($name === '' || mb_strtolower($name, 'UTF-8') === mb_strtolower($player, 'UTF-8')) continue;
                if (!in_array($name, $teams, true)) {
                    $teams[] = $name;
                    $logos[] = ($tImg instanceof DOMElement) ? $this->normalize_src($this->image_src($tImg)) : '';
                }
                if (count($teams) >= 2) break;
            }
            if (count($teams) < 1) {
                foreach ($xpath->query('.//img[@alt]', $picked) ?: [] as $tImg) {
                    if (!$tImg instanceof DOMElement) continue;
                    $name = $this->clean_team($tImg->getAttribute('alt'));
                    if ($name === '' || mb_strtolower($name, 'UTF-8') === mb_strtolower($player, 'UTF-8')) continue;
                    if (!in_array($name, $teams, true)) {
                        $teams[] = $name;
                        $logos[] = $this->normalize_src($this->image_src($tImg));
                    }
                    if (count($teams) >= 2) break;
                }
            }
            if (!$player || count($teams) < 1) continue;
            $type = 'انتقال';
            if (preg_match('/انتقال آزاد|آزاد/u', $picked_text)) $type = 'انتقال آزاد';
            elseif (preg_match('/قرضی/u', $picked_text)) $type = 'قرضی';
            elseif (preg_match('/قطعی/u', $picked_text)) $type = 'انتقال قطعی';
            $when = '';
            if (preg_match('/([0-9۰-۹]+\s*(?:سال|ماه|هفته|روز|ساعت|دقیقه)\s*پیش)/u', $picked_text, $tm)) $when = $tm[1];
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
            if (count($out) >= 400) break;
        }
        return $this->merge_transfer_lists([], $out);
    }

    private function regex_transfers(string $html): array {
        $out = [];
        if (!preg_match_all('~<a[^>]+href=["\']([^"\']*/player/[^"\']+)["\'][^>]*>(.*?)</a>~isu', $html, $items, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($items as $it) {
            $pos = (int) $it[0][1];
            $window = substr($html, $pos, 2200);
            if (!preg_match('/انتقال|قرضی|آزاد|قطعی/u', $window)) continue;
            $player = $this->strip($it[2][0]);
            $photo = $this->normalize_src($this->regex_img($it[2][0]));
            if (!$player && preg_match('/alt=["\']([^"\']+)["\']/', $it[2][0], $am)) $player = $this->clean_team($am[1]);
            $player = $this->clean_team($player);
            if (!$player || mb_strlen($player, 'UTF-8') > 60) continue;
            $teams = [];
            $logos = [];
            if (preg_match_all('~<a[^>]+href=["\'][^"\']*/team/[^"\']+["\'][^>]*>(.*?)</a>~isu', $window, $tm)) {
                foreach ($tm[1] as $tbody) {
                    $name = $this->strip($tbody);
                    $logo = $this->normalize_src($this->regex_img($tbody));
                    if (!$name && preg_match('/alt=["\']([^"\']+)["\']/', $tbody, $am)) $name = $this->clean_team($am[1]);
                    $name = $this->clean_team($name);
                    if ($name === '' || mb_strtolower($name, 'UTF-8') === mb_strtolower($player, 'UTF-8')) continue;
                    if (!in_array($name, $teams, true)) {
                        $teams[] = $name;
                        $logos[] = $logo;
                    }
                    if (count($teams) >= 2) break;
                }
            }
            if (count($teams) < 1 && preg_match_all('/<img[^>]+alt=["\']([^"\']+)["\'][^>]*>/u', $window, $im)) {
                foreach ($im[1] as $alt) {
                    $name = $this->clean_team($alt);
                    if ($name === '' || mb_strtolower($name, 'UTF-8') === mb_strtolower($player, 'UTF-8')) continue;
                    if (preg_match('/missing|placeholder|default/i', $name)) continue;
                    if (!in_array($name, $teams, true)) {
                        $teams[] = $name;
                        $logos[] = '';
                    }
                    if (count($teams) >= 2) break;
                }
            }
            if (!$player || count($teams) < 1) continue;
            $type = 'انتقال';
            if (preg_match('/انتقال آزاد|آزاد/u', $window)) $type = 'انتقال آزاد';
            elseif (preg_match('/قرضی/u', $window)) $type = 'قرضی';
            elseif (preg_match('/قطعی/u', $window)) $type = 'انتقال قطعی';
            $when = '';
            if (preg_match('/([0-9۰-۹]+\s*(?:سال|ماه|هفته|روز|ساعت|دقیقه)\s*پیش)/u', $window, $tm2)) $when = $tm2[1];
            $out[] = [
                'player' => $player,
                'photo' => $photo,
                'from' => $teams[0] ?? '',
                'from_logo' => $logos[0] ?? '',
                'to' => $teams[1] ?? '',
                'to_logo' => $logos[1] ?? '',
                'type' => $type,
                'date' => $when,
                'href' => $this->normalize_href($it[1][0]),
            ];
            if (count($out) >= 400) break;
        }
        return $this->merge_transfer_lists([], $out);
    }

    private function merge_transfer_lists(array $old, array $new): array {
        $out = [];
        $seen = [];
        foreach (array_merge($old, $new) as $row) {
            if (!is_array($row) || ($row['player'] ?? '') === '') continue;
            $key = md5(mb_strtolower(trim(($row['player'] ?? '') . '|' . ($row['from'] ?? '') . '|' . ($row['to'] ?? '')), 'UTF-8'));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $row;
        }
        return $out;
    }

    private function parse_statistics(DOMXPath $xpath): array {
        $groups = [];
        $seen_row = [];
        foreach (['player' => "/player/", 'team' => "team-statistic"] as $kind => $needle) {
            $nodes = $xpath->query("//a[contains(@href,'" . $needle . "')]");
            if (!$nodes) continue;
            foreach ($nodes as $a) {
                if (!$a instanceof DOMElement) continue;
                $row = $this->statistic_row_from_anchor($xpath, $a, $kind);
                if (!$row) continue;
                $title = $this->nearest_stat_title($xpath, $a);
                if ($title === '') continue;
                $key = $this->statistic_group_key($title, $kind);
                $uniq = $key . '|' . mb_strtolower($row['name'], 'UTF-8');
                if (isset($seen_row[$uniq])) continue;
                $seen_row[$uniq] = true;
                if (!isset($groups[$key])) {
                    $groups[$key] = ['key' => $key, 'kind' => $kind, 'title' => $title, 'rows' => []];
                }
                if (count($groups[$key]['rows']) >= 20) continue;
                $row['rank'] = (string) (count($groups[$key]['rows']) + 1);
                $groups[$key]['rows'][] = $row;
            }
        }
        $out = [];
        foreach ($groups as $group) {
            if (!empty($group['rows'])) $out[] = $group;
        }
        return $out;
    }

    private function statistic_row_from_anchor(DOMXPath $xpath, DOMElement $a, string $kind): ?array {
        $href = $this->normalize_href($a->getAttribute('href'));
        if ($kind === 'player' && strpos($href, '/player/') === false) return null;
        if ($kind === 'team' && stripos($href, 'team-statistic') === false) return null;
        $box = $a;
        $picked = null;
        for ($i = 0; $i < 8 && $box; $i++, $box = $box->parentNode) {
            if (!$box instanceof DOMElement) continue;
            $query = ($kind === 'team')
                ? ".//a[contains(@href,'team-statistic')]"
                : ".//a[contains(@href,'/player/')]";
            $count = $xpath->query($query, $box)->length;
            if ($count !== 1) continue;
            $text = $this->clean($box->textContent);
            if ($kind === 'player' && preg_match('/انتقال|قرضی/u', $text)) return null;
            $picked = $box;
            break;
        }
        if (!$picked) return null;
        $img = $xpath->query('.//img', $a)->item(0);
        if (!$img instanceof DOMElement) $img = $xpath->query('.//img', $picked)->item(0);
        $name = $this->clean_team($a->textContent);
        if (!$name && $img instanceof DOMElement) $name = $this->clean_team($img->getAttribute('alt'));
        $name = preg_replace('/\s+\d+(?:[.,]\d+)?$/u', '', (string) $name);
        $name = $this->clean_team($name);
        if (!$name) return null;
        $blob = $this->fa_to_en($this->clean($picked->textContent));
        if (!preg_match('/(\d+(?:\.\d+)?)\s*$/u', $blob, $m)) return null;
        $value = $m[1];
        $team = '';
        $team_logo = '';
        if ($kind === 'player') {
            $teamNode = $xpath->query(".//a[contains(@href,'/team/')]", $picked)->item(0);
            if ($teamNode instanceof DOMElement) {
                $team = $this->clean_team($teamNode->textContent);
                if (mb_strtolower($team, 'UTF-8') === mb_strtolower($name, 'UTF-8')) $team = '';
                $tImg = $xpath->query('.//img', $teamNode)->item(0);
                if ($tImg instanceof DOMElement) $team_logo = $this->normalize_src($this->image_src($tImg));
            }
            if ($team === '') {
                $rest = $this->fa_to_en($this->clean($picked->textContent));
                $rest = preg_replace('/' . preg_quote($this->fa_to_en($name), '/') . '/u', '', $rest, 1);
                $rest = trim(preg_replace('/' . preg_quote($value, '/') . '\s*$/u', '', $rest));
                $rest = $this->clean_team($rest);
                if ($rest !== '' && mb_strlen($rest, 'UTF-8') <= 30 && !preg_match('/\\d/u', $rest) && mb_strtolower($rest, 'UTF-8') !== mb_strtolower($name, 'UTF-8')) $team = $rest;
            }
        }
        $photo = ($img instanceof DOMElement) ? $this->normalize_src($this->image_src($img)) : '';
        return [
            'rank' => '',
            'name' => $name,
            'team' => $team,
            'team_logo' => $team_logo,
            'value' => $value,
            'goals' => $value,
            'photo' => $photo,
            'href' => $href,
            'kind' => $kind,
        ];
    }

    private function nearest_stat_title(DOMXPath $xpath, DOMElement $node): string {
        $cur = $node;
        for ($i = 0; $i < 10 && $cur; $i++, $cur = $cur->parentNode) {
            if (!$cur instanceof DOMElement) continue;
            $prev = $cur->previousSibling;
            while ($prev) {
                if ($prev instanceof DOMElement) {
                    $nested = $xpath->query(".//a[contains(@href,'/player/') or contains(@href,'team-statistic')]", $prev);
                    if (!$nested || !$nested->length) {
                        $title = $this->normalize_stat_title($this->clean($prev->textContent));
                        if ($this->looks_like_stat_title($title)) return $title;
                    }
                }
                $prev = $prev->previousSibling;
            }
            foreach ($xpath->query('./h2|./h3|./h4|./h5|./strong|./span|./p|./div[1]', $cur) ?: [] as $el) {
                if (!$el instanceof DOMElement) continue;
                $nested = $xpath->query(".//a[contains(@href,'/player/') or contains(@href,'team-statistic')]", $el);
                if ($nested && $nested->length) continue;
                $title = $this->normalize_stat_title($this->clean($el->textContent));
                if ($this->looks_like_stat_title($title)) return $title;
            }
        }
        return '';
    }

    private function looks_like_stat_title(string $title): bool {
        $title = trim($title);
        if ($title === '' || mb_strlen($title, 'UTF-8') > 55) return false;
        if (preg_match('/^(همه(?: آمارها)?|آمار (?:رقابت|بازیکنان|تیم‌ها)|نوع:.*|آخرین.*|Loading.*)$/u', $title)) return false;
        if (preg_match('/جدول|هفته|رتبه|نام تیم|برنامه/u', $title)) return false;
        return (bool) preg_match('/گل|پاس|کارت|کلین|متریکا|شوت|امید|مالکیت|خطا|کرنر|آفساید|پنالتی|سیو|تکل|سانتر|موقعیت|نمره|دفع|بازپس|توپ بلند|گل‌زده|گل خورده/u', $title);
    }

    private function normalize_stat_title(string $title): string {
        $title = $this->clean($title);
        $title = preg_replace('/^#+\s*/', '', $title);
        $aliases = [
            'گل‌' => 'گل',
            'کلین شیت' => 'کلین‌شیت',
            'کلين‌شيت' => 'کلین‌شیت',
            'گل+پاس گل' => 'گل + پاس گل',
            'گل +پاس گل' => 'گل + پاس گل',
        ];
        return $aliases[$title] ?? $title;
    }

    private function statistic_group_key(string $title, string $kind): string {
        $map = [
            'گل' => 'goals',
            'پاس گل' => 'assists',
            'گل + پاس گل' => 'goals_assists',
            'نمره متریکا' => 'metrica',
            'کلین‌شیت' => 'clean_sheet',
            'امید گل' => 'xg',
            'امید پاس گل' => 'xa',
            'پنالتی گل کرده' => 'penalties_scored',
            'پنالتی از دست داده' => 'penalties_missed',
            'کارت زرد' => 'yellow',
            'کارت قرمز' => 'red',
            'گل زده' => 'goals_for',
            'گل خورده' => 'goals_against',
        ];
        $slug = $map[$title] ?? ('s' . substr(md5($title), 0, 10));
        return ($kind === 'team' ? 'team_' : '') . $slug;
    }

    private function regex_statistics(string $html): array {
        $groups = [];
        $pattern = '/<a[^>]+href=["\']([^"\']*(?:\/player\/|team-statistic)[^"\']*)["\'][^>]*>(.*?)<\/a>/isu';
        if (!preg_match_all($pattern, $html, $items, PREG_SET_ORDER)) return [];
        foreach ($items as $it) {
            $href = $this->normalize_href($it[1] ?? '');
            $body = $it[2] ?? '';
            $kind = (stripos($href, 'team-statistic') !== false) ? 'team' : 'player';
            if ($kind === 'player' && strpos($href, '/player/') === false) continue;
            $name = $this->strip($body);
            $alt = '';
            if (preg_match('/alt=["\']([^"\']+)["\']/', $body, $am)) $alt = $this->clean_team($am[1]);
            if (!$name) $name = $alt;
            $name = preg_replace('/\s+\d+(?:[.,]\d+)?$/u', '', $name);
            $name = $this->clean_team($name);
            if (!$name) continue;
            $photo = $this->normalize_src($this->regex_img($body));
            $plain = $this->fa_to_en($this->strip($body));
            $value = '';
            if (preg_match('/(\d+(?:\.\d+)?)\s*$/u', $plain, $vm)) $value = $vm[1];
            if ($value === '') continue;
            $start = max(0, (int) strpos($html, $it[0]) - 1200);
            $window = substr($html, $start, 1200);
            $title = ($kind === 'team') ? 'آمار تیم‌ها' : 'گل';
            if (preg_match_all('/>([^<]{2,55})</u', $window, $tm)) {
                foreach (array_reverse($tm[1]) as $cand) {
                    $cand = $this->normalize_stat_title($this->clean($cand));
                    if ($this->looks_like_stat_title($cand)) { $title = $cand; break; }
                }
            }
            $key = $this->statistic_group_key($title, $kind);
            if (!isset($groups[$key])) $groups[$key] = ['key' => $key, 'kind' => $kind, 'title' => $title, 'rows' => []];
            $dup = false;
            foreach ($groups[$key]['rows'] as $exist) {
                if (mb_strtolower($exist['name'], 'UTF-8') === mb_strtolower($name, 'UTF-8')) { $dup = true; break; }
            }
            if ($dup || count($groups[$key]['rows']) >= 20) continue;
            $groups[$key]['rows'][] = [
                'rank' => (string) (count($groups[$key]['rows']) + 1),
                'name' => $name,
                'team' => '',
                'value' => $value,
                'goals' => $value,
                'photo' => $photo,
                'href' => $href,
                'kind' => $kind,
            ];
        }
        return array_values(array_filter($groups, function($g) { return !empty($g['rows']); }));
    }

    private function statistics_as_scorers(array $statistics): array {
        foreach ($statistics as $group) {
            if (($group['key'] ?? '') === 'goals' || ($group['title'] ?? '') === 'گل') return $group['rows'] ?? [];
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
            $plain = $this->fa_to_en($text);
            $kickoff = $this->extract_kickoff($plain);
            if (preg_match('/(\d+)\s*[-–]\s*(\d+)/u', $plain, $m)) $score = $m[1] . ' - ' . $m[2];
            if (preg_match('/زنده|نیمه|پایان|تمام/u', $text, $sm)) $status = $sm[0];
            if ($status === '' && $kickoff !== '') $status = $kickoff;
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
        $standings = [];
        $seen = [];
        if (!preg_match_all('/<tr[^>]*>(.*?)<\/tr>/isu', $html, $rows)) return [];
        foreach ($rows[1] as $row) {
            if (stripos($row, '/team/') === false) continue;
            $team = '';
            $logo = '';
            if (preg_match('/<a[^>]+href=["\'][^"\']*\/team\/[^"\']+["\'][^>]*>(.*?)<\/a>/isu', $row, $tm)) {
                $team = $this->strip($tm[1]);
            }
            if (preg_match('/alt=["\']([^"\']+)["\']/', $row, $am)) {
                if (!$team) $team = $this->clean_team($am[1]);
            }
            $logo = $this->normalize_src($this->regex_img($row));
            $team = preg_replace('/^\s*\d+\s*/u', '', $this->clean_team($team));
            if (!$team || preg_match('/^(نام تیم|تیم|رتبه)$/u', $team)) continue;
            $tokens = $this->extract_stat_tokens($this->strip($row));
            if (count($tokens) < 6) continue;
            $mapped = $this->map_stat_tokens($tokens);
            $key = mb_strtolower($team, 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $mapped['team'] = $team;
            $mapped['logo'] = $logo;
            $mapped['movement'] = 'equal';
            $standings[] = $mapped;
        }
        if ($this->standings_are_cloned($standings)) return [];
        return $this->renumber_standings($standings);
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
