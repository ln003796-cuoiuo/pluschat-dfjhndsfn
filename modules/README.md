# Pluschat modules
Application/domain logic lives here; public pages and HTTP adapters stay in public/, migrations in database/. PHP 8.3 + PDO/MySQL, no Composer or daemon requirement.
Rules: preserve public API URLs/JSON for web and Android; modules do not read .env, start sessions or emit HTTP responses; do not duplicate uploaded media; do not create/drop databases.
Domains: messages, calls, feed, channels, shorts, videos, communities, users, notifications, search, media, security, moderation, reactions, analytics, settings and shared.