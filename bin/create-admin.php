<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap.php';
$options=getopt('',['email:','promote-existing']);$email=strtolower(trim($options['email']??''));
if(!filter_var($email,FILTER_VALIDATE_EMAIL)){fwrite(STDERR,"Usage: php bin/create-admin.php --email=you@example.com [--promote-existing]\n");exit(1);}
$q=db()->prepare('SELECT id FROM users WHERE email=?');$q->execute([$email]);$id=$q->fetchColumn();
if($id&&!isset($options['promote-existing'])){fwrite(STDERR,"Account already exists. Use --promote-existing to grant admin access without changing its password.\n");exit(1);}
$password=null;db()->beginTransaction();
try {
    if(!$id){$password='Admin-'.bin2hex(random_bytes(12)).'!';$q=db()->prepare('INSERT INTO users(email,password_hash) VALUES (?,?)');$q->execute([$email,password_hash($password,PASSWORD_DEFAULT)]);$id=(int)db()->lastInsertId();}
    $q=db()->prepare('INSERT IGNORE INTO super_admins(user_id) VALUES (?)');$q->execute([$id]);
    Audit\Research::record('admin_created',(int)$id,'',null,null,['source'=>'CLI']);
    db()->commit();echo 'Super admin: '.$email.PHP_EOL;if($password)echo 'Password: '.$password.PHP_EOL;else echo "Existing password unchanged.\n";
} catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
