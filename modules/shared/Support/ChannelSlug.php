<?php
declare(strict_types=1);
namespace PlusChat\Modules\Shared\Support;
final class ChannelSlug {
 public static function isValid(string $slug): bool { return preg_match('/^[a-z0-9][a-z0-9_-]{2,47}$/', strtolower(trim($slug))) === 1; }
 public static function normalize(string $slug): string { return strtolower(trim($slug)); }
}