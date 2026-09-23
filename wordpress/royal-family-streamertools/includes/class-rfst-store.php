<?php
declare(strict_types=1);
/** WordPress persistence. All table identifiers come from these fixed allowlists. */
final class RFST_Store {
    public static function table(string $name): string {
        global $wpdb;
        if (!in_array($name,['rules','connections','events','oauth','media'],true)) throw new InvalidArgumentException('TABLE');
        return $wpdb->prefix.'rfst_'.$name;
    }
    public static function query(string $sql) {
        global $wpdb; $r=$wpdb->query($sql);
        if ($r===false) throw new RuntimeException('DATABASE_OPERATION_FAILED');
        return $r;
    }
    public static function one(string $sql): ?array {
        global $wpdb; $r=$wpdb->get_row($sql,ARRAY_A);
        if ($wpdb->last_error!=='') throw new RuntimeException('DATABASE_READ_FAILED');
        return is_array($r)?$r:null;
    }
    public static function all(string $sql): array {
        global $wpdb; $r=$wpdb->get_results($sql,ARRAY_A);
        if ($wpdb->last_error!=='') throw new RuntimeException('DATABASE_READ_FAILED');
        return (array)$r;
    }
    public static function update(string $table,array $data,array $where): void {
        global $wpdb; if ($wpdb->update($table,$data,$where)===false) throw new RuntimeException('DATABASE_UPDATE_FAILED');
    }
    public static function insert(string $table,array $data): void {
        global $wpdb; if ($wpdb->insert($table,$data)===false) throw new RuntimeException('DATABASE_INSERT_FAILED');
    }
    /** Deliberate fail-closed activation: no schema writes without a private backup receipt. */
    public static function backupGate(): void {
        if (!defined('RFST_DEPLOYMENT_RECEIPT_FILE')) throw new RuntimeException('PRIVATE_DATABASE_BACKUP_REQUIRED');
        $path=realpath(RFST_DEPLOYMENT_RECEIPT_FILE); $web=realpath(ABSPATH);
        if (!$path||!$web||str_starts_with($path,$web.DIRECTORY_SEPARATOR)) throw new RuntimeException('BACKUP_RECEIPT_LOCATION');
        $r=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if (($r['state']??null)!=='PRIVATE_DB_BACKUP_VERIFIED'||!is_int($r['created_at']??null)||$r['created_at']>time()+60||$r['created_at']<time()-86400) throw new RuntimeException('BACKUP_RECEIPT_STALE');
        $db=realpath((string)($r['database_backup']['path']??''));
        if (!$db||str_starts_with($db,$web.DIRECTORY_SEPARATOR)||!is_file($db)||!filesize($db)||!preg_match('/^[a-f0-9]{64}$/D',(string)($r['database_backup']['sha256']??''))||!hash_equals($r['database_backup']['sha256'],hash_file('sha256',$db))) throw new RuntimeException('BACKUP_NOT_VERIFIED');
        foreach (['streamers','sessions','timer_configs','timer_states','alerts'] as $n) {
            if (!in_array(RFS_DB::table($n),$r['tables']??[],true)) throw new RuntimeException('BACKUP_SCOPE_INCOMPLETE');
        }
        if (($r['restore_check']??null)!=='PASS') throw new RuntimeException('BACKUP_RESTORE_CHECK_REQUIRED');
    }
    public static function engines(bool $newTables=true): void {
        global $wpdb;
        $names=array_map([RFS_DB::class,'table'],['streamers','sessions','timer_states','timer_configs','alerts']);
        if ($newTables) foreach (['rules','connections','events','oauth','media'] as $n) $names[]=self::table($n);
        foreach ($names as $n) {
            $r=self::one($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$n));
            if (!$r||strtoupper((string)$r['ENGINE'])!=='INNODB') throw new RuntimeException('INNODB_REQUIRED');
        }
    }
    public static function activate(): void {
        if (!class_exists('RFS_DB')||!class_exists('RFS_Crypto')||PHP_INT_SIZE<8) wp_die('Levi Subathon 1.3.4 and 64-bit PHP required.');
        try {
            self::backupGate(); self::engines(false);
            global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $cc=$wpdb->get_charset_collate();
            $schemas=[
                'rules'=>"streamer_id varchar(128) NOT NULL, rule_json text NOT NULL, revision bigint unsigned NOT NULL DEFAULT 0, credit_from_ms bigint unsigned NOT NULL DEFAULT 0, updated_at bigint unsigned NOT NULL, PRIMARY KEY  (streamer_id)",
                'connections'=>"streamer_id varchar(128) NOT NULL, provider varchar(32) NOT NULL, source_channel_id varchar(128) NOT NULL, encrypted_tokens longtext NOT NULL, expires_at bigint unsigned NOT NULL, state varchar(32) NOT NULL, generation bigint unsigned NOT NULL DEFAULT 1, connected_at bigint unsigned NOT NULL, updated_at bigint unsigned NOT NULL, PRIMARY KEY  (streamer_id,provider), UNIQUE KEY provider_channel (provider,source_channel_id)",
                'events'=>"event_key char(64) NOT NULL, streamer_id varchar(128) NOT NULL, identity_hash char(64) NOT NULL, version_hash char(64) NOT NULL, updated_ms bigint unsigned NOT NULL, state varchar(32) NOT NULL, consumed tinyint NOT NULL DEFAULT 0, amount_minor bigint unsigned NOT NULL, requested_seconds bigint unsigned NOT NULL DEFAULT 0, actual_seconds bigint unsigned NOT NULL DEFAULT 0, rule_revision bigint unsigned NOT NULL DEFAULT 0, created_at bigint unsigned NOT NULL, PRIMARY KEY  (event_key), KEY owner_state (streamer_id,state)",
                'oauth'=>"state_hash char(64) NOT NULL, streamer_id varchar(128) NOT NULL, session_hash char(64) NOT NULL, cookie_hash char(64) NOT NULL, expires_at bigint unsigned NOT NULL, PRIMARY KEY  (state_hash), KEY expires (expires_at)",
                'media'=>"streamer_id varchar(128) NOT NULL, revision bigint unsigned NOT NULL DEFAULT 0, state_json text NOT NULL, updated_at bigint unsigned NOT NULL, PRIMARY KEY  (streamer_id)"
            ];
            foreach($schemas as $n=>$sql) {
                $definitions=preg_split('/, (?=[A-Za-z_])/', $sql);
                dbDelta('CREATE TABLE '.self::table($n)." (\n".implode(",\n", $definitions)."\n) ENGINE=InnoDB $cc;");
            }
            self::engines(); update_option('rfst_schema_version','0.2.0',false);
        } catch (Throwable $e) {wp_die(esc_html($e->getMessage()));}
    }
    public static function ready(): void {
        if (get_option('rfst_schema_version')!=='0.2.0') throw new RuntimeException('EXTENSION_NOT_ACTIVATED');
        self::engines();
    }
    public static function begin(): void {self::query('START TRANSACTION');}
    public static function commit(): void {self::query('COMMIT');}
    public static function rollback(): void {global $wpdb;$wpdb->query('ROLLBACK');}
    public static function lockOwner(string $id): array {
        global $wpdb; RFST_Domain::id($id);
        $r=self::one($wpdb->prepare('SELECT id,twitch_user_id,active FROM '.RFS_DB::table('streamers').' WHERE id=%s FOR UPDATE',$id));
        if (!$r||!(int)$r['active']) throw new RuntimeException('OWNER_INACTIVE');
        return $r;
    }
    public static function rule(string $id,bool $lock=false): array {
        global $wpdb;RFST_Domain::id($id);
        $r=self::one($wpdb->prepare('SELECT * FROM '.self::table('rules').' WHERE streamer_id=%s'.($lock?' FOR UPDATE':''),$id));
        if (!$r) return ['rule'=>RFS_Donation_Rule::defaults(),'revision'=>0,'credit_from_ms'=>0,'tip_url'=>'','tip_url_verification'=>'format_only_not_authorization'];
        $stored=json_decode($r['rule_json'],true,16,JSON_THROW_ON_ERROR);$tip=RFST_Presentation::tipLink($stored['_tip_url']??'');unset($stored['_tip_url']);
        return ['rule'=>RFS_Donation_Rule::validate($stored), 'revision'=>(int)$r['revision'],'credit_from_ms'=>(int)$r['credit_from_ms'],'tip_url'=>$tip,'tip_url_verification'=>'format_only_not_authorization'];
    }
    public static function saveRule(string $id,array $rule,int $expected,?string $tipUrl=null): array {
        global $wpdb;self::ready(); $rule=RFS_Donation_Rule::validate($rule); self::begin();
        try {
            self::lockOwner($id); $old=self::rule($id,true);$tip=$tipUrl===null?$old['tip_url']:RFST_Presentation::tipLink($tipUrl);
            if ($old['revision']!==$expected) throw new RuntimeException('CONFIG_REVISION_CONFLICT');
            $from=$old['credit_from_ms']; if ($rule['enabled']&&!$old['rule']['enabled']) $from=(int)floor(microtime(true)*1000);
            $row=['streamer_id'=>$id,'rule_json'=>wp_json_encode($rule+['_tip_url'=>$tip]),'revision'=>$expected+1,'credit_from_ms'=>$from,'updated_at'=>time()];
            if ($expected===0) self::insert(self::table('rules'),$row); else self::update(self::table('rules'),$row,['streamer_id'=>$id]);
            self::commit(); return ['rule'=>$rule,'revision'=>$expected+1,'credit_from_ms'=>$from,'tip_url'=>$tip,'tip_url_verification'=>'format_only_not_authorization'];
        } catch(Throwable $e) {self::rollback();throw $e;}
    }
    public static function connection(string $id,bool $lock=false): ?array {
        global $wpdb; return self::one($wpdb->prepare('SELECT * FROM '.self::table('connections')." WHERE streamer_id=%s AND provider='streamelements'".($lock?' FOR UPDATE':''),$id));
    }
    public static function bind(string $id,string $channel,array $tokens): void {
        global $wpdb;RFST_Domain::id($channel); self::begin();
        try {
            self::lockOwner($id);$old=self::connection($id,true);
            if ($old&&$old['source_channel_id']!==$channel) throw new RuntimeException('CHANNEL_REBIND_REQUIRES_REVIEW');
            $clash=self::one($wpdb->prepare('SELECT streamer_id FROM '.self::table('connections')." WHERE provider='streamelements' AND source_channel_id=%s FOR UPDATE",$channel));
            if ($clash&&$clash['streamer_id']!==$id) throw new RuntimeException('CHANNEL_ALREADY_BOUND');
            $row=['streamer_id'=>$id,'provider'=>'streamelements','source_channel_id'=>$channel,'encrypted_tokens'=>RFS_Crypto::encrypt(wp_json_encode($tokens)),
                  'expires_at'=>time()+(int)$tokens['expires_in'],'state'=>'connected','generation'=>$old?(int)$old['generation']+1:1,'connected_at'=>time(),'updated_at'=>time()];
            if ($old) self::update(self::table('connections'),$row,['streamer_id'=>$id,'provider'=>'streamelements']);else self::insert(self::table('connections'),$row);
            self::commit();
        } catch(Throwable $e) {self::rollback();throw $e;}
    }
    public static function disconnect(string $id): void {
        self::ready();self::begin();try {self::lockOwner($id);$r=self::rule($id,true); $rule=$r['rule'];$rule['enabled']=false;
            if ($r['revision']>0) self::update(self::table('rules'),['rule_json'=>wp_json_encode($rule+['_tip_url'=>$r['tip_url']]),'revision'=>$r['revision']+1,'updated_at'=>time()],['streamer_id'=>$id]);
            $c=self::connection($id,true);if($c)self::update(self::table('connections'),['state'=>'disconnected','encrypted_tokens'=>'','generation'=>(int)$c['generation']+1,'updated_at'=>time()],['streamer_id'=>$id,'provider'=>'streamelements']);
            self::commit();
        }catch(Throwable $e){self::rollback();throw $e;}
    }
    /** Receipt + timer + alert commit together. Never call Levi's nested transaction helper. */
    public static function receive(array $e,int $generation): array {
        global $wpdb; self::ready();$e=RFST_Domain::event($e,(int)floor(microtime(true)*1000));
        $pre=self::one($wpdb->prepare('SELECT streamer_id FROM '.self::table('connections')." WHERE provider='streamelements' AND source_channel_id=%s",$e['source_channel_id']));
        if (!$pre) throw new RuntimeException('CHANNEL_NOT_BOUND');
        $id=$pre['streamer_id']; $key=RFST_Domain::eventKey($e);self::begin();
        try {
            self::lockOwner($id);
            $cfg=self::one($wpdb->prepare('SELECT * FROM '.RFS_DB::table('timer_configs').' WHERE streamer_id=%s FOR UPDATE',$id));
            $r=self::rule($id,true);$c=self::connection($id,true);
            if (!$c||$c['state']!=='connected'||(int)$c['generation']!==$generation||$c['source_channel_id']!==$e['source_channel_id']) throw new RuntimeException('CONNECTION_NOT_CURRENT');
            $old=self::one($wpdb->prepare('SELECT * FROM '.self::table('events').' WHERE event_key=%s FOR UPDATE',$key));
            if ($old&&$old['streamer_id']!==$id) throw new RuntimeException('TENANT_CONFLICT');
            $state=RFST_Domain::transition($old,$e,$r['rule'],max($r['credit_from_ms'],(int)$c['connected_at']*1000));
            if ($state==='stale'||$state==='duplicate') {self::commit();return ['event_key'=>$key,'state'=>$state,'actual_seconds'=>(int)($old['actual_seconds']??0),'committed'=>true];}
            $requested=0;$actual=0;$now=time();
            $consumed=(int)($old['consumed']??0);
            if ($state==='credited') {
                $requested=RFS_Donation_Rule::seconds($e['amount_minor'],$e['currency'],$r['rule']);
                $timer=self::one($wpdb->prepare('SELECT * FROM '.RFS_DB::table('timer_states').' WHERE streamer_id=%s FOR UPDATE',$id));
                if (!$timer||!$cfg) throw new RuntimeException('TIMER_NOT_INITIALIZED');
                $p=RFST_Domain::timer($timer,$cfg,$requested,$now);$actual=$p['actual_seconds'];
                $alias=RFST_Presentation::alias($e['donor_alias']??null);
                $label=sprintf('Donation · %s · %d,%02d EUR',$alias,intdiv($e['amount_minor'],100),$e['amount_minor']%100);
                if($p['sleep_withheld'])$label.=' · im Schlafmodus nicht addiert';elseif($p['capped'])$label.=' · bis zum Zeitlimit';
                $data=['running'=>$p['running'],'remaining_seconds'=>$p['remaining_seconds'],'ends_at'=>$p['ends_at'],'last_event'=>$label,'updated_at'=>$now,
                       'alert_id'=>(int)$timer['alert_id']+1,'alert_label'=>$label,'alert_seconds'=>$actual,'alert_created_at'=>$now];
                self::update(RFS_DB::table('timer_states'),$data,['streamer_id'=>$id]);
                self::insert(RFS_DB::table('alerts'),['streamer_id'=>$id,'label'=>$label,'seconds'=>$actual,'created_at'=>$now]);$consumed=1;
            } elseif(in_array($state,['disabled','before_activation'],true)) $consumed=1;
            // Review never changes an already committed amount or silently reverses it.
            if($state==='review'&&$old) {
                self::update(self::table('events'),['state'=>'review'],['event_key'=>$key,'streamer_id'=>$id]);$actual=(int)$old['actual_seconds'];
            } else {
                $row=['event_key'=>$key,'streamer_id'=>$id,'identity_hash'=>RFST_Domain::identity($e),'version_hash'=>RFST_Domain::version($e),
                    'updated_ms'=>$e['updated_at_ms'],'state'=>$state,'consumed'=>$consumed,'amount_minor'=>$e['amount_minor'],'requested_seconds'=>$requested,
                    'actual_seconds'=>$actual,'rule_revision'=>$r['revision'],'created_at'=>$old?(int)$old['created_at']:$now];
                if($old)self::update(self::table('events'),$row,['event_key'=>$key,'streamer_id'=>$id]);else self::insert(self::table('events'),$row);
            }
            self::commit();return ['event_key'=>$key,'state'=>$state,'actual_seconds'=>$actual,'committed'=>true];
        } catch(Throwable $e) {self::rollback();throw $e;}
    }
    public static function mediaGet(string $id): array {
        global $wpdb;$r=self::one($wpdb->prepare('SELECT * FROM '.self::table('media').' WHERE streamer_id=%s',$id));
        return $r?['revision'=>(int)$r['revision'],'media'=>json_decode($r['state_json'],true,16,JSON_THROW_ON_ERROR),'server_now'=>time()]:['revision'=>0,'media'=>null,'server_now'=>time()];
    }
    public static function mediaSave(string $id,?array $media,int $revision): array {
        global $wpdb;self::ready();if($media!==null)$media=RFST_Domain::media($media);self::begin();try{
            self::lockOwner($id);$old=self::one($wpdb->prepare('SELECT * FROM '.self::table('media').' WHERE streamer_id=%s FOR UPDATE',$id));
            if((int)($old['revision']??0)!==$revision)throw new RuntimeException('MEDIA_REVISION_CONFLICT');
            if($media)$media['starts_at']=time();
            $row=['streamer_id'=>$id,'revision'=>$revision+1,'state_json'=>wp_json_encode($media),'updated_at'=>time()];
            if($old)self::update(self::table('media'),$row,['streamer_id'=>$id]);else self::insert(self::table('media'),$row);
            self::commit();return ['revision'=>$revision+1,'media'=>$media,'server_now'=>time()];
        }catch(Throwable $e){self::rollback();throw $e;}
    }
}
