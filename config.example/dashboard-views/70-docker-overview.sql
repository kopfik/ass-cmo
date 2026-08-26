-- label: Docker overview
-- description: Docker containers currently present on hosts reporting to ASS-CMO, grouped by host and compose project.
-- group-by: host
-- hide-columns: ip
-- group-actions: true

-- Published ports are stored in docker_containers.ports but deliberately not shown here:
-- a single container can publish dozens of them and the column made the table unusably wide.

SELECT
    -- Used as the group key and shown in the group header, never as a column.
    i.hostname || COALESCE('   ' || i.primary_ipv4_addr, '') AS host,
    -- Hidden, but row_actions() needs it to build the SSH / application links.
    i.primary_ipv4_addr AS ip,
    CASE
        WHEN d.state = 'running' AND d.health_status = 'unhealthy' THEN '🔴 unhealthy'
        WHEN d.state = 'running' AND d.health_status = 'starting' THEN '🔵 starting'
        WHEN d.state = 'running' AND d.health_status = 'healthy' THEN '🟢 healthy'
        WHEN d.state = 'running' THEN '🟢 running'
        WHEN d.state = 'restarting' THEN '🔴 restarting'
        WHEN d.state = 'dead' THEN '🔴 dead'
        WHEN d.state = 'paused' THEN '⚪ paused'
        WHEN d.state = 'exited' AND d.exit_code = 0 THEN '🟡 exited'
        WHEN d.state = 'exited' THEN '🟠 exited ' || COALESCE(d.exit_code::text, '?')
        ELSE '⚪ ' || COALESCE(d.state, 'unknown')
    END AS status,
    d.compose_project AS project,
    d.compose_service AS service,
    d.container_name AS container,
    d.image_ref AS image,
    d.image_version AS version,
    floor(EXTRACT(epoch FROM (now() - d.created_at)) / 86400)::integer || 'd' AS age,
    CASE
        WHEN d.state = 'running' THEN date_trunc('minute', justify_interval(now() - d.started_at))
    END AS uptime,
    NULLIF(d.restart_count, 0) AS restarts,
    d.restart_policy AS policy,
    date_trunc('second', d.last_seen_at AT TIME ZONE 'Europe/Prague') AS last_seen,
    -- Hidden by the dashboard, but read by row_actions() for per-host launcher links.
    i.notes
FROM docker_containers d
JOIN inventory i USING (uid)
WHERE d.present = true
ORDER BY
    CASE
        WHEN d.state = 'running' AND d.health_status = 'unhealthy' THEN 1
        WHEN d.state IN ('restarting', 'dead') THEN 1
        WHEN d.state IS DISTINCT FROM 'running' THEN 2
        ELSE 3
    END,
    i.hostname,
    d.compose_project NULLS LAST,
    d.compose_service NULLS LAST,
    d.container_name;
