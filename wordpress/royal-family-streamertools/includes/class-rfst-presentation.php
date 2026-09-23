<?php
declare(strict_types=1);
/** Display metadata only. A public tip URL is never an authentication credential. */
final class RFST_Presentation {
    public static function alias($raw): string {
        if (!is_string($raw) || strlen($raw)>512 || preg_match('//u',$raw)!==1) return 'Anonym';
        if (strpbrk($raw,'<>@')!==false) return 'Anonym';
        $s=preg_replace('/[\p{Cc}\p{Cf}\p{Cs}]/u','',$raw);
        $s=trim((string)preg_replace('/\s+/u',' ',(string)$s));
        if ($s==='' || preg_match('~https?://|www\.~iu',$s)) return 'Anonym';
        $chars=preg_split('//u',$s,-1,PREG_SPLIT_NO_EMPTY);
        return implode('',array_slice($chars,0,64));
    }
    public static function tipLink($raw): string {
        if (!is_string($raw) || strlen($raw)>300) throw new InvalidArgumentException('TIP_URL_INVALID');
        $s=trim($raw); if($s==='') return '';
        // Entire URL is matched; no requests, DNS lookups, redirect following or private URLs.
        if(!preg_match('~\Ahttps://streamelements\.com/([A-Za-z0-9_]{3,25})/tip/?\z~i',$s,$m)) throw new InvalidArgumentException('TIP_URL_UNSUPPORTED');
        return 'https://streamelements.com/'.strtolower($m[1]).'/tip';
    }
}
