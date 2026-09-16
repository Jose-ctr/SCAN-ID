SCAN-ID Database

PostgreSQL database foundation for SCAN-ID.

Requirements

- PostgreSQL 14+
- PHP 8.2+
- Composer
- PDO PostgreSQL extension

1. Create the database

Create a PostgreSQL database named:

scan_id

The database credentials must match the values in:

backend/.env

2. Configure the environment

Copy:

.env.example

to:

.env

Then configure:

- "DB_HOST"
- "DB_PORT"
- "DB_DATABASE"
- "DB_USERNAME"
- "DB_PASSWORD"

Never commit ".env".

3. Install PHP dependencies

From the "backend/" directory:

composer install

4. Run the database migration

From the "backend/" directory:

php database/migrate.php

A successful migration should display:

SCAN-ID database migration completed successfully.
Schema version: 1

5. Verify the database

The initial schema creates:

- "users"
- "user_sessions"
- "found_ids"
- "recovery_requests"
- "sms_notifications"
- "recovery_payments"
- "handovers"
- "audit_logs"
- "schema_versions"

Security

The database stores a hash of the found ID number rather than the full ID number.

Recovery payment does not automatically release:

- the finder's phone number
- the finder's precise location

Finder consent is required before contact information is released.

Production

Do not run migrations against production until the schema has been reviewed and tested.

Production credentials must never be committed to GitHub.
