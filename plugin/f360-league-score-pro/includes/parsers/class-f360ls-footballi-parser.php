<?php
if (!defined('ABSPATH')) { exit; }

class F360LS_Footballi_Parser {
    private string $html;
    private array $source;
    private array $json_nodes = [];

    public function __construct(string $html, array $source = []) {
        $this->html = $html;
        $this->source = $source;
    }

    public static function looks_like(string $html, array $source = []): bool {
        $url = strtolower((string) ($source['url'] ?? ''));
        if (strpos($url, 'footballi.net') !== false) return true;
        return (strpos($html, 'footballi.net') !== false)
            || (strpos($html, 'cdn.oddrun.ir') !== false)
            || (strpos($html, 'فوتبالی') !== false && strpos($html, '/competition/') !== false);
    }

    public function parse(array $league = []): array {
        $this->json_nodes = $this->extract_json_nodes();
        $team_logo_map = $this->team_logo_map();

        $league_meta = $this->parse_league_meta($league);
        $standings = $this->dedupe_standings($this->enrich_standings_logos(array_merge(
            $this->parse_standings_from_json(),
            $this->parse_standings_from_footballi_dom(),
            $this->parse_standings_from_dom(),
            $this->parse_standings_from_text()
        ), $team_logo_map));

        $weeks = $this->parse_weeks_from_json();
        $footballi_weeks = $this->parse_matches_from_footballi_dom();
        if ($footballi_weeks) $weeks = array_merge($weeks, $footballi_weeks);
        // Footballi pages contain unrelated match links in news/footer blocks. Only
        // fixtureContainer/one-game entries belong to the selected competition.
        // Do not fall back to page-wide match-link or text scanning here.

        $weeks = $this->dedupe_weeks($this->enrich_weeks_logos($weeks, $team_logo_map));

        $matches = [];
        foreach ($weeks as $week) {
            foreach (($week['matches'] ?? []) as $match) $matches[] = $match;
        }
        $matches = $this->dedupe_matches($matches);

        if (!$weeks && $matches) $weeks[] = ['title' => 'بازی‌ها', 'matches' => $matches];

        return [
            'league' => $league_meta,
            'weeks' => $weeks,
            'matches' => $matches,
            'standings' => $standings,
            'top_scorers' => $this->parse_top_scorers_from_dom(),
            'news' => $this->parse_news_from_dom(),
            'last_update' => $this->last_update(),
            'description' => '',
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

    private function parse_league_meta(array $configured): array {
        $title = $configured['title'] ?? '';
        $logo = $configured['logo'] ?? '';

        $dom = $this->dom();
        if ($dom) {
            $xpath = new DOMXPath($dom);
            $h1 = $this->first_text($xpath, '//h1');
            if ($h1) $title = $this->clean_title($h1);

            $ogTitle = $this->meta_content($xpath, 'og:title') ?: $this->meta_content($xpath, 'twitter:title');
            if (!$title && $ogTitle) $title = $this->clean_title($ogTitle);

            // Prefer the competition crest. Footballi's og:image is often its own
            // site branding or a news image, not the selected league logo.
            $img = $xpath->query('//img[contains(@src,"/competitions/") or contains(@data-src,"/competitions/") or contains(@src,"cdn.oddrun.ir/competitions")]')->item(0);
            if (!$logo && $img instanceof DOMElement) $logo = $this->image_src($img);

            $ogImage = $this->meta_content($xpath, 'og:image') ?: $this->meta_content($xpath, 'twitter:image');
            if (!$logo && $ogImage && preg_match('~/competitions?/|cdn\.oddrun\.ir/competitions/~i', $ogImage)) $logo = $ogImage;
        }

        if (!$title) {
            if (preg_match('~<title[^>]*>(.*?)</title>~isu', $this->html, $m)) $title = $this->clean_title($m[1]);
            elseif (preg_match('~#\s*([^\r\n<]+)~u', $this->text_content($this->html), $m)) $title = $this->clean_title($m[1]);
        }

        return [
            'title' => $title ?: ($configured['title'] ?? 'رقابت'),
            'logo' => $this->normalize_src($logo),
        ];
    }

    private function parse_standings_from_json(): array {
        $rows = [];
        foreach ($this->json_nodes as $node) {
            $this->walk_json($node, function($value) use (&$rows) {
                if (!is_array($value) || !$this->is_list($value) || count($value) < 2) return;
                $candidate = [];
                foreach ($value as $item) {
                    $row = $this->normalize_standing_row($item);
                    if ($row) $candidate[] = $row;
                }
                if (count($candidate) >= 2) $rows = array_merge($rows, $candidate);
            });
        }
        return $rows;
    }

    private function normalize_standing_row($row): ?array {
        if (!is_array($row)) return null;

        $team = $this->value($row, [
            'team.name','team.title','team.persianName','team.faName','team.translations.fa',
            'participant.name','club.name','name','title'
        ]);
        $logo = $this->value($row, [
            'team.logo','team.image','team.icon','team.badge','team.logoUrl','participant.logo','club.logo','logo','image'
        ]);
        $rank = $this->value($row, ['rank','position','pos','standing','order','place']);
        $played = $this->value($row, ['played','matches','games','overall.played','stats.played','p','mp']);
        $won = $this->value($row, ['won','wins','overall.won','stats.won','w']);
        $draw = $this->value($row, ['draw','draws','overall.draw','stats.draw','d']);
        $lost = $this->value($row, ['lost','loss','losses','overall.lost','stats.lost','l']);
        $diff = $this->value($row, ['diff','goalDifference','goal_difference','gd','stats.diff']);
        $goals = $this->value($row, ['goals','goal','goalsForAgainst','forAgainst','gfga','stats.goals']);
        $points = $this->value($row, ['points','point','pts','score','stats.points']);
        $group = $this->value($row, ['group.name','group.title','group','stage.name','stage.title','round.name','round.title']);

        if (!$team || ($played === '' && $points === '')) return null;

        return [
            'rank' => $this->clean_scalar($rank),
            'team' => $this->clean_team($team),
            'logo' => $this->normalize_src((string) $logo),
            'played' => $this->clean_scalar($played),
            'won' => $this->clean_scalar($won),
            'draw' => $this->clean_scalar($draw),
            'lost' => $this->clean_scalar($lost),
            'diff' => $this->clean_scalar($diff),
            'goals' => $this->clean_scalar($goals),
            'points' => $this->clean_scalar($points),
            'group' => $this->clean($group),
            'movement' => 'equal',
        ];
    }

    private function parse_standings_from_footballi_dom(): array {
        $dom = $this->dom();
        if (!$dom) return [];
        $xpath = new DOMXPath($dom);
        $rows = [];
        foreach ($xpath->query('//*[contains(@class,"standing") or contains(local-name(),"standing") or contains(@class,"ranking") or contains(@class,"table")]//a[contains(@href,"/team/")] | //section//a[contains(@href,"/team/")]') ?: [] as $teamNode) {
            if (!$teamNode instanceof DOMElement) continue;
            $rowNode = $this->best_standing_container($teamNode, $xpath);
            if (!$rowNode) continue;
            $rowText = $this->node_text_tokens($rowNode);
            if (mb_strlen($rowText, 'UTF-8') > 900 || preg_match('~/match/|ویدئوها|پخش زنده~u', $rowText)) continue;

            $team = $this->clean_team($this->node_text_tokens($teamNode));
            if (!$team) {
                $imgAlt = $xpath->query('.//img[@alt]', $teamNode)->item(0);
                if ($imgAlt instanceof DOMElement) $team = $this->clean_team($imgAlt->getAttribute('alt'));
            }
            if (!$team) continue;

            $img = $xpath->query('.//img', $teamNode)->item(0) ?: $xpath->query('.//img', $rowNode)->item(0);
            $logo = ($img instanceof DOMElement) ? $this->image_src($img) : '';

            $numbers = [];
            if (preg_match_all('/[-+]?\d+(?:\s*[-–]\s*\d+)?|[-+]?[۰-۹]+(?:\s*[-–]\s*[۰-۹]+)?/u', $rowText, $m)) {
                foreach ($m[0] as $num) $numbers[] = $this->fa_to_en($num);
            }
            if (count($numbers) < 7) continue;

            $rank = array_shift($numbers);
            $points = array_pop($numbers);
            $played = $numbers[0] ?? '';
            $won = $numbers[1] ?? '';
            $draw = $numbers[2] ?? '';
            $lost = $numbers[3] ?? '';
            $diff = $numbers[4] ?? '';
            $goals = $numbers[5] ?? '';

            $rows[] = [
                'rank' => $rank,
                'team' => $team,
                'logo' => $this->normalize_src($logo),
                'group' => $this->detect_group_for_node($rowNode, $xpath),
                'played' => $played,
                'won' => $won,
                'draw' => $draw,
                'lost' => $lost,
                'diff' => $diff,
                'goals' => $goals,
                'points' => $points,
                'movement' => 'equal',
            ];
        }
        return $rows;
    }

    private function best_standing_container(DOMElement $teamNode, DOMXPath $xpath): ?DOMElement {
        $node = $teamNode;
        for ($i = 0; $i < 6 && $node instanceof DOMElement; $i++, $node = $node->parentNode) {
            $text = $this->node_text_tokens($node);
            if (mb_strlen($text, 'UTF-8') > 40 && mb_strlen($text, 'UTF-8') < 900) {
                if (preg_match_all('/\d+|[۰-۹]+/u', $text, $m) && count($m[0]) >= 7) return $node;
            }
        }
        return null;
    }

    private function parse_standings_from_dom(): array {
        $dom = $this->dom();
        if (!$dom) return [];
        $xpath = new DOMXPath($dom);
        $rows = [];

        foreach ($xpath->query('//tr[.//a[contains(@href,"/team/")]]') ?: [] as $tr) {
            if (!$tr instanceof DOMElement) continue;
            $cells = [];
            foreach ($xpath->query('./th|./td', $tr) ?: [] as $cell) {
                $text = $this->node_text_tokens($cell);
                if ($text !== '') $cells[] = $text;
            }
            if (count($cells) < 5) continue;

            $teamNode = $xpath->query('.//a[contains(@href,"/team/")]', $tr)->item(0);
            if (!$teamNode instanceof DOMElement) continue;
            $team = $this->clean_team($this->node_text_tokens($teamNode));
            if (!$team) continue;

            $img = $xpath->query('.//img', $teamNode)->item(0) ?: $xpath->query('.//img', $tr)->item(0);
            $logo = ($img instanceof DOMElement) ? $this->image_src($img) : '';

            $rank = '';
            if (preg_match('/^\s*([0-9۰-۹]+)\b/u', $cells[0], $m)) $rank = $this->fa_to_en($m[1]);

            $numbers = [];
            foreach ($cells as $cell) {
                $cell = str_replace($team, '', $cell);
                if (preg_match_all('/[-+]?\d+(?:\s*[-–]\s*\d+)?|[-+]?[۰-۹]+(?:\s*[-–]\s*[۰-۹]+)?/u', $cell, $m)) {
                    foreach ($m[0] as $num) $numbers[] = $this->fa_to_en($num);
                }
            }
            if (!$rank && !empty($numbers)) $rank = array_shift($numbers);
            if (count($numbers) < 6) continue;

            $points = array_pop($numbers);
            $played = $numbers[0] ?? '';
            $won = $numbers[1] ?? '';
            $draw = $numbers[2] ?? '';
            $lost = $numbers[3] ?? '';
            $diff = $numbers[4] ?? '';
            $goals = $numbers[5] ?? '';

            $rows[] = [
                'rank' => $rank,
                'team' => $team,
                'logo' => $this->normalize_src($logo),
                'group' => $this->detect_group_for_node($tr, $xpath),
                'played' => $played,
                'won' => $won,
                'draw' => $draw,
                'lost' => $lost,
                'diff' => $diff,
                'goals' => $goals,
                'points' => $points,
                'movement' => 'equal',
            ];
        }
        return $rows;
    }

    private function parse_standings_from_text(): array {
        $text = $this->text_content($this->html);
        $rows = [];
        if (!preg_match('/جدول\s+رده\s*بندی|بازی\s*برد\s*مساوی\s*باخت/u', $text)) return [];

        $lines = array_values(array_filter(array_map([$this, 'clean'], preg_split('/\R+/u', $text))));
        $current_group = '';
        foreach ($lines as $line) {
            if (preg_match('/^(گروه\s+[^\s]+|Group\s+[A-Z0-9]+)/iu', $line, $gm)) {
                $current_group = $this->clean($gm[1]);
                continue;
            }
            if (!preg_match('/^([0-9۰-۹]+)\s+(.+?)\s+([0-9۰-۹]+)\s+([0-9۰-۹]+)\s+([0-9۰-۹]+)\s+([0-9۰-۹]+)\s+([-+0-9۰-۹]+)\s+([0-9۰-۹]+\s*[-–]\s*[0-9۰-۹]+)\s+([0-9۰-۹]+)$/u', $line, $m)) continue;
            $rows[] = [
                'rank' => $this->fa_to_en($m[1]),
                'team' => $this->clean_team($m[2]),
                'logo' => '',
                'group' => $current_group,
                'played' => $this->fa_to_en($m[3]),
                'won' => $this->fa_to_en($m[4]),
                'draw' => $this->fa_to_en($m[5]),
                'lost' => $this->fa_to_en($m[6]),
                'diff' => $this->fa_to_en($m[7]),
                'goals' => $this->fa_to_en($m[8]),
                'points' => $this->fa_to_en($m[9]),
                'movement' => 'equal',
            ];
        }
        return $rows;
    }

    private function parse_weeks_from_json(): array {
        $matches = [];
        foreach ($this->json_nodes as $node) {
            $this->walk_json($node, function($value) use (&$matches) {
                $match = $this->normalize_match($value);
                if ($match) $matches[] = $match;
            });
        }
        $matches = $this->dedupe_matches($matches);
        return $matches ? [['title' => $this->default_matches_title(), 'matches' => $matches]] : [];
    }

    private function normalize_match($item): ?array {
        if (!is_array($item)) return null;

        $home = $this->team_name($this->value_raw($item, ['home','homeTeam','host','team1','localTeam','home_team'])) ?: $this->value($item, ['home.name','homeTeam.name','host.name','team1.name','localTeam.name','home_team.name','homeTitle','home_name']);
        $away = $this->team_name($this->value_raw($item, ['away','awayTeam','guest','team2','visitorTeam','away_team'])) ?: $this->value($item, ['away.name','awayTeam.name','guest.name','team2.name','visitorTeam.name','away_team.name','awayTitle','away_name']);
        if (!$home || !$away) return null;

        $homeScore = $this->value($item, ['home_score','homeScore','homeTeamScore','home.goals','home.score','homeTeam.score','score.home','result.home','result.homeScore','result.homeTeamScore','scores.home','scores.homeScore']);
        $awayScore = $this->value($item, ['away_score','awayScore','awayTeamScore','away.goals','away.score','awayTeam.score','score.away','result.away','result.awayScore','result.awayTeamScore','scores.away','scores.awayScore']);
        $score = $this->value($item, ['score','result','final_score','finalScore','matchScore','scores.ft','scores.fullTime','result.score']);
        if (is_array($score)) $score = '';
        if ($score === '' && $homeScore !== '' && $awayScore !== '') $score = $homeScore . ' - ' . $awayScore;
        if ($score === '') $score = '—';

        $startDate = $this->value($item, ['startDate','start_at','startAt','kickoff','date']);
        $status = $this->value($item, ['status','state','matchStatus','statusTitle','time','date','startTime','kickoff','start_at','startAt','minute']);
        if (is_array($status)) $status = '';
        if ($status === '') $status = ($score !== '—') ? 'پایان' : 'زمان نامشخص';

        $href = $this->value($item, ['href','url','link','permalink','slug']);

        return [
            'home' => $this->clean_team($home),
            'away' => $this->clean_team($away),
            'score' => $this->clean_score((string) $score),
            'status' => $this->clean_status((string) $status),
            'status_type' => $this->status_type((string) $status, (string) $score),
            'date' => $this->match_date((string) ($startDate ?: $status)),
            'home_logo' => $this->normalize_src($this->team_logo($this->value_raw($item, ['home','homeTeam','host','team1','localTeam','home_team']))),
            'away_logo' => $this->normalize_src($this->team_logo($this->value_raw($item, ['away','awayTeam','guest','team2','visitorTeam','away_team']))),
            'href' => $this->normalize_href((string) $href),
        ];
    }

    private function parse_matches_from_footballi_dom(): array {
        $dom = $this->dom();
        if (!$dom) return [];
        $xpath = new DOMXPath($dom);
        $matches = [];
        foreach ($xpath->query('//*[@id="fixtureContainer"]//*[contains(@class,"one-game")] | //*[contains(@class,"one-game")][.//a[contains(@href,"/match/")]]') ?: [] as $game) {
            if (!$game instanceof DOMElement) continue;
            $match = $this->match_from_footballi_game($game, $xpath);
            if ($match) $matches[] = $match;
        }
        $matches = $this->dedupe_matches($matches);
        return $matches ? [['title' => $this->default_matches_title(), 'matches' => $matches]] : [];
    }

    private function match_from_footballi_game(DOMElement $game, DOMXPath $xpath): ?array {
        $hrefNode = $xpath->query('.//a[contains(@href,"/match/")]', $game)->item(0);
        $href = ($hrefNode instanceof DOMElement) ? $hrefNode->getAttribute('href') : '';
        $urlMeta = $xpath->query('.//meta[@itemprop="url"]', $game)->item(0);
        if (!$href && $urlMeta instanceof DOMElement) $href = $urlMeta->getAttribute('content');

        $startMeta = $xpath->query('.//meta[@itemprop="startDate"]', $game)->item(0);
        $startDate = ($startMeta instanceof DOMElement) ? $startMeta->getAttribute('content') : '';

        $teamNodes = [];
        foreach ($xpath->query('.//a[contains(@href,"/team/")]', $game) ?: [] as $a) if ($a instanceof DOMElement) $teamNodes[] = $a;
        $teams = [];
        $logos = [];
        foreach ($teamNodes as $teamNode) {
            $name = $this->clean_team($this->node_text_tokens($teamNode));
            $img = $xpath->query('.//img', $teamNode)->item(0);
            if (!$name && $img instanceof DOMElement) $name = $this->clean_team($img->getAttribute('alt'));
            if ($name) {
                $teams[] = $name;
                $logos[] = ($img instanceof DOMElement) ? $this->image_src($img) : '';
            }
        }

        if (count($teams) < 2) {
            foreach ($xpath->query('.//img[@alt]', $game) ?: [] as $img) {
                if (!$img instanceof DOMElement) continue;
                $alt = $this->clean_team($img->getAttribute('alt'));
                if ($alt && !in_array($alt, $teams, true) && !preg_match('/default|placeholder|icon/i', $this->image_src($img))) {
                    $teams[] = $alt;
                    $logos[] = $this->image_src($img);
                }
            }
        }

        $tokens = array_values(array_filter($this->node_tokens($game), fn($t) => !$this->is_meta_token($t)));
        $score = '';
        $status = '';
        foreach ($tokens as $token) {
            if (!$score && $this->is_score_token($token)) $score = $token;
            if (!$status && $this->is_status_token($token)) $status = $token;
        }
        if (!$score) {
            foreach ($tokens as $token) if ($this->is_time_token($token)) { $status = $token; break; }
        }

        if (count($teams) < 2) {
            $idx = -1;
            foreach ($tokens as $i => $token) if ($this->is_score_token($token) || $this->is_time_token($token)) { $idx = $i; break; }
            if ($idx > 0) {
                $home = $this->previous_team_token($tokens, $idx - 1);
                $away = $this->next_team_token($tokens, $idx + 1);
                if ($home && $away) $teams = [$home, $away];
            }
        }

        if (count($teams) < 2) return null;
        if (!$score) $score = '—';
        if (!$status) $status = $startDate ?: (($score !== '—') ? 'پایان' : 'زمان نامشخص');

        return [
            'home' => $this->clean_team($teams[0]),
            'away' => $this->clean_team($teams[1]),
            'score' => $this->clean_score($score),
            'status' => $this->clean_status($status),
            'status_type' => $this->status_type($status, $score),
            'date' => $this->match_date($startDate),
            'home_logo' => $this->normalize_src($logos[0] ?? ''),
            'away_logo' => $this->normalize_src($logos[1] ?? ''),
            'href' => $this->normalize_href($href),
        ];
    }

    private function parse_matches_from_dom(): array {
        $dom = $this->dom();
        if (!$dom) return [];
        $xpath = new DOMXPath($dom);
        $matches = [];

        foreach ($xpath->query('//a[contains(@href,"/match/")]') ?: [] as $a) {
            if (!$a instanceof DOMElement) continue;
            $match = $this->match_from_node($a, $xpath);
            if ($match) $matches[] = $match;
        }
        $matches = $this->dedupe_matches($matches);
        return $matches ? [['title' => $this->default_matches_title(), 'matches' => $matches]] : [];
    }

    private function match_from_node(DOMElement $node, DOMXPath $xpath): ?array {
        $startMeta = $xpath->query('.//meta[@itemprop="startDate"]', $node)->item(0);
        $startDate = ($startMeta instanceof DOMElement) ? $startMeta->getAttribute('content') : ($node->getAttribute('data-date') ?: $node->getAttribute('data-start-date'));
        $tokens = $this->node_tokens($node);
        $tokens = array_values(array_filter($tokens, fn($t) => !$this->is_meta_token($t)));
        if (count($tokens) < 3) return null;

        $scoreIndex = -1;
        foreach ($tokens as $i => $token) {
            if ($this->is_score_token($token) || $this->is_time_token($token)) { $scoreIndex = $i; break; }
        }
        if ($scoreIndex < 1 || empty($tokens[$scoreIndex + 1])) return null;

        $home = $this->previous_team_token($tokens, $scoreIndex - 1);
        $score = $tokens[$scoreIndex];
        $status = '';
        $awayStart = $scoreIndex + 1;
        if (isset($tokens[$awayStart]) && $this->is_status_token($tokens[$awayStart])) {
            $status = $tokens[$awayStart];
            $awayStart++;
        }
        $away = $this->next_team_token($tokens, $awayStart);
        if (!$home || !$away || $home === $away) return null;

        if ($this->is_time_token($score) && !$this->is_score_token($score)) {
            $status = $score;
            $score = '—';
        }
        if (!$status) $status = ($score !== '—') ? 'پایان' : 'زمان نامشخص';

        $imgs = [];
        foreach ($xpath->query('.//img', $node) ?: [] as $img) {
            if ($img instanceof DOMElement) $imgs[] = $this->image_src($img);
        }

        return [
            'home' => $this->clean_team($home),
            'away' => $this->clean_team($away),
            'score' => $this->clean_score($score),
            'status' => $this->clean_status($status),
            'status_type' => $this->status_type($status, $score),
            'date' => $this->match_date($startDate),
            'home_logo' => $this->normalize_src($imgs[0] ?? ''),
            'away_logo' => $this->normalize_src($imgs[1] ?? ''),
            'href' => $this->normalize_href($node->getAttribute('href')),
        ];
    }

    private function parse_matches_from_text(): array {
        $text = $this->text_content($this->html);
        $lines = array_values(array_filter(array_map([$this, 'clean'], preg_split('/\R+/u', $text))));
        $matches = [];
        $currentCompetition = '';

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (preg_match('/^#{2,4}\s*(.+)$/u', $line, $m)) {
                $currentCompetition = $this->clean($m[1]);
                continue;
            }
            if (!$this->is_score_token($line) && !$this->is_time_token($line)) continue;
            $near = implode(' ', array_slice($lines, max(0, $i - 3), 7));
            if (preg_match('/comment|remove_red_eye|play_arrow|visibility|ویدئو|ویدیو|خبر|مصاحبه|خلاصه بازی/u', $near)) continue;
            $home = $this->previous_line_team($lines, $i - 1);
            $away = $this->next_line_team($lines, $i + 1);
            if (!$home || !$away || $home === $away) continue;
            $score = $line;
            $status = $lines[$i + 1] ?? '';
            if ($this->is_time_token($score) && !$this->is_score_token($score)) {
                $status = $score;
                $score = '—';
            } elseif (!$this->is_status_token($status)) {
                $status = ($score !== '—') ? 'پایان' : 'زمان نامشخص';
            }
            $matches[] = [
                'home' => $this->clean_team($home),
                'away' => $this->clean_team($away),
                'score' => $this->clean_score($score),
                'status' => $this->clean_status($status),
                'status_type' => $this->status_type($status, $score),
                'home_logo' => '',
                'away_logo' => '',
                'href' => '',
                '_competition' => $currentCompetition,
            ];
        }

        $matches = $this->dedupe_matches($matches);
        if (!$matches) return [];
        return [['title' => $this->default_matches_title(), 'matches' => array_map(function($m) { unset($m['_competition']); return $m; }, $matches)]];
    }

    private function parse_news_from_dom(): array {
        $dom = $this->dom();
        if (!$dom) return [];
        $xpath = new DOMXPath($dom);
        $items = [];
        foreach ($xpath->query('//a[contains(@href,"/news/r/") or contains(@href,"/video/")]') ?: [] as $a) {
            if (!$a instanceof DOMElement) continue;
            $title = $this->node_text_tokens($a);
            $title = preg_replace('/(play_arrow|remove_red_eye|comment|visibility|\d+:\d+|\d+\s*ساعت قبل|\d+\s*دقیقه قبل|یک روز قبل|امروز|دیروز)/u', ' ', $title);
            $title = $this->clean($title);
            if (mb_strlen($title, 'UTF-8') < 8) continue;
            $img = $xpath->query('.//img', $a)->item(0);
            $items[] = [
                'title' => mb_substr($title, 0, 140, 'UTF-8'),
                'summary' => mb_substr($title, 140, 180, 'UTF-8'),
                'image' => ($img instanceof DOMElement) ? $this->normalize_src($this->image_src($img)) : '',
                'href' => $this->normalize_href($a->getAttribute('href')),
            ];
            if (count($items) >= 24) break;
        }
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = md5($item['href'] . '|' . $item['title']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $item;
        }
        return $out;
    }

    private function parse_top_scorers_from_dom(): array {
        $dom = $this->dom();
        if (!$dom) return [];
        $xpath = new DOMXPath($dom);
        $rows = [];

        $players = $xpath->query('//a[(contains(@class,"top-scorers-player") or contains(@href,"/player/")) and (.//*[contains(@class,"player-scores")] or .//*[contains(@class,"player-pic")])]') ?: [];
        foreach ($players as $a) {
            if (!$a instanceof DOMElement) continue;

            $rank = $this->first_text($xpath, './/*[contains(@class,"player-rank")]', $a);
            $name = $this->first_text($xpath, './/*[@itemprop="name" or contains(@class,"player-name")]', $a);
            if (!$name) $name = $this->clean($a->getAttribute('title'));

            $playerImg = $xpath->query('.//img[contains(@class,"player-pic") or @itemprop="image"]', $a)->item(0);
            if ($playerImg instanceof DOMElement) {
                if (!$name) $name = $this->clean($playerImg->getAttribute('alt'));
                $photo = $this->normalize_src($this->image_src($playerImg));
            } else {
                $photo = '';
            }

            $teamNode = $xpath->query('.//*[contains(@class,"player-team")]', $a)->item(0);
            $team = '';
            $teamLogo = '';
            if ($teamNode instanceof DOMElement) {
                $team = $this->first_text($xpath, './/*[contains(@class,"name")]', $teamNode);
                $teamImg = $xpath->query('.//img', $teamNode)->item(0);
                if ($teamImg instanceof DOMElement) {
                    if (!$team) $team = $this->clean($teamImg->getAttribute('alt'));
                    $teamLogo = $this->normalize_src($this->image_src($teamImg));
                }
            }

            $scoreNode = $xpath->query('.//*[contains(@class,"player-scores")]', $a)->item(0);
            $scoreNumbers = [];
            $metric = 'goals';
            if ($scoreNode instanceof DOMElement) {
                $scoreClassText = $scoreNode->textContent;
                if ($xpath->query('.//*[contains(@class,"assist-score")]', $scoreNode)->length > 0) $metric = 'assists';
                if ($xpath->query('.//*[contains(@class,"total-score") or contains(@class,"sum-score")]', $scoreNode)->length > 0) $metric = 'total';
                if (preg_match_all('/\d+|[۰-۹]+/u', $scoreClassText, $m)) {
                    foreach ($m[0] as $num) $scoreNumbers[] = $this->fa_to_en($num);
                }
            }
            $headerText = $this->clean($this->first_text($xpath, 'ancestor::*[contains(@class,"top-scorers-container")][1]//*[contains(@class,"top-player-header")]', $a));
            if (preg_match('/پاس/u', $headerText)) $metric = 'assists';
            if (preg_match('/مجموع/u', $headerText)) $metric = 'total';
            if (!$scoreNumbers && preg_match_all('/\d+|[۰-۹]+/u', $this->node_text_tokens($a), $m)) {
                foreach ($m[0] as $num) $scoreNumbers[] = $this->fa_to_en($num);
            }

            if (!$rank && $scoreNumbers) $rank = array_shift($scoreNumbers);
            $goals = $scoreNumbers ? end($scoreNumbers) : '';
            $penalty = count($scoreNumbers) >= 2 ? $scoreNumbers[count($scoreNumbers) - 2] : '';

            $name = $this->clean_team($name);
            if (!$name || !$goals) continue;
            $href = $this->normalize_href($a->getAttribute('href'));
            if (!$photo && preg_match('~/player/(\d+)/~', $href, $pm)) {
                $photo = 'https://cdn.oddrun.ir/players/150/' . $pm[1] . '.png';
            }

            $rows[] = [
                'rank' => $this->clean_scalar($rank ?: (count($rows) + 1)),
                'name' => $name,
                'team' => $this->clean_team($team),
                'team_logo' => $teamLogo,
                'penalty' => $this->clean_scalar($penalty),
                'goals' => $metric === 'goals' ? $this->clean_scalar($goals) : '',
                'assists' => $metric === 'assists' ? $this->clean_scalar($goals) : '',
                'total' => $metric === 'total' ? $this->clean_scalar($goals) : '',
                'metric' => $metric,
                'value' => $this->clean_scalar($goals),
                'photo' => $photo,
                'href' => $href,
            ];
            if (count($rows) >= 50) break;
        }

        if ($rows) return $rows;

        foreach ($xpath->query('//a[contains(@href,"/player/")]') ?: [] as $a) {
            if (!$a instanceof DOMElement) continue;
            $text = $this->node_text_tokens($a);
            if (!$text || mb_strlen($text, 'UTF-8') > 100) continue;
            $context = $this->clean($a->textContent);
            if (!preg_match_all('/\d+|[۰-۹]+/u', $context, $nums) || count($nums[0]) < 2) continue;
            $img = $xpath->query('.//img', $a)->item(0);
            $name = preg_replace('/^\s*\d+\s*/u', '', $text);
            $href = $this->normalize_href($a->getAttribute('href'));
            $photo = ($img instanceof DOMElement) ? $this->normalize_src($this->image_src($img)) : '';
            if (!$photo && preg_match('~/player/(\d+)/~', $href, $pm)) $photo = 'https://cdn.oddrun.ir/players/150/' . $pm[1] . '.png';
            $rows[] = [
                'rank' => $this->fa_to_en($nums[0][0]),
                'name' => $this->clean_team($name),
                'goals' => $this->fa_to_en(end($nums[0])),
                'metric' => 'goals',
                'value' => $this->fa_to_en(end($nums[0])),
                'photo' => $photo,
                'href' => $href,
            ];
            if (count($rows) >= 30) break;
        }
        return $rows;
    }

    private function detect_group_for_node(DOMNode $node, DOMXPath $xpath): string {
        $groupNode = $xpath->query('preceding::*[self::h2 or self::h3 or self::h4 or self::strong][contains(normalize-space(.),"گروه") or contains(normalize-space(.),"Group")][1]', $node)->item(0);
        if ($groupNode) {
            $group = $this->clean($groupNode->textContent);
            if (preg_match('/(گروه\s+[^\s]+|Group\s+[A-Z0-9]+)/iu', $group, $m)) return $m[1];
            return $group;
        }
        return '';
    }

    private function extract_json_nodes(): array {
        $nodes = [];
        if (preg_match_all('~<script[^>]*type=["\']application/(?:ld\+)?json["\'][^>]*>(.*?)</script>~isu', $this->html, $m)) {
            foreach ($m[1] as $json) {
                $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES, 'UTF-8'), true);
                if (is_array($decoded)) $nodes[] = $decoded;
            }
        }
        if (preg_match('~<script[^>]*id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>~isu', $this->html, $m)) {
            $decoded = json_decode(html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8'), true);
            if (is_array($decoded)) $nodes[] = $decoded;
        }
        if (preg_match_all('~(?:window\.[A-Za-z0-9_]+|__INITIAL_STATE__|__NUXT__)\s*=\s*(\{.*?\});\s*</script>~isu', $this->html, $m)) {
            foreach ($m[1] as $json) {
                $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES, 'UTF-8'), true);
                if (is_array($decoded)) $nodes[] = $decoded;
            }
        }
        return $nodes;
    }

    private function walk_json($node, callable $callback): void {
        $callback($node);
        if (!is_array($node)) return;
        foreach ($node as $child) {
            if (is_array($child)) $this->walk_json($child, $callback);
        }
    }

    private function team_logo_map(): array {
        $dom = $this->dom();
        if (!$dom) return [];
        $xpath = new DOMXPath($dom);
        $map = [];
        foreach ($xpath->query('//a[contains(@href,"/team/")]') ?: [] as $a) {
            if (!$a instanceof DOMElement) continue;
            $img = $xpath->query('.//img', $a)->item(0);
            if (!$img instanceof DOMElement) continue;
            $logo = $this->normalize_src($this->image_src($img));
            if (!$logo) continue;
            $names = [];
            $text = $this->clean_team($this->node_text_tokens($a));
            if ($text) $names[] = $text;
            $alt = $this->clean_team($img->getAttribute('alt'));
            if ($alt) $names[] = $alt;
            foreach ($names as $name) $map[$this->logo_key($name)] = $logo;
        }
        foreach ($xpath->query('//img[@alt]') ?: [] as $img) {
            if (!$img instanceof DOMElement) continue;
            $alt = $this->clean_team($img->getAttribute('alt'));
            $logo = $this->normalize_src($this->image_src($img));
            if ($alt && $logo) $map[$this->logo_key($alt)] = $logo;
        }
        return $map;
    }

    private function enrich_standings_logos(array $rows, array $map): array {
        foreach ($rows as &$row) {
            if (empty($row['logo']) && !empty($row['team'])) {
                $key = $this->logo_key($row['team']);
                if (!empty($map[$key])) $row['logo'] = $map[$key];
            }
        }
        return $rows;
    }

    private function enrich_weeks_logos(array $weeks, array $map): array {
        foreach ($weeks as &$week) {
            foreach (($week['matches'] ?? []) as &$match) {
                if (empty($match['home_logo']) && !empty($match['home'])) {
                    $key = $this->logo_key($match['home']);
                    if (!empty($map[$key])) $match['home_logo'] = $map[$key];
                }
                if (empty($match['away_logo']) && !empty($match['away'])) {
                    $key = $this->logo_key($match['away']);
                    if (!empty($map[$key])) $match['away_logo'] = $map[$key];
                }
            }
        }
        return $weeks;
    }

    private function logo_key(string $name): string {
        return mb_strtolower(preg_replace('/\s+/u', '', $this->clean_team($name)), 'UTF-8');
    }

    private function dom(): ?DOMDocument {
        if (!class_exists('DOMDocument')) return null;
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $html = '<?xml encoding="UTF-8">' . $this->html;
        $ok = $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        return $ok ? $dom : null;
    }

    private function node_tokens(DOMNode $node): array {
        $tokens = [];
        if ($node instanceof DOMElement && strtolower($node->tagName) === 'img') {
            $alt = $node->getAttribute('alt');
            if ($alt) $tokens[] = $this->clean($alt);
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $parts = preg_split('/\s{2,}|\R+/u', $child->nodeValue);
                foreach ($parts as $part) {
                    $part = $this->clean($part);
                    if ($part !== '') $tokens[] = $part;
                }
            } else {
                $tokens = array_merge($tokens, $this->node_tokens($child));
            }
        }
        return array_values(array_unique(array_filter($tokens)));
    }

    private function node_text_tokens(DOMNode $node): string {
        return $this->clean(implode(' ', $this->node_tokens($node)) ?: $node->textContent);
    }

    private function previous_team_token(array $tokens, int $start): string {
        for ($i = $start; $i >= 0; $i--) {
            if (!$this->is_meta_token($tokens[$i]) && !$this->is_score_token($tokens[$i]) && !$this->is_time_token($tokens[$i])) return $tokens[$i];
        }
        return '';
    }

    private function next_team_token(array $tokens, int $start): string {
        for ($i = $start; $i < count($tokens); $i++) {
            if (!$this->is_meta_token($tokens[$i]) && !$this->is_status_token($tokens[$i]) && !$this->is_score_token($tokens[$i]) && !$this->is_time_token($tokens[$i])) return $tokens[$i];
        }
        return '';
    }

    private function previous_line_team(array $lines, int $start): string {
        for ($i = $start; $i >= 0 && $i >= $start - 4; $i--) {
            $line = $lines[$i] ?? '';
            if ($line && !$this->is_meta_token($line) && !$this->is_status_token($line) && !$this->is_score_token($line) && !$this->is_time_token($line)) return $line;
        }
        return '';
    }

    private function next_line_team(array $lines, int $start): string {
        for ($i = $start; $i < count($lines) && $i <= $start + 4; $i++) {
            $line = $lines[$i] ?? '';
            if ($line && !$this->is_meta_token($line) && !$this->is_status_token($line) && !$this->is_score_token($line) && !$this->is_time_token($line)) return $line;
        }
        return '';
    }

    private function is_score_token(string $text): bool {
        $text = $this->fa_to_en($this->clean($text));
        if ($this->is_time_token($text)) return false;
        return (bool) preg_match('/^\d+\s*[-–]\s*\d+(?:\s*\([^)]*\))?$/u', $text);
    }

    private function is_time_token(string $text): bool {
        $text = $this->fa_to_en($this->clean($text));
        if (preg_match('/^\d{1,2}:\d{2}$/u', $text)) return true;
        return (bool) preg_match('/^(?:[01]?\d|2[0-3])\s*[-–]\s*00$/u', $text);
    }

    private function is_status_token(string $text): bool {
        $text = $this->clean($text);
        return (bool) preg_match('/پایان|لغو|تعویق|زنده|نیمه|وقت|ادامه|\d+\s*[′\']|\d{1,2}:\d{2}/u', $text);
    }

    private function is_meta_token(string $text): bool {
        $text = $this->clean($text);
        if ($text === '') return true;
        return in_array($text, ['ویدئو‌ها','ویدئوها','ویدیوها','پخش زنده','تماشا کنید','بیشتر','keyboard_arrow_left','keyboard_arrow_right','remove_red_eye','comment','play_arrow','notifications_none'], true)
            || preg_match('/^(visibility|comment|remove_red_eye|play_arrow)(\s+\d+)?$/u', $text)
            || preg_match('/^(comment|دیدگاه|بازدید)\s*[:：]?\s*\d+$/iu', $text)
            || preg_match('/^\d+\s*(comment|دیدگاه|بازدید)$/iu', $text);
    }

    private function status_type(string $status, string $score): string {
        $s = mb_strtolower($this->clean($status), 'UTF-8');
        $score = $this->clean_score($score);
        if (preg_match('/زنده|live|نیمه|در حال|\d+\s*[′\']/u', $s)) return 'live';
        if (preg_match('/پایان|تمام|finished|ended|ft|full/u', $s) || ($score !== '—' && $this->is_score_token($score) && !preg_match('/^\d{1,2}:\d{2}$/', $score))) return 'finished';
        return 'scheduled';
    }

    private function default_matches_title(): string {
        $url = (string) ($this->source['url'] ?? '');
        if (strpos($url, '/live-scores') !== false) return 'نتایج زنده';
        return 'بازی‌ها و نتایج';
    }

    private function first_text(DOMXPath $xpath, string $query): string {
        $node = $xpath->query($query)->item(0);
        return $node ? $this->clean($node->textContent) : '';
    }

    private function meta_content(DOMXPath $xpath, string $property): string {
        $node = $xpath->query('//meta[@property="' . $property . '" or @name="' . $property . '"]')->item(0);
        return ($node instanceof DOMElement) ? $node->getAttribute('content') : '';
    }

    private function image_src(DOMElement $img): string {
        $srcset = trim($img->getAttribute('srcset'));
        $srcsetUrl = $this->first_src_from_srcset($srcset);

        foreach (['data-srcset','data-lazy-srcset'] as $attr) {
            if (!$srcsetUrl) $srcsetUrl = $this->first_src_from_srcset(trim($img->getAttribute($attr)));
        }

        foreach (['src','data-src','data-lazy-src','data-original','data-lazy'] as $attr) {
            $value = trim($img->getAttribute($attr));
            if (!$value) continue;
            if ($this->is_placeholder_image($value) && $srcsetUrl) return $srcsetUrl;
            if (!$this->is_placeholder_image($value)) return $value;
        }
        return $srcsetUrl ?: '';
    }

    private function first_src_from_srcset(string $srcset): string {
        $srcset = trim($srcset);
        if (!$srcset) return '';
        $parts = array_map('trim', explode(',', $srcset));
        $best = '';
        foreach ($parts as $part) {
            if (preg_match('/^(\S+)\s+(\d+)w/u', $part, $m)) {
                $best = $m[1];
            } elseif (!$best && preg_match('/^(\S+)/u', $part, $m)) {
                $best = $m[1];
            }
        }
        return $best;
    }

    private function is_placeholder_image(string $src): bool {
        return (bool) preg_match('~/placeholders/|ic_player_default|ic_default_team_logo|data:image~i', $src);
    }

    private function team_name($team): string {
        if (is_string($team) || is_numeric($team)) return (string) $team;
        if (!is_array($team)) return '';
        return $this->value($team, ['name','title','faName','persianName','shortName','displayName']);
    }

    private function team_logo($team): string {
        if (!is_array($team)) return '';
        return $this->value($team, ['logo','image','icon','badge','flag','src','logoUrl']);
    }

    private function value(array $arr, array $paths) {
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

    private function value_raw(array $arr, array $paths) {
        foreach ($paths as $path) {
            $current = $arr;
            foreach (explode('.', $path) as $part) {
                if (!is_array($current) || !array_key_exists($part, $current)) { $current = null; break; }
                $current = $current[$part];
            }
            if ($current !== null && $current !== '') return $current;
        }
        return null;
    }

    private function clean_title(string $text): string {
        $text = $this->clean($text);
        $text = preg_replace('/\s*\|\s*فوتبالی.*$/u', '', $text);
        $text = preg_replace('/\s*\-\s*Footballi.*$/iu', '', $text);
        // Only normalize the non-standard Persian spelling used for Bundesliga.
        return str_replace(['بوندس-لیگا', 'بوندس‌-لیگا'], 'بوندس لیگا', $text);
    }

    private function clean_team(string $text): string {
        $text = $this->clean($text);
        $text = preg_replace('/^(تیم|باشگاه فوتبال)\s+/u', '', $text);
        $text = preg_replace('/\s+(تیم|باشگاه فوتبال)$/u', '', $text);
        $text = preg_replace('/^\d+\s*/u', '', $text);
        return trim($text);
    }

    private function match_date(string $value): string {
        return preg_match('/\d{4}-\d{2}-\d{2}/', $value, $match) ? $match[0] : '';
    }

    private function clean_score(string $text): string {
        $text = $this->fa_to_en($this->clean($text));
        if ($text === '' || $text === '-') return '—';
        $text = str_replace('–', '-', $text);
        if ($this->is_time_token($text)) return '—';
        return $text;
    }

    private function clean_status(string $text): string {
        return $this->clean($text);
    }

    private function clean_scalar($value): string {
        if (is_array($value)) return '';
        return $this->fa_to_en($this->clean((string) $value));
    }

    private function clean(string $text): string {
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '‌'], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }

    private function text_content(string $html): string {
        $html = preg_replace('~<script\b[^>]*>.*?</script>~isu', "\n", $html);
        $html = preg_replace('~<style\b[^>]*>.*?</style>~isu', "\n", $html);
        $html = preg_replace('~<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>~iu', "\n", $html);
        return html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
    }

    private function fa_to_en(string $text): string {
        return strtr($text, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    }

    private function normalize_src(string $src): string {
        $src = trim($src);
        if (!$src || strpos($src, 'data:image') === 0) return '';
        if (strpos($src, '//') === 0) return 'https:' . $src;
        if (strpos($src, '/') === 0) return 'https://footballi.net' . $src;
        if (strpos($src, 'http://footballi.net') === 0 || strpos($src, 'http://cdn.oddrun.ir') === 0) return preg_replace('/^http:/', 'https:', $src);
        return $src;
    }

    private function normalize_href(string $href): string {
        $href = trim($href);
        if (!$href) return '';
        if (strpos($href, '//') === 0) return 'https:' . $href;
        if (strpos($href, '/') === 0) return 'https://footballi.net' . $href;
        if (strpos($href, 'http://footballi.net') === 0) return preg_replace('/^http:/', 'https:', $href);
        return $href;
    }

    private function dedupe_weeks(array $weeks): array {
        $out = [];
        foreach ($weeks as $week) {
            $matches = $this->dedupe_matches($week['matches'] ?? []);
            if (!$matches) continue;
            $out[] = ['title' => $week['title'] ?? $this->default_matches_title(), 'matches' => $matches];
        }
        $seen = [];
        foreach ($out as $wi => $week) {
            $clean = [];
            foreach ($week['matches'] as $match) {
                $key = md5(($match['home'] ?? '') . '|' . ($match['away'] ?? '') . '|' . ($match['score'] ?? '') . '|' . ($match['status'] ?? ''));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $clean[] = $match;
            }
            $out[$wi]['matches'] = $clean;
        }
        return array_values(array_filter($out, fn($w) => !empty($w['matches'])));
    }

    private function dedupe_matches(array $matches): array {
        $out = [];
        $seen = [];
        foreach ($matches as $match) {
            if (empty($match['home']) || empty($match['away'])) continue;
            $key = md5(($match['home'] ?? '') . '|' . ($match['away'] ?? '') . '|' . ($match['score'] ?? '') . '|' . ($match['status'] ?? ''));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $match;
        }
        return $out;
    }

    private function dedupe_standings(array $rows): array {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (empty($row['team'])) continue;
            $key = md5(($row['rank'] ?? '') . '|' . ($row['team'] ?? ''));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $row;
        }
        return $out;
    }

    private function last_update(): string {
        return 'آخرین دریافت داده: ' . date_i18n(get_option('date_format') . ' - ' . get_option('time_format'), current_time('timestamp'));
    }

    private function is_list(array $arr): bool {
        if (function_exists('array_is_list')) return array_is_list($arr);
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
