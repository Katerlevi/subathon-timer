<?php
declare(strict_types=1);
final class RFST_OAuth {
    public const BASE='https://api.streamelements.com';
    public const SCOPES=['channel:read','tips:read','tips:moderation'];
    public static function configured(): bool {return defined('RFST_SE_CLIENT_ID')&&RFST_SE_CLIENT_ID!==''&&defined('RFST_SE_CLIENT_SECRET')&&strlen(RFST_SE_CLIENT_SECRET)>=16;}
    public static function callback(): string {return rest_url('royal-family-subathon/v1/providers/streamelements/oauth/callback');}
    public static function request(string $path,string $method='GET',array $body=[],?string $access=null): array {
        if (!in_array($path,['/oauth2/token','/oauth2/validate','/kappa/v2/channels/me'],true))throw new RuntimeException('PROVIDER_PATH_FORBIDDEN');
        $headers=['Accept'=>'application/json'];if($access!==null)$headers['Authorization']='oAuth '.$access;
        $args=['method'=>$method,'headers'=>$headers,'timeout'=>15,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>65536];
        if($body)$args['body']=$body;
        $res=wp_remote_request(self::BASE.$path,$args);
        if(is_wp_error($res)||wp_remote_retrieve_response_code($res)!==200)throw new RuntimeException('PROVIDER_REQUEST_FAILED');
        $data=json_decode(wp_remote_retrieve_body($res),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('PROVIDER_RESPONSE_INVALID');
        return $data; // Never log/return this raw provider object to the browser.
    }
    public static function tokenShape(array $t): array {
        if(!is_string($t['access_token']??null)||strlen($t['access_token'])<16||!is_string($t['refresh_token']??null)||strlen($t['refresh_token'])<16||!is_int($t['expires_in']??null)||$t['expires_in']<60)throw new RuntimeException('PROVIDER_TOKENS_INVALID');
        return ['access_token'=>$t['access_token'],'refresh_token'=>$t['refresh_token'],'expires_in'=>$t['expires_in']];
    }
    public static function start(WP_REST_Request $request): array {
        if(!is_ssl()||!self::configured())throw new RuntimeException('SE_OAUTH_APPLICATION_NOT_CONFIGURED');
        RFST_Store::ready();$auth=(array)$request->get_param('_rfs_auth');$id=RFST_Domain::tenant($auth);
        if(!preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,60})$/',(string)$request->get_header('Authorization'),$m))throw new RuntimeException('UNAUTHORIZED');
        $state=bin2hex(random_bytes(32));$cookie=bin2hex(random_bytes(32));
        RFST_Store::insert(RFST_Store::table('oauth'),['state_hash'=>hash('sha256',$state),'streamer_id'=>$id,'session_hash'=>RFS_Crypto::hash_token($m[1]),'cookie_hash'=>hash('sha256',$cookie),'expires_at'=>time()+600]);
        $cookieName='rfst_se_'.substr(hash('sha256',$state),0,16);
        if(!setcookie($cookieName,$cookie,['expires'=>time()+600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']))throw new RuntimeException('OAUTH_COOKIE_FAILED');
        return ['authorize_url'=>self::BASE.'/oauth2/authorize?'.http_build_query(['client_id'=>RFST_SE_CLIENT_ID,'redirect_uri'=>self::callback(),'response_type'=>'code','scope'=>implode(' ',self::SCOPES),'state'=>$state],'','&',PHP_QUERY_RFC3986)];
    }
    public static function finish(WP_REST_Request $request): void {
        global $wpdb; RFST_Store::ready();
        $state=(string)$request->get_param('state');$code=(string)$request->get_param('code');
        if(!self::configured()||!preg_match('/^[a-f0-9]{64}$/D',$state)||!$code||strlen($code)>2048||$request->get_param('error'))throw new RuntimeException('OAUTH_DENIED_OR_INVALID');
        $name='rfst_se_'.substr(hash('sha256',$state),0,16);$cookie=(string)($_COOKIE[$name]??'');
        RFST_Store::begin();try{
            $s=RFST_Store::one($wpdb->prepare('SELECT * FROM '.RFST_Store::table('oauth').' WHERE state_hash=%s FOR UPDATE',hash('sha256',$state)));
            if(!$s||(int)$s['expires_at']<time()||!hash_equals($s['cookie_hash'],hash('sha256',$cookie)))throw new RuntimeException('OAUTH_STATE_INVALID');
            $owner=RFST_Store::one($wpdb->prepare('SELECT st.id,st.twitch_user_id,st.active FROM '.RFS_DB::table('streamers').' st JOIN '.RFS_DB::table('sessions').' se ON se.streamer_id=st.id WHERE st.id=%s AND st.active=1 AND se.token_hash=%s AND se.expires_at>%d',$s['streamer_id'],$s['session_hash'],time()));
            if(!$owner)throw new RuntimeException('OAUTH_SESSION_EXPIRED');
            RFST_Store::query($wpdb->prepare('DELETE FROM '.RFST_Store::table('oauth').' WHERE state_hash=%s',hash('sha256',$state)));RFST_Store::commit();
        }catch(Throwable $e){RFST_Store::rollback();throw $e;}
        setcookie($name,'',['expires'=>1,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        $tokens=self::tokenShape(self::request('/oauth2/token','POST',['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>self::callback(),'client_id'=>RFST_SE_CLIENT_ID,'client_secret'=>RFST_SE_CLIENT_SECRET]));
        $valid=self::request('/oauth2/validate','GET',[],$tokens['access_token']);
        $me=self::request('/kappa/v2/channels/me','GET',[],$tokens['access_token']);
        $channel=RFST_Domain::validateChannel($me,$valid,(string)$owner['twitch_user_id'],RFST_SE_CLIENT_ID);
        RFST_Store::bind($owner['id'],$channel,$tokens);
    }
    public static function accessForWorker(array $c): string {
        global $wpdb;
        $tokens=json_decode(RFS_Crypto::decrypt($c['encrypted_tokens']),true,16,JSON_THROW_ON_ERROR);
        if(($tokens['auth_method']??'oauth2')==='jwt'){
            if((int)$c['expires_at']<=time()+120)throw new RuntimeException('PERSONAL_TOKEN_RECONNECT_REQUIRED');
            RFST_Personal::expiry($tokens['access_token'],time());return $tokens['access_token'];
        }
        if((int)$c['expires_at']>time()+120)return $tokens['access_token'];
        if(!self::configured())throw new RuntimeException('SE_OAUTH_APPLICATION_NOT_CONFIGURED');
        // Serialize refresh-token rotation; short provider timeout bounds the owner lock.
        RFST_Store::begin();try{
            RFST_Store::lockOwner($c['streamer_id']);$current=RFST_Store::connection($c['streamer_id'],true);
            if(!$current||$current['state']!=='connected'||$current['generation']!=$c['generation'])throw new RuntimeException('CONNECTION_NOT_CURRENT');
            $t=json_decode(RFS_Crypto::decrypt($current['encrypted_tokens']),true,16,JSON_THROW_ON_ERROR);
            if((int)$current['expires_at']<=time()+120){
                $r=self::request('/oauth2/token','POST',['grant_type'=>'refresh_token','refresh_token'=>$t['refresh_token'],'client_id'=>RFST_SE_CLIENT_ID,'client_secret'=>RFST_SE_CLIENT_SECRET]);
                if(!isset($r['refresh_token']))$r['refresh_token']=$t['refresh_token'];$t=self::tokenShape($r);
                RFST_Store::update(RFST_Store::table('connections'),['encrypted_tokens'=>RFS_Crypto::encrypt(wp_json_encode($t)),'expires_at'=>time()+$t['expires_in'],'updated_at'=>time()],['streamer_id'=>$c['streamer_id'],'provider'=>'streamelements']);
            }
            RFST_Store::commit();return $t['access_token'];
        }catch(Throwable $e){RFST_Store::rollback();throw $e;}
    }
}
