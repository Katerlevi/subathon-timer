<?php
declare(strict_types=1);
final class RFST_REST {
    public const NS='royal-family-subathon/v1';
    public static function init(): void {
        add_action('rest_api_init',[self::class,'routes']);
        add_filter('rest_post_dispatch',static function($response,$server,$request){
            if(str_starts_with($request->get_route(),'/'.self::NS.'/')){
                if(method_exists($response,'header')){$response->header('Cache-Control','no-store, private, max-age=0');$response->header('Referrer-Policy','no-referrer');}
            } return $response;
        },20,3);
    }
    public static function routes(): void {
        $own=[RFS_REST::class,'require_session'];
        foreach([
            ['GET','/donations/config','config',$own],['PUT','/donations/config','save',$own],
            ['POST','/donations/preview','preview',$own],['GET','/donations/status','status',$own],
            ['POST','/providers/streamelements/personal/connect','personalConnect',$own],
            ['POST','/providers/streamelements/oauth/start','oauthStart',$own],
            ['GET','/providers/streamelements/oauth/callback','oauthFinish','__return_true'],
            ['POST','/providers/streamelements/disconnect','disconnect',$own],
            ['POST','/providers/streamelements/tips','receive','__return_true'],
            ['POST','/internal/streamelements/channels','workerChannels','__return_true'],
            ['GET','/media/control','mediaGet',$own],['PUT','/media/control','mediaSave',$own],
            ['GET','/media/state','mediaState','__return_true']
        ] as [$method,$route,$cb,$permission]) register_rest_route(self::NS,$route,['methods'=>$method,'callback'=>static function($req)use($cb){return self::handle($cb,$req);},'permission_callback'=>$permission]);
    }
    public static function handle(string $cb,WP_REST_Request $r){
        try {
            if(strlen($r->get_body())>65536)throw new InvalidArgumentException('BODY_TOO_LARGE');
            $value=self::$cb($r);return $value instanceof WP_REST_Response?$value:new WP_REST_Response($value,200);
        }catch(Throwable $e){
            $allowed=['PERSONAL_TOKEN_INVALID','PERSONAL_TOKEN_RECONNECT_REQUIRED','PERSONAL_TOKEN_PROVIDER_REJECTED','PERSONAL_TOKEN_CONSENT_REQUIRED','PERSONAL_TOKEN_RATE_LIMITED','PERSONAL_TOKEN_HTTPS_REQUIRED','PRIVATE_DATABASE_BACKUP_REQUIRED','EXTENSION_NOT_ACTIVATED','INNODB_REQUIRED','SE_OAUTH_APPLICATION_NOT_CONFIGURED','PROVIDER_ACCOUNT_MISMATCH','PROVIDER_TOKEN_MISMATCH','MISSING_PROVIDER_SCOPE','CHANNEL_ALREADY_BOUND','CHANNEL_REBIND_REQUIRES_REVIEW','CONFIG_REVISION_CONFLICT','MEDIA_REVISION_CONFLICT','PROVIDER_REQUEST_FAILED','OAUTH_STATE_INVALID','OAUTH_SESSION_EXPIRED','OAUTH_DENIED_OR_INVALID','CONNECTION_NOT_CURRENT','CHANNEL_NOT_BOUND','WORKER_NOT_CONFIGURED','WORKER_AUTH_FAILED','CREDITS_NOT_RELEASED','INVALID_OVERLAY_KEY','CURRENCY_NOT_SUPPORTED','AMOUNT_OUT_OF_BOUNDS','CREDIT_EXCEEDS_720_HOURS','RULE_INVALID','RULE_UNKNOWN_FIELD','TIP_URL_INVALID','TIP_URL_UNSUPPORTED','UNEXPECTED_FIELD','BODY_TOO_LARGE'];
            $code=in_array($e->getMessage(),$allowed,true)?$e->getMessage():($e instanceof InvalidArgumentException?'INVALID_REQUEST':'INTEGRATION_FAILURE');
            $status=in_array($code,['WORKER_AUTH_FAILED','INVALID_OVERLAY_KEY'],true)?403:($e instanceof InvalidArgumentException?400:409);
            return new WP_Error(strtolower($code),$code,['status'=>$status]);
        }
    }
    public static function owner(WP_REST_Request $r): string {return RFST_Domain::tenant((array)$r->get_param('_rfs_auth'));}
    public static function body(WP_REST_Request $r,array $fields): array {
        // Read the submitted bytes, not the JSON bag mutated by Levi's require_session().
        // The original auth hook injects _rfs_auth via set_param; client owner overrides must still be rejected.
        $v=json_decode($r->get_body(),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($v)||array_diff(array_keys($v),$fields))throw new InvalidArgumentException('UNEXPECTED_FIELD');return $v;
    }
    public static function config(WP_REST_Request $r): array {RFST_Store::ready();return RFST_Store::rule(self::owner($r));}
    public static function save(WP_REST_Request $r): array {
        $b=self::body($r,['rule','revision','tip_url']);if(!is_array($b['rule']??null)||!is_int($b['revision']??null)||$b['revision']<0)throw new InvalidArgumentException('INVALID_CONFIG');
        if(array_key_exists('tip_url',$b)&&!is_string($b['tip_url'])) throw new InvalidArgumentException('TIP_URL_INVALID');
        return RFST_Store::saveRule(self::owner($r),$b['rule'],$b['revision'],$b['tip_url']??null);
    }
    public static function preview(WP_REST_Request $r): array {
        $b=self::body($r,['amount_minor','currency']);if(!is_int($b['amount_minor']??null)||!is_string($b['currency']??null))throw new InvalidArgumentException('INVALID_AMOUNT');
        $rule=self::config($r);$preview=$rule['rule'];$preview['enabled']=true;
        return ['seconds'=>RFS_Donation_Rule::seconds($b['amount_minor'],$b['currency'],$preview),'revision'=>$rule['revision'],'timer_changed'=>false,'provider_event'=>false];
    }
    public static function status(WP_REST_Request $r): array {
        RFST_Store::ready();$id=self::owner($r);$c=RFST_Store::connection($id);$rule=RFST_Store::rule($id);
        return ['provider'=>'streamelements','personal_connection_supported'=>true,'connection_method'=>($c&&$c['state']==='connected'?(RFST_Personal::type($c)==='jwt'?'personal_jwt':'oauth2'):null),'oauth_application_configured'=>RFST_OAuth::configured(),'account_connected'=>$c&&$c['state']==='connected',
            'rule_enabled'=>$rule['rule']['enabled'],'credits_operator_released'=>defined('RFST_CREDITS_RELEASED')&&RFST_CREDITS_RELEASED===true,
            'event_transport_verified'=>false,'live_end_to_end_verified'=>false,'source'=>'per-account backend state; no successful event inferred from a connected account'];
    }
    public static function personalConnect(WP_REST_Request $r): array {return RFST_Personal::connect($r);}
    public static function oauthStart(WP_REST_Request $r): array {self::body($r,[]);return RFST_OAuth::start($r);}
    public static function oauthFinish(WP_REST_Request $r): WP_REST_Response {
        $code='connected';try {RFST_OAuth::finish($r);}catch(Throwable $e){$code='connection_failed';}
        $response=new WP_REST_Response(null,302);$response->header('Location',home_url('/subathon/').'?' . http_build_query(['se_status'=>$code]));return $response;
    }
    public static function disconnect(WP_REST_Request $r): array {self::body($r,[]);RFST_Store::disconnect(self::owner($r));return ['local_connection_removed'=>true,'rule_disabled'=>true,'provider_global_revocation_claimed'=>false];}
    public static function master(): string {
        if(!defined('RFST_WORKER_KEY')||!is_string(RFST_WORKER_KEY)||strlen(RFST_WORKER_KEY)<32)throw new RuntimeException('WORKER_NOT_CONFIGURED');return RFST_WORKER_KEY;
    }
    public static function receive(WP_REST_Request $r): array {
        if(!defined('RFST_CREDITS_RELEASED')||RFST_CREDITS_RELEASED!==true)throw new RuntimeException('CREDITS_NOT_RELEASED');
        $body=$r->get_body();$envelope=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($envelope)||array_keys($envelope)!==['event','generation']){
            if(!is_array($envelope)||count($envelope)!==2||!isset($envelope['event'],$envelope['generation']))throw new InvalidArgumentException('ENVELOPE');
        }
        if(!is_array($envelope['event'])||!is_int($envelope['generation'])||$envelope['generation']<1)throw new InvalidArgumentException('ENVELOPE');
        $e=RFST_Domain::event($envelope['event'],(int)floor(microtime(true)*1000));$key=RFST_Domain::eventKey($e);
        $secret=RFST_Signature::derivedKey(self::master(),$e['source_channel_id']);
        if(!hash_equals($key,(string)$r->get_header('X-RF-Event-Key'))||!RFST_Signature::verify($body,$r->get_header('X-RF-Timestamp'),$key,(string)$r->get_header('X-RF-Signature'),$secret,RFST_Signature::TIP_PATH,time()))throw new RuntimeException('WORKER_AUTH_FAILED');
        $res=RFST_Store::receive($e,$envelope['generation']);$encoded=wp_json_encode($res,JSON_UNESCAPED_SLASHES);
        return ['receipt'=>$res,'receipt_sha256'=>hash('sha256',$encoded),'receipt_signature'=>hash_hmac('sha256','RFST/receipt/v1/'.$encoded,$secret)];
    }
    public static function workerChannels(WP_REST_Request $r): array {
        $key=(string)$r->get_header('X-RF-Event-Key');$body=$r->get_body();
        if(!RFST_Signature::verify($body,$r->get_header('X-RF-Timestamp'),$key,(string)$r->get_header('X-RF-Signature'),self::master(),RFST_Signature::WORKER_PATH,time())||!hash_equals(hash('sha256',$body),$key))throw new RuntimeException('WORKER_AUTH_FAILED');
        $b=self::body($r,['after']);$after=$b['after']??'';if($after!=='')RFST_Domain::id($after);
        RFST_Store::ready();global $wpdb;
        $rows=RFST_Store::all($wpdb->prepare('SELECT c.* FROM '.RFST_Store::table('connections').' c JOIN '.RFS_DB::table('streamers')." st ON st.id=c.streamer_id WHERE c.provider='streamelements' AND c.state='connected' AND st.active=1 AND c.streamer_id>%s ORDER BY c.streamer_id LIMIT 100",$after));
        $result=[];foreach($rows as $c){
            try {$access=RFST_OAuth::accessForWorker($c);$r=RFST_Store::rule($c['streamer_id']);
                $result[]=['source_channel_id'=>$c['source_channel_id'],'destination'=>$c['streamer_id'],'generation'=>(int)$c['generation'],'activation_ms'=>max($r['credit_from_ms'],(int)$c['connected_at']*1000),'token_type'=>RFST_Personal::type($c),'access_token'=>$access];
            }catch(Throwable $e){ /* One invalid channel must not expose errors/tokens or stop other tenants. */ }
        }
        return ['channels'=>$result,'next'=>count($rows)===100?$rows[count($rows)-1]['streamer_id']:null,'contains_server_credentials'=>true];
    }
    public static function mediaGet(WP_REST_Request $r): array {RFST_Store::ready();return RFST_Store::mediaGet(self::owner($r));}
    public static function mediaSave(WP_REST_Request $r): array {
        $b=self::body($r,['media','revision']);if(!array_key_exists('media',$b)||!is_int($b['revision']??null)||$b['revision']<0||(!is_null($b['media']??null)&&!is_array($b['media'])))throw new InvalidArgumentException('MEDIA_BODY');
        return RFST_Store::mediaSave(self::owner($r),$b['media']??null,$b['revision']);
    }
    public static function mediaState(WP_REST_Request $r): array {
        RFST_Store::ready();global $wpdb;$key=(string)$r->get_header('X-RFS-Overlay-Key');
        if(!preg_match('/^[A-Za-z0-9_-]{40,60}$/D',$key))throw new RuntimeException('INVALID_OVERLAY_KEY');
        $row=RFST_Store::one($wpdb->prepare('SELECT id FROM '.RFS_DB::table('streamers').' WHERE overlay_key_hash=%s AND active=1',RFS_Crypto::hash_token($key)));
        if(!$row)throw new RuntimeException('INVALID_OVERLAY_KEY');return RFST_Store::mediaGet($row['id']);
    }
}
