<?php
declare(strict_types=1);
/** Optional, explicitly consented per-channel access; NEVER logs tokens or provider bodies. */
final class RFST_Personal {
    public static function expiry(string $token,int $now): int {
        if(strlen($token)<64||strlen($token)>8192||!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D',$token))throw new InvalidArgumentException('PERSONAL_TOKEN_INVALID');
        $parts=explode('.',$token);$decode=static function(string $s): array {
            $s=strtr($s,'-_','+/');$raw=base64_decode($s.str_repeat('=',(4-strlen($s)%4)%4),true);
            if($raw===false)throw new InvalidArgumentException('PERSONAL_TOKEN_INVALID');
            $v=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($v))throw new InvalidArgumentException('PERSONAL_TOKEN_INVALID');return $v;
        };
        $h=$decode($parts[0]);$c=$decode($parts[1]);
        if(!is_string($h['alg']??null)||strtolower($h['alg'])==='none'||$h['alg']==='')throw new InvalidArgumentException('PERSONAL_TOKEN_INVALID');
        // A provider JWT may omit exp. The local connection still has a bounded 30-day lease.
        if(array_key_exists('exp',$c)&&!is_int($c['exp']))throw new InvalidArgumentException('PERSONAL_TOKEN_INVALID');
        $expires=array_key_exists('exp',$c)?$c['exp']:$now+2592000;
        if($expires<=$now+120)throw new RuntimeException('PERSONAL_TOKEN_RECONNECT_REQUIRED');
        // Decoding is NOT verification. The authoritative provider request below is mandatory.
        return min($expires,$now+2592000);
    }
    public static function provider(string $token): array {
        $res=wp_remote_get('https://api.streamelements.com/kappa/v2/channels/me',[
            'headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json'],
            'timeout'=>15,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>65536
        ]);
        if(is_wp_error($res)||wp_remote_retrieve_response_code($res)!==200)throw new RuntimeException('PERSONAL_TOKEN_PROVIDER_REJECTED');
        $v=json_decode(wp_remote_retrieve_body($res),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($v))throw new RuntimeException('PERSONAL_TOKEN_PROVIDER_REJECTED');return $v;
    }
    public static function connect(WP_REST_Request $r): array {
        if(!is_ssl())throw new RuntimeException('PERSONAL_TOKEN_HTTPS_REQUIRED');
        $b=RFST_REST::body($r,['token','consent']);
        if(($b['consent']??null)!==true)throw new InvalidArgumentException('PERSONAL_TOKEN_CONSENT_REQUIRED');
        if(!is_string($b['token']??null))throw new InvalidArgumentException('PERSONAL_TOKEN_INVALID');
        RFST_Store::ready();$auth=(array)$r->get_param('_rfs_auth');$id=RFST_Domain::tenant($auth);
        $token=trim($b['token']);$expiry=self::expiry($token,time());
        $rate='rfst_personal_'.hash('sha256',$id);
        if(get_transient($rate))throw new RuntimeException('PERSONAL_TOKEN_RATE_LIMITED');
        set_transient($rate,1,10);$me=self::provider($token);
        if(($me['provider']??null)!=='twitch'||($me['type']??null)!=='streamer'||!empty($me['suspended'])||
           !is_string($me['providerId']??null)||!hash_equals((string)$auth['twitch_user_id'],$me['providerId']))throw new RuntimeException('PROVIDER_ACCOUNT_MISMATCH');
        $channel=RFST_Domain::id($me['_id']??null);$seconds=$expiry-time();
        if($seconds<=120)throw new RuntimeException('PERSONAL_TOKEN_RECONNECT_REQUIRED');
        RFST_Store::bind($id,$channel,['access_token'=>$token,'auth_method'=>'jwt','expires_in'=>$seconds]);
        // Only explicit connection metadata leaves this function, never the raw $me or token.
        return ['account_connected'=>true,'connection_method'=>'personal_jwt','expires_at'=>$expiry,
            'event_transport_verified'=>false,'live_end_to_end_verified'=>false,'token_returned'=>false];
    }
    public static function type(array $connection): string {
        $t=json_decode(RFS_Crypto::decrypt($connection['encrypted_tokens']),true,16,JSON_THROW_ON_ERROR);
        return ($t['auth_method']??'oauth2')==='jwt'?'jwt':'oauth2';
    }
}
