<?php
declare(strict_types=1);

namespace PlusChat\Modules\Channels;

use InvalidArgumentException;
use PlusChat\Modules\Shared\Support\ChannelSlug;

final class ChannelPageService
{
    public function __construct(private readonly ChannelRepository $repository)
    {
    }

    public function getPage(string $slug, int $viewerId): ?array
    {
        $slug = ChannelSlug::normalize($slug);
        if (!ChannelSlug::isValid($slug)) {
            throw new InvalidArgumentException('invalid_channel_slug');
        }

        return $this->repository->findPageBySlug($slug, $viewerId);
    }
}
