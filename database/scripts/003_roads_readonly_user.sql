-- ass-roads read-only database user and host view.
-- Intended to be applied manually after the inventory schema exists.
--
-- Usage example:
--   docker exec -i ass-postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v roads_user=ass_roads -v roads_password="'CHANGE_ME'" < database/scripts/003_roads_readonly_user.sql
--
-- ass-roads is the company-wide crossroads page. Its role can read only the
-- roads_hosts view, not the inventory table itself. The view is the security
-- boundary: whatever SQL ass-roads runs, it cannot see more than this view.
--
-- A host is listed when its notes contain a web link (a "Label: https://..."
-- line), or when its tags contain "roads": true. "roads": false hides a host
-- that would otherwise be listed.
--
-- Only the web link lines of notes are exposed. Other note lines, including
-- ssh_user, stay private to the admin dashboard.

-- Dropped and recreated, because CREATE OR REPLACE VIEW cannot remove columns.
-- The grant below is applied again on every run.
DROP VIEW IF EXISTS roads_hosts;

CREATE VIEW roads_hosts AS
SELECT
    i.hostname,
    i.primary_ipv4_addr AS ip,
    links.notes
FROM inventory i
CROSS JOIN LATERAL (
    SELECT string_agg(l.line, E'\n' ORDER BY l.n) AS notes
    FROM (
        SELECT btrim(raw, E' \t\r[]') AS line, n
        FROM regexp_split_to_table(coalesce(i.notes, ''), E'\n') WITH ORDINALITY AS s(raw, n)
    ) l
    WHERE l.line ~* '^[A-Za-z0-9 _.-]{1,24}\s*:\s*https?://\S+$'
) links
WHERE i.tags->>'roads' = 'true'
   OR (links.notes IS NOT NULL AND i.tags->>'roads' IS DISTINCT FROM 'false');

SELECT format('CREATE ROLE %I LOGIN', :'roads_user')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'roads_user');
\gexec

ALTER ROLE :"roads_user" PASSWORD :roads_password;

SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'roads_user');
\gexec

GRANT USAGE ON SCHEMA public TO :"roads_user";
-- Deliberately no grant on inventory or any other table.
GRANT SELECT ON TABLE roads_hosts TO :"roads_user";
