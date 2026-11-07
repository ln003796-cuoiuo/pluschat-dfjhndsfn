# calls database

This is a separate database for call rooms, participants and call events.

- First migration: `update_001.sql`

It deliberately does not contain users or passwords. User IDs and conversation IDs refer to records in the main `pluschat` database.

The application still needs a separately configured calls-database connection before the call API can write here. Creating these tables alone does not make WebRTC media or the SFU/TURN infrastructure operational.
