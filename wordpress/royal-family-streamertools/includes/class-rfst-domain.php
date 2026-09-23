<?php
declare(strict_types=1);
/** Pure domain rules. No credentials, network or database access. */
final class RFST_Domain {
    public static function id($value): string {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value)) throw new InvalidArgumentException('INVALID_ID');
        return $value;
    }
    public static function event(array $e, int $nowMs): array {
        $keys=['source','source_channel_id','source_event_id','amount_minor','currency','payment_status','moderation_status','created_at_ms','updated_at_ms','kind','schema_version'];
        if (($e['schema_version']??null)===2) $keys[]='donor_alias';
        if (count($e)!==count($keys) || array_diff($keys,array_keys($e))) throw new InvalidArgumentException('EVENT_SCHEMA');
        if ($e['source']!=='streamelements'||$e['kind']!=='donation'||!in_array($e['schema_version'],[1,2],true)) throw new InvalidArgumentException('EVENT_SCHEMA');
        self::id($e['source_channel_id']); self::id($e['source_event_id']);
        if (!is_int($e['amount_minor'])||$e['amount_minor']<1||$e['amount_minor']>100000000||$e['currency']!=='EUR') throw new InvalidArgumentException('EVENT_AMOUNT');
        if (!in_array($e['payment_status'],['success','pending','failed','refunded','canceled','cancelled'],true) ||
            !in_array($e['moderation_status'],['pending','allowed','rejected'],true)) throw new InvalidArgumentException('EVENT_STATUS');
        if (!is_int($e['created_at_ms'])||!is_int($e['updated_at_ms'])||$e['created_at_ms']<0||$e['updated_at_ms']<$e['created_at_ms']||$e['updated_at_ms']>$nowMs+300000) throw new InvalidArgumentException('EVENT_TIMESTAMP');
        if ($e['schema_version']===2) $e['donor_alias']=RFST_Presentation::alias($e['donor_alias']);
        return $e;
    }
    public static function eventKey(array $e): string { return hash('sha256',$e['source'].':'.$e['source_channel_id'].':'.$e['source_event_id']); }
    public static function identity(array $e): string {
        return hash('sha256',json_encode([$e['source'],$e['source_channel_id'],$e['source_event_id'],$e['amount_minor'],$e['currency'],$e['created_at_ms']],JSON_THROW_ON_ERROR));
    }
    public static function version(array $e): string {
        return hash('sha256',json_encode([self::identity($e),$e['updated_at_ms'],$e['payment_status'],$e['moderation_status']],JSON_THROW_ON_ERROR));
    }
    public static function validateChannel(array $me, array $validation, string $twitchId, string $clientId): string {
        if (($me['provider']??null)!=='twitch'||($me['type']??null)!=='streamer'||!empty($me['suspended']) || (string)($me['providerId']??'')!==$twitchId) throw new InvalidArgumentException('PROVIDER_ACCOUNT_MISMATCH');
        $id=self::id($me['_id']??null);
        if (($validation['channel_id']??null)!==$id||($validation['client_id']??null)!==$clientId) throw new InvalidArgumentException('PROVIDER_TOKEN_MISMATCH');
        $scopes=$validation['scopes']??[];
        if (!is_array($scopes)||array_diff(['channel:read','tips:read','tips:moderation'],$scopes)) throw new InvalidArgumentException('MISSING_PROVIDER_SCOPE');
        return $id;
    }
    public static function transition(?array $old, array $e, array $rule, int $creditFromMs): string {
        if ($old) {
            if (!hash_equals((string)$old['identity_hash'],self::identity($e))) return 'review';
            if ((int)$e['updated_at_ms']<(int)$old['updated_ms']) return 'stale';
            if ((int)$e['updated_at_ms']===(int)$old['updated_ms'] && !hash_equals((string)$old['version_hash'],self::version($e))) return 'review';
            if ($old['state']==='review') return 'review';
            if ((int)$old['consumed']===1) {
                if ($old['state']==='credited' && ($e['payment_status']!=='success'||$e['moderation_status']!=='allowed')) return 'review';
                return 'duplicate';
            }
        }
        if ($e['created_at_ms']<$creditFromMs) return 'before_activation';
        if ($e['payment_status']!=='success'||$e['moderation_status']!=='allowed') return 'withheld';
        if (!$rule['enabled']) return 'disabled';
        return 'credited';
    }
    /** Same positive-adjustment and caps as Levi 1.3.4, but passed explicit server time. */
    public static function timer(array $row, array $cfg, int $seconds, int $now): array {
        if ($seconds<0||$seconds>2592000) throw new InvalidArgumentException('CREDIT_RANGE');
        $running=!empty($row['running']);
        $remaining=$running ? max(0,(int)$row['ends_at']-$now) : max(0,(int)$row['remaining_seconds']);
        if ($remaining===0) $running=false;
        $sleeping=!empty($row['sleeping'])&&empty($cfg['sleep_additions_enabled']);
        if ($sleeping) $seconds=0;
        $limit=($cfg['max_mode']??'limited')==='open'?4294967295:max(0,(int)$cfg['max_seconds']);
        if (($cfg['end_mode']??'open')==='fixed' && (int)$cfg['stream_end_at']>0) $limit=min($limit,max(0,(int)$cfg['stream_end_at']-$now));
        $after=min($limit,$remaining+$seconds); $actual=max(0,$after-$remaining);
        return ['running'=>$running&&$after>0?1:0,'remaining_seconds'=>$after,'ends_at'=>$running&&$after>0?$now+$after:0,
                'actual_seconds'=>$actual,'sleep_withheld'=>$sleeping,'capped'=>$actual<$seconds,'updated_at'=>$now];
    }
    public static function media(array $input): array {
        if (array_diff(array_keys($input),['kind','clip','duration_seconds','muted'])) throw new InvalidArgumentException('MEDIA_FIELDS');
        if (($input['kind']??null)!=='twitch_clip') throw new InvalidArgumentException('MEDIA_KIND');
        if (!is_string($input['clip']??null)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$input['clip'])) throw new InvalidArgumentException('CLIP_ID');
        if (!is_int($input['duration_seconds']??null)||$input['duration_seconds']<1||$input['duration_seconds']>300||!is_bool($input['muted']??null)) throw new InvalidArgumentException('MEDIA_DURATION');
        return $input;
    }
    public static function tenant(array $auth): string {
        if (empty($auth['active'])||!isset($auth['id'])) throw new InvalidArgumentException('UNAUTHORIZED');
        return self::id($auth['id']);
    }
}
