-- label: Agent versions
-- description: Agent bundle version overview.
-- Expected versions in this view must be kept in sync with agents/linux/VERSION and agents/windows/VERSION before each release.

SELECT
    hostname,
    notes,
    primary_ipv4_addr AS ip,
    CASE
        WHEN os_name ILIKE '%microsoft%' OR os_name ILIKE '%windows%' THEN 'windows'
        ELSE 'linux'
    END AS agent_platform,
    CASE
        WHEN os_name ILIKE '%microsoft%' OR os_name ILIKE '%windows%' THEN
            CASE
                WHEN agent_version = '0.8.0' THEN 'ok'
                ELSE 'outdated'
            END
        ELSE
            CASE
                WHEN agent_version = '0.8.0' THEN 'ok'
                ELSE 'outdated'
            END
    END AS agent_state,
    CASE
        WHEN os_name ILIKE '%microsoft%' OR os_name ILIKE '%windows%' THEN
            CASE
                WHEN agent_version = '0.8.0' THEN '🟢 OK'
                ELSE '🟡 OUTDATED'
            END
        ELSE
            CASE
                WHEN agent_version = '0.8.0' THEN '🟢 OK'
                ELSE '🟡 OUTDATED'
            END
    END AS agent_status,
    agent_version,
    os_name,
    date_trunc('second', agent_update_time AT TIME ZONE 'Europe/Prague') AS agent_update_time,
    date_trunc('second', inventory_update_time AT TIME ZONE 'Europe/Prague') AS last_seen
FROM inventory
ORDER BY
    CASE
        WHEN os_name ILIKE '%microsoft%' OR os_name ILIKE '%windows%' THEN
            CASE WHEN agent_version = '0.8.0' THEN 2 ELSE 1 END
        ELSE
            CASE WHEN agent_version = '0.8.0' THEN 2 ELSE 1 END
    END,
    os_name,
    agent_version,
    hostname;
