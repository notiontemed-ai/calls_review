<?php
/**
 * Преобразование сырых значений листов в структуры интерфейса.
 * Единственное место, знающее про заголовки и правила (группа, эффективная оценка).
 */
final class Data
{
    private array $headerMap = [];   // имя колонки → индекс
    private array $calls = [];       // лёгкие строки списка
    private array $rawRows = [];     // сырые строки (для карточки)
    private array $groupBySide = []; // normalized mango_side → группа

    /** Лёгкие поля списка. Тяжёлые (транскрипты, rule_results) — только в карточке. */
    private const LIST_FIELDS = [
        'call_key', 'call_datetime', 'call_date', 'direction', 'client_phone',
        'mango_side', 'line_name', 'operator_name', 'call_duration', 'call_duration_seconds',
        'overall_score', 'problem_level', 'is_problem_call', 'is_target_call',
        'is_appointment_booked', 'transcription_send_status', 'transcript_text',
        'review_status', 'review_score', 'reviewed_by', 'reviewed_at', 'drive_url',
    ];

    private const CARD_FIELDS = [
        'summary', 'transcript_text', 'transcript_left', 'transcript_right',
        'main_strength', 'main_error', 'recommended_feedback', 'rule_results_json',
        'non_target_reason', 'non_target_detail', 'no_booking_reason', 'no_booking_detail',
        'appointment_datetime', 'city', 'referral_source', 'call_initiator',
        'requested_specialist', 'requested_specialist_raw', 'requested_specialist_match_status',
        'appointment_specialist', 'appointment_specialist_raw', 'appointment_specialist_match_status',
        'transcription_error', 'prompt_version',
    ];

    public function __construct(array $sheets)
    {
        $rows = $sheets['calls'];
        if (count($rows) < 1) return;
        foreach ($rows[0] as $i => $name) $this->headerMap[trim((string)$name)] = $i;

        // Лист «Операторы»: mango_side | Сотрудник | Группа
        foreach (array_slice($sheets['operators'], 1) as $r) {
            $side = self::norm((string)($r[0] ?? ''));
            if ($side === '') continue;
            $group = trim((string)($r[2] ?? ''));
            $this->groupBySide[$side] = $group !== '' ? $group : 'Без группы';
        }

        foreach (array_slice($rows, 1) as $idx => $r) {
            $get = fn(string $h) => trim((string)($r[$this->headerMap[$h] ?? -1] ?? ''));
            if ($get('call_key') === '') continue;

            $item = [];
            foreach (self::LIST_FIELDS as $f) $item[$f] = $get($f);

            $item['group'] = $this->groupBySide[self::norm($item['mango_side'])] ?? 'Без группы';
            $item['has_analysis'] = $get('overall_score') !== '';
            $item['skipped_short'] = str_starts_with($item['transcription_send_status'], 'SKIPPED');
            $item['reviewable'] = $item['has_analysis'] && !$item['skipped_short'];
            $item['effective_score'] = $item['review_score'] !== ''
                ? (float)$item['review_score']
                : ($item['overall_score'] !== '' ? (float)$item['overall_score'] : null);
            unset($item['transcript_text']); // в список не отдаём

            $this->calls[] = $item;
            $this->rawRows[$item['call_key']] = $r;
        }
    }

    // ---------- список ----------

    public function calls(array $q): array
    {
        $items = array_filter($this->calls, fn($c) => $this->matches($c, $q));
        $items = array_values($items);

        $sort = $q['sort'] ?? 'call_datetime';
        $dir  = ($q['dir'] ?? 'desc') === 'asc' ? 1 : -1;
        usort($items, function ($a, $b) use ($sort, $dir) {
            $av = $a[$sort] ?? ($a['effective_score'] ?? '');
            $bv = $b[$sort] ?? ($b['effective_score'] ?? '');
            if ($sort === 'effective_score' || $sort === 'call_duration_seconds') {
                return $dir * (((float)($a[$sort] ?? 0)) <=> ((float)($b[$sort] ?? 0)));
            }
            return $dir * strcmp((string)$av, (string)$bv);
        });

        $per  = max(1, min(200, (int)($q['per_page'] ?? 50)));
        $page = max(1, (int)($q['page'] ?? 1));
        return [
            'total' => count($items),
            'page'  => $page,
            'per_page' => $per,
            'items' => array_slice($items, ($page - 1) * $per, $per),
        ];
    }

