<?php
if (!defined('ABSPATH')) { exit; }

class F360LS_JSON_Parser {
    private array $data;
    private array $matches = [];

    public function __construct($json) {
        if (is_array($json)) {
            $this->data = $json;
            return;
        }
        $decoded = json_decode((string) $json, true);
        $this->data = is_array($decoded) ? $decoded : [];
    }

    public function parse(array $league = []): array {
        $weeks = $this->extract_weeks($this->data);

        if (empty($weeks)) {
            $this->walk_for_matches($this->data);
            if (!empty($this->matches)) {
                $weeks[] = ['title' => 'همه بازی‌ها', 'matches' => $this->dedupe($this->matches)];
            }
        }

        $flat = [];
        foreach ($weeks as $week) {
            foreach (($week['matches'] ?? []) as $match) {
                $flat[] = $match;
            }
        }
        $flat = $this->dedupe($flat);

        return [
            'league' => [
                'title' => $league['title'] ?? $this->value($this->data, ['league.title','competition.title','name','title']),
                'logo' => $league['logo'] ?? $this->value($this->data, ['league.logo','league.image','competition.logo','logo']),
            ],
            'weeks' => $weeks,
            'matches' => $flat,
            'standings' => [],
            'top_scorers' => [],
            'news' => [],
            'last_update' => $this->value($this->data, ['last_update','lastUpdate','updated_at','updatedAt']),
            'description' => '',
            'stats' => [
                'total' => count($flat),
                'finished' => count(array_filter($flat, fn($m) => ($m['status_type'] ?? '') === 'finished')),
                'live' => count(array_filter($flat, fn($m) => ($m['status_type'] ?? '') === 'live')),
                'scheduled' => count(array_filter($flat, fn($m) => ($m['status_type'] ?? '') === 'scheduled')),
                'teams' => 0,
                'has_matches' => !empty($flat),
                'has_table' => false,
            ],
        ];
    }

    private function extract_weeks($node): array {
        $weeks = [];
        if (!is_array($node)) return $weeks;

        foreach ($node as $key => $value) {
            if (!is_array($value)) continue;

            $key_l = strtolower((string) $key);
            if (in_array($key_l, ['weeks','rounds','matchweeks','stages','sections'], true) && $this->is_list($value)) {
                foreach ($value as $idx => $weekNode) {
                    if (!is_array($weekNode)) continue;
                    $matchesNode = $weekNode['matches'] ?? $weekNode['games'] ?? $weekNode['items'] ?? [];
                    $matches = [];
                    if (is_array($matchesNode)) {
                        foreach ($matchesNode as $m) {
                            $match = $this->normalize_match($m);
                            if ($match) $matches[] = $match;
                        }
                    }
                    if ($matches) {
                        $weeks[] = [
                            'title' => $this->pick($weekNode, ['title','name','week','round','label']) ?: ('هفته ' . ($idx + 1)),
                            'matches' => $this->dedupe($matches),
                        ];
                    }
                }
            }

            $childWeeks = $this->extract_weeks($value);
            if ($childWeeks) $weeks = array_merge($weeks, $childWeeks);
        }
        return $weeks;
    }

    private function walk_for_matches($node): void {
        if (!is_array($node)) return;
        $match = $this->normalize_match($node);
        if ($match) $this->matches[] = $match;
        foreach ($node as $child) {
            if (is_array($child)) $this->walk_for_matches($child);
        }
    }

