-- 008_bug_fixes.sql — small schema fixes uncovered in the bug audit.
-- All statements are idempotent (IF NOT EXISTS / replaceable enums) so re-running
-- on already-migrated installs is a no-op.

-- B1: drop the broken `'all:' || ?` revoke-all approach. Instead, stamp the
-- user with a "no JWT issued before this" timestamp; auth middleware compares
-- the JWT's iat against this. One UPDATE on password-reset revokes everything
-- atomically and without filling the revoked_tokens table with thousands of
-- pseudo-jtis.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS tokens_invalid_before DATETIME NULL AFTER api_key_last4;

-- B20: ensure the collaborator enum matches what code expects after the
-- "approver → admin" rename. If migration 007 didn't run cleanly (e.g. on a
-- fresh install where 005 already used 'admin'), this is a no-op.
ALTER TABLE project_collaborators
    MODIFY role ENUM('viewer','editor','admin','owner') NOT NULL DEFAULT 'viewer';
