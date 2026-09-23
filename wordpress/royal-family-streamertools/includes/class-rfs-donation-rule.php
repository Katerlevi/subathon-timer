<?php
/** Pure, isolated rule model. NOT auto-loaded or installed into the live plugin. */
declare(strict_types=1);
final class RFS_Donation_Rule {
    public const MAX_AMOUNT_MINOR = 100000000;
    public const MAX_SECONDS = 2592000;
    public static function defaults(): array {
        return ['enabled'=>false,'currency'=>'EUR','base_amount_minor'=>500,
                'seconds_per_base'=>600,'mode'=>'proportional','rounding'=>'floor_per_tip'];
    }
    public static function validate(array $input): array {
        $defaults=self::defaults();
        if (array_diff(array_keys($input),array_keys($defaults))) throw new InvalidArgumentException('RULE_UNKNOWN_FIELD');
        $r=array_replace($defaults,$input);
        if (!is_bool($r['enabled']) || $r['currency']!=='EUR' || $r['mode']!=='proportional' || $r['rounding']!=='floor_per_tip') {
            throw new InvalidArgumentException('RULE_INVALID');
        }
        if (!is_int($r['base_amount_minor']) || $r['base_amount_minor']<1 || $r['base_amount_minor']>self::MAX_AMOUNT_MINOR ||
            !is_int($r['seconds_per_base']) || $r['seconds_per_base']<0 || $r['seconds_per_base']>self::MAX_SECONDS) {
            throw new InvalidArgumentException('RULE_INVALID');
        }
        return $r;
    }
    public static function seconds(int $amount_minor,string $currency,array $input): int {
        if (PHP_INT_SIZE<8) throw new RuntimeException('REQUIRES_64_BIT_PHP');
        $r=self::validate($input);
        if ($amount_minor<1 || $amount_minor>self::MAX_AMOUNT_MINOR) throw new InvalidArgumentException('AMOUNT_OUT_OF_BOUNDS');
        if ($currency!==$r['currency']) throw new InvalidArgumentException('CURRENCY_NOT_SUPPORTED');
        if (!$r['enabled']) return 0;
        $seconds=intdiv($amount_minor*$r['seconds_per_base'],$r['base_amount_minor']);
        if ($seconds>self::MAX_SECONDS) throw new InvalidArgumentException('CREDIT_EXCEEDS_720_HOURS');
        return $seconds;
    }
}
