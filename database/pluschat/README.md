# pluschat database

This is the main messenger and client-account database.

- Existing base schema: `../schema.sql`
- Next migration: `update_001.sql`

The migration adds channels, subscriptions and posts so the future home feed can show publications from channels a user follows. It does not create another account table.

Apply migrations in ascending numeric order after backing up the database. Never rerun an already-applied migration or edit its contents after production use.
