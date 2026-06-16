-- label: Windows overview
-- description: Windows hosts grouped by major version and client/server track.

WITH normalized AS (
    SELECT
        i.*,
        CASE
            WHEN i.os_name ILIKE '%microsoft%' OR i.os_name ILIKE '%windows%' THEN 'windows'
            ELSE 'other'
        END AS os_family,
        COALESCE(
            substring(i.os_name::text, 'Windows Server ([0-9]+)'),
            substring(i.os_name::text, 'Windows ([0-9]+)'),
            'unknown'
        ) AS os_major,
        CASE
            WHEN i.os_name ILIKE '%server%' THEN 'windows-server'
            ELSE 'windows-client'
        END AS os_track,
        lpad(COALESCE((regexp_match(i.kernel_version::text, '^([0-9]+)'))[1], '0'), 6, '0') || '.' ||
        lpad(COALESCE((regexp_match(i.kernel_version::text, '^[0-9]+\.([0-9]+)'))[1], '0'), 6, '0') || '.' ||
        lpad(COALESCE((regexp_match(i.kernel_version::text, '^[0-9]+\.[0-9]+\.([0-9]+)'))[1], '0'), 6, '0') || '.' ||
        lpad(COALESCE((regexp_match(i.kernel_version::text, '^[0-9]+\.[0-9]+\.[0-9]+\.([0-9]+)'))[1], '0'), 6, '0') AS kernel_sort_key
    FROM inventory i
    WHERE i.os_name ILIKE '%microsoft%' OR i.os_name ILIKE '%windows%'
),
kernel_stats AS (
    SELECT DISTINCT ON (os_family, os_major, os_track)
        os_family,
        os_major,
        os_track,
        kernel_version AS latest_known,
        kernel_sort_key AS latest_sort_key
    FROM normalized
    ORDER BY os_family, os_major, os_track, kernel_sort_key DESC, system_upgrade_time DESC NULLS LAST, inventory_update_time DESC NULLS LAST
)
SELECT
    n.hostname,
    CASE
        WHEN n.reboot_required = true THEN '🔴 REBOOT'
        WHEN n.system_upgrade_time > n.system_boot_time THEN '🟡 POST-UP'
        WHEN n.kernel_sort_key < ks.latest_sort_key THEN '🔵 UPDATE'
        ELSE '🟢 OK'
    END AS status,
    n.location,
    n.primary_ipv4_addr AS ip,
    date_trunc('minute', justify_interval(now() - n.system_boot_time)) AS uptime,
    n.kernel_version,
    ks.latest_known,
    n.disk_usage_percent || '%' AS disk,
    n.os_name,
    notes,
    date_trunc('second', n.inventory_update_time AT TIME ZONE 'Europe/Prague') AS last_seen
FROM normalized n
JOIN kernel_stats ks ON ks.os_family = n.os_family AND ks.os_major = n.os_major AND ks.os_track = n.os_track
ORDER BY
    CASE
        WHEN n.reboot_required = true THEN 1
        WHEN n.system_upgrade_time > n.system_boot_time THEN 2
        WHEN n.kernel_sort_key < ks.latest_sort_key THEN 3
        ELSE 4
    END,
    n.location,
    n.hostname;
