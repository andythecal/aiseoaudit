<?php
declare(strict_types=1);
namespace Audit;
final class Allowance {
    public static function day(): string { return (new \DateTimeImmutable('now',new \DateTimeZone('Europe/London')))->format('Y-m-d'); }
    public static function resets(): string { return (new \DateTimeImmutable('tomorrow',new \DateTimeZone('Europe/London')))->format(DATE_ATOM); }
    public static function subject(string $identity): string { return hash_hmac('sha256',$identity,\app_config('app_key')); }
    public static function subjects(?int $user,string $visitor): array {
        if ($user !== null) { return [self::subject('user:'.$user)]; }
        return [self::subject('ip:'.($_SERVER['REMOTE_ADDR'] ?? 'cli')),self::subject('visitor:'.$visitor)];
    }
    public static function reserve(string $kind,?int $user,string $visitor,?string $day=null): bool {
        if (!\db()->inTransaction()) { throw new \LogicException('Reserve allowance inside a transaction.'); }
        $day=$day ?? self::day();
        $limit=$kind==='deep'?3:($user===null?5:100);
        $subjects=self::subjects($user,$visitor); sort($subjects);
        foreach($subjects as $subject) {
            $q=\db()->prepare('INSERT IGNORE INTO daily_usage (subject_hash,kind,usage_day,used) VALUES (?,?,?,0)');$q->execute([$subject,$kind,$day]);
            $q=\db()->prepare('SELECT used FROM daily_usage WHERE subject_hash=? AND kind=? AND usage_day=? FOR UPDATE');$q->execute([$subject,$kind,$day]);
            if ((int)$q->fetchColumn()>=$limit) { return false; }
        }
        foreach($subjects as $subject) { $q=\db()->prepare('UPDATE daily_usage SET used=used+1 WHERE subject_hash=? AND kind=? AND usage_day=?');$q->execute([$subject,$kind,$day]); }
        return true;
    }
    public static function summary(?int $user,string $visitor): array {
        $result=['resetsAt'=>self::resets(),'timezone'=>'Europe/London'];
        foreach(['basic','deep'] as $kind) {
            $limit=$kind==='deep'?($user===null?0:3):($user===null?5:100);$used=0;
            foreach(self::subjects($user,$visitor) as $subject) {
                $q=\db()->prepare('SELECT used FROM daily_usage WHERE subject_hash=? AND kind=? AND usage_day=?');$q->execute([$subject,$kind,self::day()]);$used=max($used,(int)$q->fetchColumn());
            }
            $result[$kind]=['limit'=>$limit,'used'=>$used,'remaining'=>max(0,$limit-$used)];
        }
        return $result;
    }
    public static function failDeep(string $id,string $message): void {
        \db()->beginTransaction();
        try {
            $q=\db()->prepare('SELECT * FROM advanced_audits WHERE audit_id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();
            if ($row && $row['state']==='running') {
                if (!$row['refunded']) {
                    $q=\db()->prepare("UPDATE daily_usage SET used=GREATEST(used,1)-1 WHERE subject_hash=? AND kind='deep' AND usage_day=?");
                    $q->execute([self::subject('user:'.$row['user_id']),$row['charged_day']]);
                }
                $q=\db()->prepare("UPDATE advanced_audits SET state='failed',refunded=1,error_message=?,completed_at=UTC_TIMESTAMP() WHERE audit_id=?");
                $q->execute([mb_substr($message,0,500),$id]);
                $q=\db()->prepare('SELECT visitor_hash,requested_url FROM audits WHERE id=?');$q->execute([$id]);$audit=$q->fetch();
                if($audit)Research::record('deep_failed',(int)$row['user_id'],$_SESSION['visitor_hash']??$audit['visitor_hash'],$id,$audit['requested_url'],['creditRefunded'=>true,'reason'=>$message]);
            }
            \db()->commit();
        } catch(\Throwable $e) { if(\db()->inTransaction()) \db()->rollBack();throw $e; }
    }
    public static function recover(?int $user): void {
        if ($user===null) return;
        $q=\db()->prepare("SELECT audit_id FROM advanced_audits WHERE user_id=? AND state='running' AND created_at < UTC_TIMESTAMP() - INTERVAL 2 MINUTE");$q->execute([$user]);
        foreach($q->fetchAll() as $row) { self::failDeep($row['audit_id'],'The in-depth scan was interrupted. Your credit was returned; please try again.'); }
    }
}
