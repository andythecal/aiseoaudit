<?php
declare(strict_types=1);
namespace Audit;
final class Reports {
    public static function scope(?int $user, string $visitor): array {
        return ['((user_id IS NULL AND visitor_hash = ?) OR user_id = ?)', [$visitor,$user ?? 0]];
    }
    public static function get(string $id, ?int $user, string $visitor): ?array {
        [$where,$args] = self::scope($user,$visitor);
        $q = \db()->prepare('SELECT * FROM audits WHERE id = ? AND ' . $where);
        $q->execute(array_merge([$id],$args)); return $q->fetch() ?: null;
    }
    public static function project(array $r, bool $authenticated): array {
        // Basic findings are public to their owner; registration gates only member extras.
        $r['locked'] = false;
        $accessibility=$r['accessibility'] ?? Accessibility::unavailable('This saved audit predates accessibility checks. Run a new audit to check this page.');
        if (!$authenticated) {
            // Explicit allowlist: member evidence never reaches guest JSON or exports.
            $accessibility=array_intersect_key($accessibility,array_flip(['version','status','summary','scope']));
        }
        $accessibility['detailsLocked']=!$authenticated;
        $r['accessibility']=$accessibility;
        return $r;
    }
    public static function payload(array $row, bool $authenticated): array {
        if ($row['result_json'] !== null) { $r = json_decode($row['result_json'],true); }
        else { $r = Engine::failure($row['requested_url'],'This audit did not finish. Please run a new scan.'); }
        $r['id'] = $row['id']; $r['createdAt'] = $row['created_at'] . 'Z'; $r['state'] = $row['state'];
        $r['savedTo'] = $row['user_id'] === null ? 'browser' : 'account';
        if ($authenticated && $row['user_id'] !== null) {
            $q=\db()->prepare('SELECT state,result_json,error_message,created_at FROM advanced_audits WHERE audit_id=? AND user_id=?');
            $q->execute([$row['id'],$row['user_id']]);$advanced=$q->fetch();
            if($advanced) $r['advanced']=['state'=>$advanced['state'],'result'=>$advanced['state']==='complete'?json_decode($advanced['result_json'],true):null,'error'=>$advanced['error_message']];
        }
        return self::project($r,$authenticated);
    }
}
