<?php
declare(strict_types=1);
namespace Audit;

final class Account {
    public static function browserAudits(?int $user, string $visitor): array {
        if (!$user) return ['count'=>0,'shouldPrompt'=>false];
        $q=\db()->prepare('SELECT COUNT(*) FROM audits WHERE user_id IS NULL AND visitor_hash=?');
        $q->execute([$visitor]);$count=(int)$q->fetchColumn();
        $q=\db()->prepare('SELECT decision FROM browser_audit_choices WHERE user_id=? AND visitor_hash=?');
        $q->execute([$user,$visitor]);$decision=$q->fetchColumn();
        return ['count'=>$count,'shouldPrompt'=>$count>0 && $decision===false];
    }

    public static function summary(int $user, string $visitor): array {
        $q=\db()->prepare('SELECT email,created_at FROM users WHERE id=?');$q->execute([$user]);$profile=$q->fetch();
        $q=\db()->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(state='complete'),0) AS completed,COALESCE(SUM(state='failed'),0) AS failed,COALESCE(SUM(state='running'),0) AS running FROM audits WHERE user_id=?");
        $q->execute([$user]);$counts=array_map('intval',$q->fetch());
        return ['email'=>$profile['email'],'createdAt'=>$profile['created_at'].'Z','plan'=>'Free account','counts'=>$counts,'browserAudits'=>self::browserAudits($user,$visitor)];
    }

    public static function chooseBrowserAudits(int $user, string $visitor, bool $import): int {
        \db()->beginTransaction();
        try {
            // Serialise decisions for this account. Ownership is always rechecked by UPDATE.
            $q=\db()->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$user]);
            if(!$q->fetchColumn())throw new \RuntimeException('Account no longer exists.');
            $imported=0;
            if($import) {
                $q=\db()->prepare('UPDATE audits SET user_id=? WHERE user_id IS NULL AND visitor_hash=?');
                $q->execute([$user,$visitor]);$imported=$q->rowCount();
            }
            $q=\db()->prepare('INSERT INTO browser_audit_choices(user_id,visitor_hash,decision) VALUES (?,?,?) ON DUPLICATE KEY UPDATE decision=VALUES(decision),decided_at=UTC_TIMESTAMP()');
            $q->execute([$user,$visitor,$import?'imported':'declined']);
            \db()->commit();return $imported;
        } catch(\Throwable $e) {if(\db()->inTransaction())\db()->rollBack();throw $e;}
    }

    public static function history(?int $user,string $visitor,string $scope,string $search,string $state,string $cursor): array {
        // Account history must not mix in unclaimed browser history.
        $where=$scope==='account'?'user_id=?':'user_id IS NULL AND visitor_hash=?';
        $args=[$scope==='account'?$user:$visitor];
        if($search!=='') {
            $where.=" AND (requested_url LIKE ? ESCAPE '!' OR final_url LIKE ? ESCAPE '!')";
            $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';$args[]=$pattern;$args[]=$pattern;
        }
        if($state!=='all'){$where.=' AND state=?';$args[]=$state;}
        $q=\db()->prepare('SELECT COUNT(*) FROM audits WHERE '.$where);$q->execute($args);$total=(int)$q->fetchColumn();
        if($cursor!=='') {
            $q=\db()->prepare('SELECT id,created_at FROM audits WHERE id=? AND '.$where);$q->execute(array_merge([$cursor],$args));$older=$q->fetch();
            if(!$older)return ['audits'=>[],'nextCursor'=>null,'total'=>$total,'scope'=>$scope];
            $where.=' AND (created_at < ? OR (created_at = ? AND id < ?))';
            $args=array_merge($args,[$older['created_at'],$older['created_at'],$older['id']]);
        }
        $q=\db()->prepare("SELECT id,requested_url,final_url,state,score,created_at,EXISTS(SELECT 1 FROM advanced_audits d WHERE d.audit_id=audits.id AND d.state='complete') AS has_deep FROM audits WHERE ".$where.' ORDER BY created_at DESC,id DESC LIMIT 21');
        $q->execute($args);$rows=$q->fetchAll();$more=count($rows)>20;$rows=array_slice($rows,0,20);
        return ['audits'=>$rows,'nextCursor'=>$more?$rows[count($rows)-1]['id']:null,'total'=>$total,'scope'=>$scope];
    }
}
