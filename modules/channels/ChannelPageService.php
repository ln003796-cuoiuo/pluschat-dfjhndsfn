<?php
declare(strict_types=1);
namespace PlusChat\Modules\Channels;
use InvalidArgumentException;
final class ChannelPageService {
 public function __construct(private readonly ChannelRepository $repository) {}
 public function getPage(string $slug,int $viewerId): ?array {
  $slug=strtolower(trim($slug));
  if(!preg_match('/^[a-z0-9][a-z0-9_-]{2,47}$/',$slug))throw new InvalidArgumentException('invalid_channel_slug');
  return $this->repository->findPageBySlug($slug,$viewerId);
 }
}