-- ASS-CMO dashboard read-only database user.
-- Intended to be applied manually after the inventory schema exists.
--
-- Usage example:
--   docker exec -i ass-postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v dashboard_user="$POSTGRES_DASHBOARD_USER" -v dashboard_password="'CHANGE_ME'" < database/scripts/002_dashboard_readonly_user.sql
--
-- The dashboard user is intentionally read-only. It can read public tables/views
-- but cannot insert, update, delete or modify schema objects.

SELECT format('CREATE ROLE %I LOGIN', :'dashboard_user')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'dashboard_user');
\gexec

ALTER ROLE :"dashboard_user" PASSWORD :dashboard_password;

SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'dashboard_user');
\gexec

GRANT USAGE ON SCHEMA public TO :"dashboard_user";
-- Future secret-bearing tables must not be exposed to the dashboard read-only
-- role by default. If dashboard access to non-inventory data is needed later,
-- expose it through an explicit safe view or allowlisted table grant.
GRANT SELECT ON TABLE inventory TO :"dashboard_user";
REVOKE SELECT ON TABLE agent_enrollment_requests, agent_auth FROM :"dashboard_user";
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO :"dashboard_user";

ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON SEQUENCES TO :"dashboard_user";