    /** call_key всех строк текущего фильтра, доступных к подтверждению. */
    public function confirmableKeys(array $q): array
    {
        $keys = [];
        foreach ($this->calls as $c) {
            if ($this->matches($c, $q) && $c['reviewable'] && $c['review_status'] === '') {
                $keys[] = $c['call_key'];
            }
        }
        return $keys;
    }

    private function matches(array $c, array $q): bool
    {
        if (!empty($q['from']) && $c['call_date'] < $q['from']) return false;
        if (!empty($q['to'])   && $c['call_date'] > $q['to'])   return false;
        if (!empty($q['group']) && $c['group'] !== $q['group']) return false;
        if (!empty($q['operator']) && $c['operator_name'] !== $q['operator']) return false;
        if (!empty($q['direction']) && $c['direction'] !== $q['direction']) return false;

        if (($q['status'] ?? '') === 'unreviewed' && $c['review_status'] !== '') return false;
        if (($q['status'] ?? '') === 'reviewed'   && $c['review_status'] === '') return false;

        switch ($q['preset'] ?? '') {
            case 'unreviewed':
                if (!$c['reviewable'] || $c['review_status'] !== '') return false;
                break;
            case 'problem':
                if ($c['is_problem_call'] !== 'TRUE') return false;
                break;
            case 'low_score':
                if ($c['effective_score'] === null || $c['effective_score'] > 3) return false;
                break;
            case 'non_target':
                if ($c['is_target_call'] !== 'FALSE') return false;
                break;
            case 'changed':
                if ($c['review_status'] !== 'SCORE_CHANGED') return false;
                break;
        }
        return true;
    }

    // ---------- карточка ----------

    public function callDetail(string $callKey): ?array
    {
        $row = $this->rawRows[$callKey] ?? null;
        if ($row === null) return null;
        $get = fn(string $h) => trim((string)($row[$this->headerMap[$h] ?? -1] ?? ''));

        $base = null;
        foreach ($this->calls as $c) if ($c['call_key'] === $callKey) { $base = $c; break; }

        $detail = $base ?? [];
        foreach (self::CARD_FIELDS as $f) $detail[$f] = $get($f);
        $detail['rule_results'] = json_decode($detail['rule_results_json'] ?: '[]', true) ?: [];
        unset($detail['rule_results_json']);
        return $detail;
    }

    /** review_status по call_key — для валидации вердиктов. */
    public function reviewStatus(string $callKey): ?string
    {
        foreach ($this->calls as $c) if ($c['call_key'] === $callKey) return $c['review_status'];
        return null;
    }

    public function snapshotForJournal(string $callKey): array
    {
        foreach ($this->calls as $c) {
            if ($c['call_key'] === $callKey) {
                return [
                    'ai_score'      => $c['overall_score'],
                    'group'         => $c['group'],
                    'operator_name' => $c['operator_name'],
                    'call_datetime' => $c['call_datetime'],
                    'client_phone'  => $c['client_phone'],
                ];
            }
        }
        return ['ai_score' => '', 'group' => '', 'operator_name' => '', 'call_datetime' => '', 'client_phone' => ''];
    }

    // ---------- сводка ----------

    public function summary(string $date): array
    {
        $day = array_values(array_filter($this->calls, fn($c) => $c['call_date'] === $date));

        $groups = [];
        foreach (['Сервис', 'Продажи', 'Клиники', 'Без группы'] as $g) {
            $gc = array_values(array_filter($day, fn($c) => $c['group'] === $g));
            if (!$gc && $g === 'Без группы') continue;
            $scored = array_filter($gc, fn($c) => $c['effective_score'] !== null);
            $row = [
                'group' => $g,
                'calls' => count($gc),
                'inbound'  => count(array_filter($gc, fn($c) => $c['direction'] === 'INBOUND')),
                'outbound' => count(array_filter($gc, fn($c) => $c['direction'] === 'OUTBOUND')),
                'avg_score' => $scored ? round(array_sum(array_map(fn($c) => $c['effective_score'], $scored)) / count($scored), 2) : null,
                'unreviewed' => count(array_filter($gc, fn($c) => $c['reviewable'] && $c['review_status'] === '')),
            ];
            if ($g === 'Продажи') {
                // Метрики продаж раздельно по направлениям: клиент со входящим
                // и исходящим звонком попадает в оба блока — базы независимы.
                $row['sales_inbound']  = self::salesDirectionStats(array_values(array_filter($gc, fn($c) => $c['direction'] === 'INBOUND')));
                $row['sales_outbound'] = self::salesDirectionStats(array_values(array_filter($gc, fn($c) => $c['direction'] === 'OUTBOUND')));
            }
            $groups[] = $row;
        }

        $byOperator = [];
        foreach ($day as $c) {
            if ($c['operator_name'] === '') continue;
            $byOperator[$c['operator_name']][] = $c;
        }
        $operators = [];
        foreach ($byOperator as $name => $oc) {
            $seconds = array_sum(array_map(fn($c) => (int)$c['call_duration_seconds'], $oc));
            $scored  = array_filter($oc, fn($c) => $c['effective_score'] !== null);
            $o = [
                'operator'  => $name,
                'group'     => $oc[0]['group'],
                'calls'     => count($oc),
                'seconds'   => $seconds,
                'duration'  => sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60),
                'avg_score' => $scored ? round(array_sum(array_map(fn($c) => $c['effective_score'], $scored)) / count($scored), 2) : null,
            ];
            if ($o['group'] === 'Продажи') $o += self::conversionByClient($oc);
            $operators[] = $o;
        }
        usort($operators, fn($a, $b) => $b['calls'] <=> $a['calls']);
        $inGroup = fn(callable $p) => array_values(array_filter($operators, $p));

