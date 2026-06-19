-- label: General overview
-- description: Basic inventory overview ordered by upgrade age and hostname.

SELECT
    hostname,
    primary_ipv4_addr AS ip,
    location,
    CASE
        WHEN system_upgrade_time IS NULL THEN '⚪ UNKNOWN'
        WHEN now() - system_upgrade_time > interval '90 days' THEN '🔴 ' || floor(EXTRACT(epoch FROM (now() - system_upgrade_time)) / 86400)::integer || 'd'
        WHEN now() - system_upgrade_time > interval '30 days' THEN '🟡 ' || floor(EXTRACT(epoch FROM (now() - system_upgrade_time)) / 86400)::integer || 'd'
        ELSE '🟢 ' || floor(EXTRACT(epoch FROM (now() - system_upgrade_time)) / 86400)::integer || 'd'
    END AS upg_age,
    CASE
        WHEN reboot_required = true THEN 'NEEDED'
        ELSE ''
    END AS reboot,
    date_trunc('minute', justify_interval(now() - system_boot_time)) AS uptime,
    kernel_version AS kernel,
    os_name,
    cpu_cores AS cores,
    ram_gb AS ram,
    date_trunc('second', inventory_update_time AT TIME ZONE 'Europe/Prague') AS last_seen,
    notes,
    tags
FROM inventory
ORDER BY
    CASE
        WHEN system_upgrade_time IS NULL THEN 0
        WHEN now() - system_upgrade_time > interval '90 days' THEN 1
        WHEN now() - system_upgrade_time > interval '30 days' THEN 2
        ELSE 3
    END,
    floor(EXTRACT(epoch FROM (now() - system_upgrade_time)) / 86400)::integer DESC NULLS LAST,
    hostname;
