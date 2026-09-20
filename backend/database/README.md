# SCAN-ID Database

PostgreSQL database foundation for the SCAN-ID Lost ID Recovery Network Kenya.

SCAN-ID connects people who lose documents with people who find them, while allowing participating offices, businesses, schools, hospitals, security desks, and other locations to participate through ordinary users.

## Requirements

- PostgreSQL 14+
- PHP 8.2+
- Composer
- PDO PostgreSQL extension

---

## 1. Create the database

Create a PostgreSQL database named:

```text
scan_id
The database credentials must match the values configured in:
backend/.env
2. Configure the environment
Copy:
.env.example
to:
.env
Configure:
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
Never commit .env.
3. Install PHP dependencies
From the backend/ directory:
composer install
4. Run the database migration
From the backend/ directory:
php database/migrate.php
A successful migration should report the current SCAN-ID schema version.
5. Database architecture
The database supports the complete recovery network:
Users
Stores registered SCAN-ID users.
Users can:
Report a lost document
Report a found document
Receive recovery notifications
Participate in secure handovers
There is no requirement for a dedicated office staff account.
A receptionist, security guard, employee, customer-service worker, or any other person can use SCAN-ID as a normal user.
Lost documents
Stores secure reports from people who have lost documents.
The system does not publicly expose raw ID numbers.
Found documents
Stores secure reports from people who find documents.
A person may find a document:
On the street
At an office
At a school
At a hospital
At a business
At a security desk
At another public or private location
Secure matching
SCAN-ID compares protected document identifiers and other safe matching information to identify possible matches between lost and found reports.
Raw identification numbers must not be exposed through public APIs.
Recovery requests
Tracks the recovery process after a possible match is identified.
SMS notifications
Allows SCAN-ID to notify an owner even when the owner does not have the SCAN-ID app installed.
The owner can receive an SMS containing a secure recovery link.
Recovery tokens
Secure, one-time or time-limited tokens can be used for:
SMS recovery links
QR recovery
Secure handover verification
Only token hashes are stored in the database.
Recovery payments
Handles supported M-Pesa recovery payments.
Payment confirmation must come from the backend/payment provider verification process rather than from information supplied by the client.
Handovers
Tracks the physical return of a document.
A handover can record:
Recovery request
Collection/safe location
Scheduled time
Completion
Status
Notes
Audit logs
Records important security and recovery events.
6. QR recovery
QR codes are part of the SCAN-ID recovery system.
A QR code must never contain:
Raw ID numbers
Owner phone numbers
Private personal information
Finder private information
Instead, the QR contains a secure SCAN-ID recovery reference/token that the backend verifies.
Example:
QR CODE
   ↓
Secure SCAN-ID reference
   ↓
Backend verification
   ↓
Recovery / handover
7. Users without the app
The owner does not have to install SCAN-ID to recover a document.
Example:
Found document
       ↓
SCAN-ID matching
       ↓
Owner identified/matched
       ↓
SMS notification
       ↓
Secure recovery link
       ↓
Owner verification
       ↓
Collection instructions
       ↓
Handover
A registered SCAN-ID user can instead receive an in-app notification.
8. Security
SCAN-ID follows a privacy-first recovery model.
The database should store protected identifiers rather than publicly searchable raw identification numbers.
The system must not automatically release:
Finder phone number
Finder precise location
Owner private information
Contact or handover information should only be released through the appropriate verified recovery process and consent rules.
9. Schema versions
Database changes are versioned.
The migration system must preserve previously applied versions and safely apply new versions.
Never manually delete production database tables to apply a new application version.
10. Production
Do not run database migrations against production until they have been reviewed and tested.
Production credentials must never be committed to GitHub.
Never commit:
.env
or any file containing production credentials, API keys, payment secrets, SMS credentials, or private tokens.

### Commit message

```text
Update SCAN-ID database documentation for fresh recovery network
