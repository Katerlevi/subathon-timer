<?php
declare(strict_types=1);
final class RFST_Signature {
    public const TIP_PATH='/wp-json/royal-family-subathon/v1/providers/streamelements/tips';
    public const WORKER_PATH='/wp-json/royal-family-subathon/v1/internal/streamelements/channels';
    public static function derivedKey(string $master,string $channel): string {
        if (strlen($master)<32) throw new RuntimeException('WORKER_NOT_CONFIGURED');
        RFST_Domain::id($channel);
        return hash_hmac('sha256','RFST/channel/v1/'.$channel,$master,true);
    }
    public static function sign(string $body,int $ts,string $eventKey,string $key,string $path): string {
        if (!preg_match('/^[a-f0-9]{64}$/D',$eventKey)||strlen($key)<32||!in_array($path,[self::TIP_PATH,self::WORKER_PATH],true)) throw new InvalidArgumentException('SIGNATURE_METADATA');
        $msg="RFv1\n{$ts}\n{$eventKey}\nPOST\n{$path}\n".hash('sha256',$body);
        return 'sha256='.hash_hmac('sha256',$msg,$key);
    }
    public static function verify(string $body,$ts,string $eventKey,string $sig,string $key,string $path,int $now): bool {
        if (!is_string($ts)||!preg_match('/^[0-9]{1,12}$/D',$ts)||abs($now-(int)$ts)>300||strlen($body)>65536||!preg_match('/^sha256=[a-f0-9]{64}$/D',$sig)) return false;
        try { return hash_equals(self::sign($body,(int)$ts,$eventKey,$key,$path),$sig); } catch(Throwable $e) {return false;}
    }
}
