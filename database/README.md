# Database layout and migrations

The platform currently has two logical databases:

- `pluschat` — the messenger database and the source of truth for client accounts. Existing users, sessions, conversations and messages remain here.
- `calls` — the separate call-control database. It stores call rooms, participants and call events. It must not duplicate user accounts.

## Folder and file naming

Each database has its own directory named after the actual MySQL database:

- `database/pluschat/update_001.sql`
- `database/calls/update_001.sql`

Use the exact pattern `update_002.sql`, `update_003.sql`, and so on for later changes. Never reuse a number or edit an already-applied migration. Every migration must be reviewed and backed up before being applied.

## Existing installation

The original `database/schema.sql` remains the initial schema for the existing `pluschat` database. Do not import it again over a live database. Apply the existing `database/migrations/001_sticker_history_restrict.sql` only if the installation has not already applied it.

For a fresh installation, create the `pluschat` database, import `schema.sql`, then apply numbered files in `database/pluschat/` in ascending order. Create the separate `calls` database and apply its numbered files in `database/calls/`.

## Important separation

The `users` table exists only in `pluschat`. The calls database stores the numeric user ID as an external reference; it intentionally has no cross-database foreign key. Authentication remains centralized in the messenger API.

These SQL files define database structure only. They do not change hosting credentials, `.env`, or automatically run against production. The application must be configured with separate calls-database connection variables before call endpoints write to the `calls` database.
