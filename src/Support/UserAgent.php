<?php
declare(strict_types=1);

namespace NeoFramework\Core\Support;

final class UserAgent
{
    private function __construct()
    {
    }

    public static function isMobile(string $userAgent): bool
    {
        return preg_match('/android.+mobile|avantgo|blackberry|iemobile|iphone|ipod|kindle|mobile|opera mini|palm|phone|windows phone/i', $userAgent) === 1;
    }
}