        return [
            'date'   => $date,
            'groups' => $groups,
            'total'  => count($day),
            'operators_sales'   => $inGroup(fn($o) => $o['group'] === 'Продажи'),
            'operators_service' => $inGroup(fn($o) => $o['group'] === 'Сервис'),
            'operators_other'   => $inGroup(fn($o) => !in_array($o['group'], ['Продажи', 'Сервис'], true)),
        ];
    }

    /**
     * Метрики продаж по одному направлению (входящие или исходящие).
     * Звонки: всего и целевые/нецелевые. Клиенты (уникальные client_phone,
     * взаимоисключающе): целевой — есть хотя бы один целевой звонок этого
     * направления за день; нецелевой — целевых нет, есть нецелевые.
     * Конверсия — по уникальным целевым (совпадают с базой conversionByClient).
     */
    private static function salesDirectionStats(array $calls): array
    {
        $stats = [
            'calls'          => count($calls),
            'unique_clients' => count(array_unique(array_map(
                fn($c) => $c['client_phone'],
                array_filter($calls, fn($c) => $c['client_phone'] !== '')
            ))),
            'target_calls' => count(array_filter($calls, fn($c) => $c['is_target_call'] === 'TRUE')),
            'non_target'   => count(array_filter($calls, fn($c) => $c['is_target_call'] === 'FALSE')),
        ];
        $hasTarget = [];
        foreach ($calls as $c) {
            if ($c['client_phone'] === '' || $c['skipped_short']) continue;
            if ($c['is_target_call'] === 'TRUE') $hasTarget[$c['client_phone']] = true;
            elseif ($c['is_target_call'] === 'FALSE') $hasTarget[$c['client_phone']] ??= false;
        }
        $stats['unique_target']     = count(array_filter($hasTarget));
        $stats['unique_non_target'] = count($hasTarget) - $stats['unique_target'];
        return $stats + self::conversionByClient($calls);
    }

    /**
     * Конверсия в запись по уникальным клиентам.
     * База — client_phone, у которого есть хотя бы один состоявшийся целевой звонок;
     * в числителе — те из них, у кого есть хотя бы одна запись.
     */
    private static function conversionByClient(array $calls): array
    {
        $booked = [];
        foreach ($calls as $c) {
            if ($c['client_phone'] === '') continue;
            if ($c['skipped_short'] || $c['is_target_call'] !== 'TRUE') continue;
            $booked[$c['client_phone']] ??= false;
            if ($c['is_appointment_booked'] === 'TRUE') $booked[$c['client_phone']] = true;
        }
        $target = count($booked);
        $won    = count(array_filter($booked));
        return [
            'target'     => $target,   // уникальные клиенты с состоявшимся целевым звонком
            'booked'     => $won,      // из них — с записью
            'conversion' => $target ? round(100 * $won / $target, 1) : null,
        ];
    }

    public function operatorsAndGroups(): array
    {
        $operators = [];
        foreach ($this->calls as $c) if ($c['operator_name'] !== '') $operators[$c['operator_name']] = true;
        ksort($operators);
        return ['operators' => array_keys($operators), 'groups' => ['Сервис', 'Продажи', 'Клиники', 'Без группы']];
    }

    /** Та же нормализация, что normalizeFio_ в Apps Script. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace('ё', 'е', $s);
        $s = preg_replace('/[.,"\'’«»]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }
}
