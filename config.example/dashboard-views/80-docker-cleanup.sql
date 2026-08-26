-- label: Docker cleanup
-- description: Containers that are stopped, were replaced by a recreate, or disappeared from the latest agent scan.

WITH classified AS (
    SELECT
        d.*,
        CASE
            WHEN d.present = true THEN 'stopped'
            WHEN d.compose_service IS NOT NULL AND EXISTS (
                SELECT 1
                  FROM docker_containers r
                 WHERE r.uid = d.uid
                   AND r.present = true
                   AND r.compose_project IS NOT DISTINCT FROM d.compose_project
                   AND r.compose_service IS NOT DISTINCT FROM d.compose_service
            ) THEN 'replaced'
            ELSE 'removed'
        END AS reason_key
    FROM docker_containers d
    -- A restarting container is a live incident, not a cleanup item, and the
    -- Docker overview already flags it. NULL state counts as unknown, not running.
    WHERE d.present = false
       OR COALESCE(d.state, 'unknown') NOT IN ('running', 'restarting')
)
SELECT
    i.hostname,
    i.primary_ipv4_addr AS ip,
    CASE c.reason_key
        WHEN 'removed' THEN '⚫ removed'
        WHEN 'replaced' THEN '🔄 replaced'
        ELSE '🟡 stopped'
    END AS reason,
    c.compose_project AS project,
    c.compose_service AS service,
    c.container_name AS container,
    COALESCE(c.state, 'unknown')
        || CASE WHEN c.state = 'exited' THEN ' (' || c.exit_code || ')' ELSE '' END AS last_state,
    c.image_ref AS image,
    c.image_version AS version,
    date_trunc('minute', justify_interval(now() - COALESCE(c.missing_since, c.finished_at))) AS age,
    date_trunc('second', c.last_seen_at AT TIME ZONE 'Europe/Prague') AS last_seen
FROM classified c
JOIN inventory i USING (uid)
ORDER BY
    CASE c.reason_key
        WHEN 'removed' THEN 1
        WHEN 'stopped' THEN 2
        ELSE 3
    END,
    COALESCE(c.missing_since, c.finished_at) DESC NULLS LAST,
    i.hostname,
    c.container_name;
