<?php
declare(strict_types=1);
namespace App;

/** عرض وتصفية سجل الموقع — للمالك فقط. مفيش أي مسار تعديل أو حذف. */
final class Logs
{
    public static function rows(string $where, array $args, int $limit, int $offset): array
    {
        $rows = Db::all(
            "SELECT id, actor_id, actor_name, actor_role, tag, severity, description, meta, ip, user_agent, created_at
             FROM activity_log WHERE $where ORDER BY id DESC LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset,
            $args
        );
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['tag_label'] = Audit::TAGS[$r['tag']] ?? $r['tag'];
            $r['severity_label'] = Audit::SEVERITIES[$r['severity']] ?? $r['severity'];
            $r['ip'] = $r['ip'] !== null ? (string)@inet_ntop($r['ip']) : null;
            $r['meta'] = $r['meta'] !== null ? json_decode((string)$r['meta'], true) : null;
        }
        unset($r);
        return $rows;
    }

    private static function cairoDayToUtc(string $d, bool $end): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { throw new ApiException('validation', 'صيغة التاريخ غير صحيحة.', 422); }
        $t = new \DateTimeImmutable($d . ($end ? ' 23:59:59' : ' 00:00:00'), new \DateTimeZone('Africa/Cairo'));
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function list(): void
    {
        $me = Auth::needOwner();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 30;
        $where = '1=1';
        $args = [];
        $f = [];
        if (isset($_GET['tag']) && $_GET['tag'] !== '') {
            $tag = Validator::enum($_GET['tag'], array_keys(Audit::TAGS), 'الـTAG');
            $where .= ' AND tag = ?'; $args[] = $tag; $f['tag'] = $tag;
        }
        if (isset($_GET['severity']) && $_GET['severity'] !== '') {
            $sv = Validator::enum($_GET['severity'], array_keys(Audit::SEVERITIES), 'الخطورة');
            $where .= ' AND severity = ?'; $args[] = $sv; $f['severity'] = $sv;
        }
        if (isset($_GET['q']) && $_GET['q'] !== '') {
            $q = Validator::str($_GET['q'], 1, 60, 'البحث');
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (description LIKE ? OR actor_name LIKE ?)'; array_push($args, $like, $like); $f['q'] = $q;
        }
        if (isset($_GET['user']) && $_GET['user'] !== '') {
            $u = Db::one('SELECT id FROM users WHERE uuid = ?', [Validator::uuid($_GET['user'])]);
            $where .= ' AND actor_id = ?'; $args[] = $u ? (int)$u['id'] : 0; $f['user'] = $_GET['user'];
        }
        if (isset($_GET['ip']) && $_GET['ip'] !== '') {
            $bin = @inet_pton((string)$_GET['ip']);
            if ($bin === false) { throw new ApiException('validation', 'عنوان IP غير صحيح.', 422); }
            $where .= ' AND ip = ?'; $args[] = $bin; $f['ip'] = $_GET['ip'];
        }
        if (isset($_GET['from']) && $_GET['from'] !== '') { $where .= ' AND created_at >= ?'; $args[] = self::cairoDayToUtc((string)$_GET['from'], false); $f['from'] = $_GET['from']; }
        if (isset($_GET['to']) && $_GET['to'] !== '') { $where .= ' AND created_at <= ?'; $args[] = self::cairoDayToUtc((string)$_GET['to'], true); $f['to'] = $_GET['to']; }
        $total = (int)Db::one("SELECT COUNT(*) AS n FROM activity_log WHERE $where", $args)['n'];
        $rows = self::rows($where, $args, $per, ($page - 1) * $per);
        $tags = [];
        foreach (Db::all('SELECT tag, COUNT(*) AS n FROM activity_log GROUP BY tag') as $t) { $tags[] = ['code' => $t['tag'], 'label' => Audit::TAGS[$t['tag']] ?? $t['tag'], 'n' => (int)$t['n']]; }
        Audit::event($me, 'logs_view', 'info', 'قام ' . Audit::who($me) . ' بفتح سجل الموقع', $f, 120);
        Http::ok(['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'tags' => $tags,
                  'tag_catalog' => Audit::TAGS, 'severities' => Audit::SEVERITIES]);
    }

    /** فحص سلامة السلسلة: أي صف اتحذف أو اتعدّل من برّه التطبيق بيكسر الربط ويظهر رقمه هنا. */
    public static function verify(): void
    {
        $me = Auth::needOwner();
        RateLimiter::hit('logverify:' . $me['id'], 6, 600);
        $prev = str_repeat('0', 64);
        $last = 0;
        $checked = 0;
        $bad = null;
        $why = null;
        while ($checked < 300000) {
            $rows = Db::all('SELECT id, actor_id, actor_name, actor_role, tag, severity, description, meta, ip, created_at, prev_hash, row_hash FROM activity_log WHERE id > ? ORDER BY id ASC LIMIT 1000', [$last]);
            if (!$rows) { break; }
            foreach ($rows as $r) {
                $checked++;
                $last = (int)$r['id'];
                if (!hash_equals($prev, (string)$r['prev_hash'])) { $bad = $last; $why = 'الربط بالصف السابق مكسور (صف محذوف أو مُدخَل يدويًا).'; break 2; }
                $h = Audit::hash((string)$r['prev_hash'], $r['actor_id'], (string)$r['actor_name'], $r['actor_role'], (string)$r['tag'], (string)$r['severity'], (string)$r['description'], $r['meta'], $r['ip'], (string)$r['created_at']);
                if (!hash_equals($h, (string)$r['row_hash'])) { $bad = $last; $why = 'محتوى الصف اتعدّل بعد تسجيله.'; break 2; }
                $prev = (string)$r['row_hash'];
            }
        }
        $ok = $bad === null;
        Audit::event($me, 'logs_view', $ok ? 'info' : 'critical', $ok ? 'فحص سلامة السجل: سليم (' . $checked . ' حدث)' : 'فحص سلامة السجل: تم اكتشاف تلاعب عند الحدث رقم ' . $bad, ['checked' => $checked, 'broken_id' => $bad]);
        Http::ok(['ok' => $ok, 'checked' => $checked, 'broken_id' => $bad, 'reason' => $why]);
    }
}
