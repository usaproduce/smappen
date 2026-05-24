-- Rename 'approver' → 'admin' in project_collaborators.role (item #10).
-- The original 005 schema used 'approver'; the action plan calls for 'admin'
-- as the team-admin role with invite/remove permissions.
-- Two-step ALTER to keep existing data sane on installations that already
-- have role='approver' rows:
ALTER TABLE project_collaborators
    MODIFY role ENUM('viewer','editor','admin','approver','owner') NOT NULL DEFAULT 'viewer';
UPDATE project_collaborators SET role = 'admin' WHERE role = 'approver';
ALTER TABLE project_collaborators
    MODIFY role ENUM('viewer','editor','admin','owner') NOT NULL DEFAULT 'viewer';
