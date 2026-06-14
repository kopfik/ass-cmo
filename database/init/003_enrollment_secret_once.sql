-- One-time plaintext agent secret set on approve, cleared after delivery to installer.
-- Must never be logged or exposed outside the enrollment handshake.
ALTER TABLE agent_enrollment_requests ADD COLUMN IF NOT EXISTS agent_secret_once text;