    private function normalize_match($m): ?array {
        if (!is_array($m)) return null;

        $home = $this->team_name($m['home'] ?? $m['homeTeam'] ?? $m['host'] ?? $m['team1'] ?? $m['home_team'] ?? null);
        $away = $this->team_name($m['away'] ?? $m['awayTeam'] ?? $m['guest'] ?? $m['team2'] ?? $m['away_team'] ?? null);

        if (!$home) $home = $this->value($m, ['home.name','homeTeam.name','host.name','team1.name','home_team.name','homeTitle','home_name']);
        if (!$away) $away = $this->value($m, ['away.name','awayTeam.name','guest.name','team2.name','away_team.name','awayTitle','away_name']);

        if (!$home || !$away) return null;

        $homeScore = $this->value($m, ['home_score','homeScore','home.goals','homeTeam.score','home.result','score.home','result.home']);
        $awayScore = $this->value($m, ['away_score','awayScore','away.goals','awayTeam.score','away.result','score.away','result.away']);
        $scoreText = $this->value($m, ['score','result','final_score','matchScore']);
        if (is_array($scoreText)) $scoreText = '';
        if ($scoreText === '' && $homeScore !== '' && $awayScore !== '') {
            $scoreText = $homeScore . ' - ' . $awayScore;
        }
        if ($scoreText === '') $scoreText = '—';

        $status = $this->value($m, ['status','state','matchStatus','statusTitle','time','date','startTime','kickoff','start_at','startAt']);
        if (is_array($status)) $status = '';
        if ($status === '') $status = ($scoreText !== '—') ? 'پایان' : 'زمان نامشخص';

        $href = $this->value($m, ['href','url','link','permalink']);

        return [
            'home' => $this->clean($home),
            'away' => $this->clean($away),
            'score' => $this->clean((string) $scoreText),
            'status' => $this->clean((string) $status),
            'status_type' => $this->status_type((string) $status, (string) $scoreText),
            'home_logo' => $this->normalize_src($this->team_logo($m['home'] ?? $m['homeTeam'] ?? $m['host'] ?? $m['team1'] ?? null)),
            'away_logo' => $this->normalize_src($this->team_logo($m['away'] ?? $m['awayTeam'] ?? $m['guest'] ?? $m['team2'] ?? null)),
            'href' => $this->normalize_href((string) $href),
        ];
    }

    private function team_name($team): string {
        if (is_string($team) || is_numeric($team)) return (string) $team;
        if (!is_array($team)) return '';
        return $this->pick($team, ['name','title','faName','persianName','shortName']);
    }

    private function team_logo($team): string {
        if (!is_array($team)) return '';
        return $this->pick($team, ['logo','image','icon','badge','flag','src']);
    }

    private function pick(array $arr, array $keys): string {
        foreach ($keys as $key) {
            if (isset($arr[$key]) && !is_array($arr[$key])) return (string) $arr[$key];
        }
        return '';
    }

    private function value(array $arr, array $paths) {
        foreach ($paths as $path) {
            $current = $arr;
            foreach (explode('.', $path) as $part) {
                if (!is_array($current) || !array_key_exists($part, $current)) { $current = null; break; }
                $current = $current[$part];
            }
            if ($current !== null && $current !== '') return $current;
        }
        return '';
    }

    private function status_type(string $status, string $score): string {
        $s = mb_strtolower($status, 'UTF-8');
        if (preg_match('/زنده|live|نیمه|در حال|\b[0-9]{1,3}\s*\'\b/u', $s)) return 'live';
        if (preg_match('/پایان|تمام|finished|ended|ft|full/u', $s) || ($score && $score !== '—' && preg_match('/\d+\s*[-:]\s*\d+/u', $score))) return 'finished';
        return 'scheduled';
    }

    private function clean(string $text): string {
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function normalize_src(string $src): string {
        $src = trim($src);
        if (!$src) return '';
        if (strpos($src, '//') === 0) return 'https:' . $src;
        if (strpos($src, '/') === 0) return 'https://football360.ir' . $src;
        return $src;
    }

    private function normalize_href(string $href): string {
        $href = trim($href);
        if (!$href) return '';
        if (strpos($href, '/') === 0) return 'https://football360.ir' . $href;
        return $href;
    }

    private function dedupe(array $matches): array {
        $out = [];
        $seen = [];
        foreach ($matches as $m) {
            $key = md5(($m['home'] ?? '') . '|' . ($m['away'] ?? '') . '|' . ($m['score'] ?? '') . '|' . ($m['status'] ?? ''));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $m;
        }
        return $out;
    }

    private function is_list(array $arr): bool {
        if (function_exists('array_is_list')) return array_is_list($arr);
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
