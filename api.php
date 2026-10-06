<?php
declare(strict_types=1);
use Audit\Security;
use Audit\Reports;
use Audit\Account;
use Audit\Research;
use Audit\Admin;
use Audit\Engine;
use Audit\Http;
use Audit\Allowance;
use Audit\Advanced;
use Audit\Pdf;
try {
    require __DIR__ . '/bootstrap.php';
    Security::session();
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    $action = $_GET['action'] ?? 'session';
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method,['GET','POST'],true)) { json_response(['error'=>'Method not allowed.'],405); }
    $write = in_array($action,['audit','register','login','logout','delete','deepen','browser-audits'],true);
    if ($write && $method !== 'POST') { json_response(['error'=>'Use POST for this action.'],405); }
    $data = [];
    if ($method === 'POST') {
        Security::csrf();
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 10000) { json_response(['error'=>'Request too large.'],413); }
        $raw = file_get_contents('php://input',false,null,0,10001);
        if (strlen($raw) > 10000) { json_response(['error'=>'Request too large.'],413); }
        $data = json_decode($raw,true);
        if (!is_array($data)) { json_response(['error'=>'Invalid request.'],400); }
    }
    $user = Security::user(); $userId = $user ? (int)$user['id'] : null; $visitor = $_SESSION['visitor_hash'];
    if (in_array($action,['session','account','deepen','report'],true)) Allowance::recover($userId);
    if ($action === 'session') {
        if (($_SESSION['research_visit_at']??0)<time()-1800) { Research::record('visit',$userId,$visitor); $_SESSION['research_visit_at']=time(); } json_response(['csrf'=>$_SESSION['csrf'],'user'=>$user,'gateDetails'=>false,'professionalRoles'=>Research::ROLES,'browserAudits'=>Account::browserAudits($userId,$visitor),'allowance'=>Allowance::summary($userId,$visitor),'registrationEnabled'=>(bool)app_config('registration_enabled')]); }
    if ($action === 'login' || $action === 'register') {
        Security::rate('auth',20);
        $email = strtolower(trim(is_string($data['email'] ?? null) ? $data['email'] : ''));
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || strlen($password) < 12 || strlen($password) > 72) { json_response(['error'=>'Enter a valid email and a password of 12–72 bytes.'],422); }
        Security::rate('auth-email',15,$email);
        if ($action === 'register') {
            $professionalRole=$data['professionalRole']??null;
            if(!is_string($professionalRole)||!isset(Research::ROLES[$professionalRole])) json_response(['error'=>'Please tell us your role by choosing an option from the list.'],422);
            if (!app_config('registration_enabled')) { json_response(['error'=>'Account creation is currently unavailable.'],403); }
            db()->beginTransaction();
            try {
                $q = db()->prepare('INSERT INTO users (email,password_hash) VALUES (?,?)'); $q->execute([$email,password_hash($password,PASSWORD_DEFAULT)]);
                $userId = (int)db()->lastInsertId();
                Research::registerProfile($userId,$professionalRole);
                Research::record('registered',$userId,$visitor);
                $q = db()->prepare('INSERT INTO leads (user_id,marketing_consent,consent_version) VALUES (?,?,?)');
                $q->execute([$userId,($data['marketingConsent'] ?? false) === true ? 1 : 0,'2026-10-v1']);
                db()->commit();
            } catch (PDOException $e) {
                db()->rollBack();
                if ($e->getCode() === '23000') { json_response(['error'=>'Unable to create this account. Try signing in if you already have an account.'],409); }
                throw $e;
            }
        } else {
            $q = db()->prepare('SELECT id,password_hash FROM users WHERE email = ?'); $q->execute([$email]); $record = $q->fetch();
            $hash = $record ? $record['password_hash'] : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            if (!password_verify($password,$hash) || !$record) { json_response(['error'=>'Email or password was not recognised.'],401); }
            $userId = (int)$record['id'];
            Research::record('login',$userId,$visitor);
            if (password_needs_rehash($record['password_hash'],PASSWORD_DEFAULT)) { $q = db()->prepare('UPDATE users SET password_hash=? WHERE id=?'); $q->execute([password_hash($password,PASSWORD_DEFAULT),$userId]); }
        }
        session_regenerate_id(true); $_SESSION['user_id'] = $userId; $_SESSION['csrf'] = bin2hex(random_bytes(32));
        json_response(['user'=>Security::user(),'csrf'=>$_SESSION['csrf'],'allowance'=>Allowance::summary($userId,$visitor),'browserAudits'=>Account::browserAudits($userId,$visitor)]);
    }
    if ($action === 'logout') { if($userId)Research::record('logout',$userId,$visitor); unset($_SESSION['user_id']); session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32)); json_response(['csrf'=>$_SESSION['csrf'],'allowance'=>Allowance::summary(null,$visitor)]); }
    if (strpos($action,'admin-')===0) {
        if (!$userId) json_response(['error'=>'Sign in with a super-admin account.'],401);
        if (!(int)($user['is_super_admin']??0)) json_response(['error'=>'Super-admin access is required.'],403);
        if ($action==='admin-report') {
            $id=$_GET['id']??'';
            if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id))json_response(['error'=>'Audit not found.'],404);
            $q=db()->prepare('SELECT * FROM audits WHERE id=?');$q->execute([$id]);$row=$q->fetch();
            if(!$row)json_response(['error'=>'This saved report is no longer available. Its research activity may remain.'],404);
            Research::record('admin_report_viewed',$userId,$visitor,$id,$row['final_url']?:$row['requested_url']);
            json_response(['report'=>Reports::payload($row,true)]);
        }
        if(!in_array($action,['admin-data','admin-csv'],true))json_response(['error'=>'Unknown admin action.'],404);
        try{$filters=Admin::filters($_GET);}catch(InvalidArgumentException $e){json_response(['error'=>$e->getMessage()],422);}
        if($action==='admin-csv') {
            Security::rate('admin-export',30,'user:'.$userId);$filters['before']='';$rows=Admin::rows($filters,5000);
            Research::record('admin_csv_exported',$userId,$visitor,null,null,['view'=>$filters['view'],'from'=>$filters['from'],'to'=>$filters['to'],'rows'=>count($rows['items'])]);
            header('Content-Type: text/csv; charset=utf-8');header('Cache-Control: private, no-store');
            header('Content-Disposition: attachment; filename="research-'.$filters['view'].'-'.$filters['from'].'-'.$filters['to'].'.csv"');
            header('X-Export-Truncated: '.($rows['nextCursor']?'1':'0'));
            echo Admin::csv($rows['items'],$filters['view']);exit;
        }
        json_response(['overview'=>Admin::overview($filters),'results'=>Admin::rows($filters),'filters'=>$filters,'roles'=>Research::ROLES,'events'=>Research::EVENTS]);
    }
    if ($action === 'account' || $action === 'browser-audits') {
        if (!$userId) json_response(['error'=>'Sign in to access your account.','code'=>'registration_required'],401);
        if ($action === 'browser-audits') {
            $decision=$data['decision']??null;
            if (!in_array($decision,['import','decline'],true)) json_response(['error'=>'Choose whether to save this browser history to your account.'],422);
            $imported=Account::chooseBrowserAudits($userId,$visitor,$decision==='import');
            Research::record($decision==='import'?'browser_imported':'browser_kept',$userId,$visitor,null,null,['imported'=>$imported]);
            json_response(['imported'=>$imported,'browserAudits'=>Account::browserAudits($userId,$visitor),'allowance'=>Allowance::summary($userId,$visitor)]);
        }
        json_response(['account'=>Account::summary($userId,$visitor),'allowance'=>Allowance::summary($userId,$visitor)]);
    }
    if ($action === 'history') {
        $scope=$_GET['scope']??($userId?'account':'browser');
        $search=$_GET['q']??'';$state=$_GET['state']??'all';$cursor=$_GET['before']??'';
        if (!in_array($scope,['account','browser'],true) || !is_string($search) || mb_strlen($search)>150 || !in_array($state,['all','complete','failed','running'],true) || !is_string($cursor) || ($cursor!==''&&!preg_match('/^[a-f0-9]{32}$/',$cursor))) json_response(['error'=>'Invalid history filter.'],422);
        if ($scope==='account' && !$userId) json_response(['error'=>'Sign in to view account history.'],401);
        json_response(Account::history($userId,$visitor,$scope,trim($search),$state,$cursor));
    }
    if (in_array($action,['report','export','delete','pdf','deepen'],true)) {
        $id = $data['id'] ?? $_GET['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/',$id)) { json_response(['error'=>'Audit not found.'],404); }
        $row = Reports::get($id,$userId,$visitor);
        if (!$row) { json_response(['error'=>'Audit not found in your history. Sign in to its owning account.'],404); }
        if (in_array($action,['pdf','deepen'],true) && !$userId) {
            json_response(['error'=>$action==='pdf'?'Register to get PDF audits.':'Register to unlock in-depth audits.','code'=>'registration_required'],401);
        }
        if ($action === 'deepen') {
            if ($row['user_id']===null) json_response(['error'=>'Save this browser audit to your account before starting an in-depth review.','code'=>'import_required'],409);
            if($row['state']!=='complete') json_response(['error'=>'Complete a successful basic audit before requesting an in-depth review.'],422);
            db()->beginTransaction();
            try {
                $q=db()->prepare('SELECT id FROM audits WHERE id=? AND user_id=? FOR UPDATE');$q->execute([$id,$userId]);
                if(!$q->fetchColumn()){db()->rollBack();json_response(['error'=>'Audit not found.'],404);}
                $q=db()->prepare('SELECT state FROM advanced_audits WHERE audit_id=? FOR UPDATE');$q->execute([$id]);$state=$q->fetchColumn();
                if($state==='complete'){db()->commit();json_response(['report'=>Reports::payload($row,true),'allowance'=>Allowance::summary($userId,$visitor)]);}
                if($state==='running'){db()->rollBack();json_response(['error'=>'An in-depth scan is already running for this report. Reopen it shortly.'],409);}
                $chargedDay=Allowance::day();
                if(!Allowance::reserve('deep',$userId,$visitor,$chargedDay)){db()->rollBack();json_response(['error'=>'You have used all 3 in-depth audits for today. Your allowance resets at midnight UK time.','code'=>'deep_limit','allowance'=>Allowance::summary($userId,$visitor)],429);}
                $q=db()->prepare("INSERT INTO advanced_audits (audit_id,user_id,charged_day) VALUES (?,?,?) ON DUPLICATE KEY UPDATE state='running',result_json=NULL,error_message=NULL,charged_day=VALUES(charged_day),refunded=0,created_at=UTC_TIMESTAMP(),completed_at=NULL");
                $q->execute([$id,$userId,$chargedDay]);Research::record('deep_started',$userId,$visitor,$id,$row['final_url']?:$row['requested_url']);db()->commit();
            } catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
            session_write_close();ignore_user_abort(true);@set_time_limit(60);
            register_shutdown_function(function()use($id){try{Allowance::failDeep($id,'The in-depth scan was interrupted. Your credit was returned.');}catch(Throwable $ignored){}});
            try {
                $basic=json_decode($row['result_json'],true);$deep=(new Advanced())->run($basic);
                $q=db()->prepare("UPDATE advanced_audits SET state='complete',result_json=?,completed_at=UTC_TIMESTAMP() WHERE audit_id=? AND state='running'");
                $q->execute([json_encode($deep,JSON_INVALID_UTF8_SUBSTITUTE),$id]);
                Research::record('deep_completed',$userId,$visitor,$id,$row['final_url']?:$row['requested_url']);
            } catch(Throwable $e) {
                error_log('In-depth audit '.$id.': '.$e->getMessage());
                Allowance::failDeep($id,'The page could not be analysed. Your in-depth credit was returned. Please try again.');
                json_response(['error'=>'The page could not be analysed. Your in-depth credit was returned.','allowance'=>Allowance::summary($userId,$visitor)],422);
            }
            json_response(['report'=>Reports::payload($row,true),'allowance'=>Allowance::summary($userId,$visitor)]);
        }
        if ($action === 'delete') {
            $q=db()->prepare("SELECT state FROM advanced_audits WHERE audit_id=?");$q->execute([$id]);
            if($q->fetchColumn()==='running') json_response(['error'=>'Wait for the in-depth scan to finish before deleting this report.'],409);
            Research::record('audit_deleted',$userId,$visitor,$id,$row['final_url']?:$row['requested_url']);
            $q = db()->prepare('DELETE FROM audits WHERE id = ?'); $q->execute([$id]); json_response(['deleted'=>true]);
        }
        $result = Reports::payload($row,$userId !== null);
        if ($action === 'pdf') {
            Security::rate('pdf',30,'user:'.$userId);
            session_write_close();@set_time_limit(60);
            $pdf=Pdf::render($result);Research::record('pdf_downloaded',$userId,$visitor,$id,$row['final_url']?:$row['requested_url']);
            header('Content-Type: application/pdf');header('Cache-Control: private, no-store');
            header('Content-Disposition: attachment; filename="xray-audit-'.$id.'.pdf"');
            header('Content-Length: '.strlen($pdf));echo $pdf;exit;
        }
        if ($action === 'export') {
            if ($result['locked']) { json_response(['error'=>'Sign in to export the full report.'],403); }
            header('Content-Disposition: attachment; filename="audit-' . $id . '.json"');
        }
        Research::record($action==='export'?'json_exported':'report_viewed',$userId,$visitor,$id,$row['final_url']?:$row['requested_url']);
        json_response(['report'=>$result]);
    }
    if ($action === 'audit') {

        $raw = is_string($data['url'] ?? null) ? trim($data['url']) : '';
        if ($raw === '' || strlen($raw) > 2048) { json_response(['error'=>'Enter a URL up to 2,048 characters.'],422); }
        $id = bin2hex(random_bytes(16));
        db()->beginTransaction();
        try {
            if(!Allowance::reserve('basic',$userId,$visitor)){
                db()->rollBack();
                json_response(['error'=>$userId?'You have used your 100 basic audits today. Your allowance resets at midnight UK time.':'You have used your 5 free audits today. Register for 100 basic audits and 3 in-depth audits per day.','code'=>'basic_limit','allowance'=>Allowance::summary($userId,$visitor)],429);
            }
        $q = db()->prepare('INSERT INTO audits (id,visitor_hash,user_id,requested_url,engine_version) VALUES (?,?,?,?,?)'); $q->execute([$id,$visitor,$userId,$raw,Engine::VERSION]);
            Research::auditContext($id);Research::record('audit_started',$userId,$visitor,$id,$raw);
            db()->commit();
        } catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        // Save the attempt before network activity; release the session lock during the crawl.
        session_write_close(); ignore_user_abort(true); @set_time_limit(60);
        register_shutdown_function(function () use ($id,$userId,$visitor,$raw) {
            try { $q = db()->prepare("UPDATE audits SET state='failed',error_message='The audit process was interrupted.',completed_at=UTC_TIMESTAMP() WHERE id=? AND state='running'"); $q->execute([$id]); if($q->rowCount())Research::record('audit_failed',$userId,$visitor,$id,$raw,['reason'=>'interrupted']); } catch (Throwable $ignored) {}
        });
        try { $result = (new Engine())->run(Http::normalize($raw)); }
        catch (Throwable $e) { $result = Engine::failure($raw,$e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : 'The audit could not complete. Please try again.'); error_log('Audit ' . $id . ': ' . $e->getMessage()); }
        $q = db()->prepare('UPDATE audits SET final_url=?,state=?,score=?,result_json=?,error_message=?,completed_at=UTC_TIMESTAMP() WHERE id=?');
        $q->execute([$result['finalUrl'],$result['incomplete'] ? 'failed' : 'complete',$result['score'],json_encode($result,JSON_INVALID_UTF8_SUBSTITUTE),$result['incomplete'] ? mb_substr($result['checks'][0]['detail'],0,500) : null,$id]);
        Research::record($result['incomplete']?'audit_failed':'audit_completed',$userId,$visitor,$id,$result['finalUrl'],['score'=>$result['score'],'httpStatus'=>$result['httpStatus']]);
        $row = Reports::get($id,$userId,$visitor);
        json_response(['report'=>Reports::payload($row,$userId !== null),'allowance'=>Allowance::summary($userId,$visitor)],201);
    }
    json_response(['error'=>'Unknown action.'],404);
} catch (Throwable $e) {
    error_log($e->getMessage());
    if (function_exists('json_response')) { json_response(['error'=>'The service could not complete this request. Please try again.'],503); }
    http_response_code(503); header('Content-Type: application/json'); echo '{"error":"Setup is incomplete. See README.md."}';
}
