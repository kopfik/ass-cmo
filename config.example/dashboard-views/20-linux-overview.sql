-- label: Linux overview
-- description: Linux hosts grouped by OS family, major version and kernel track.

WITH normalized AS (
    SELECT
        i.*,
        CASE
            WHEN i.os_name ILIKE '%windows%' THEN 'windows'
            WHEN i.os_name ILIKE '%debian%' THEN 'debian'
            WHEN i.os_name ILIKE '%ubuntu%' THEN 'ubuntu'
            WHEN i.os_name ILIKE '%arch%' THEN 'arch'
            ELSE 'linux'
        END AS os_family,
        COALESCE(
            substring(i.os_name::text, 'Debian GNU/Linux ([0-9]+)'),
            substring(i.os_name::text, 'Debian ([0-9]+)'),
            substring(i.os_name::text, 'Ubuntu ([0-9]+)'),
            'unknown'
        ) AS os_major,
        CASE
            WHEN i.kernel_version LIKE '%cloud-amd64' THEN 'debian-cloud-amd64'
            WHEN i.kernel_version LIKE '%+deb%amd64' THEN 'debian-amd64'
            WHEN i.kernel_version LIKE '%-generic' THEN 'ubuntu-generic'
            WHEN i.kernel_version LIKE '%-lts' THEN 'arch-lts'
            WHEN i.kernel_version LIKE '%-zen' THEN 'arch-zen'
            ELSE 'mainline'
        END AS kernel_track,
        lpad(COALESCE((regexp_match(i.kernel_version::text, '^([0-9]+)'))[1], '0'), 6, '0') || '.' ||
        lpad(COALESCE((regexp_match(i.kernel_version::text, '^[0-9]+\.([0-9]+)'))[1], '0'), 6, '0') || '.' ||
        lpad(COALESCE((regexp_match(i.kernel_version::text, '^[0-9]+\.[0-9]+\.([0-9]+)'))[1], '0'), 6, '0') || '.' ||
        lpad(COALESCE((regexp_match(i.kernel_version::text, '-([0-9]+)'))[1], '0'), 6, '0') AS kernel_sort_key
    FROM inventory i
    WHERE i.os_name NOT ILIKE '%windows%'
      AND NOT (
          i.os_name ILIKE '%proxmox virtual environment%'
          OR i.os_name ILIKE '%proxmox ve%'
          OR i.os_name ILIKE '%proxmox backup server%'
          OR i.os_name ILIKE '%proxmox mail gateway%'
          OR i.os_name ILIKE '%proxmox datacenter manager%'
          OR i.kernel_version LIKE '%-pve'
      )
),
kernel_stats AS (
    SELECT DISTINCT ON (os_name, os_family, os_major, kernel_track)
        os_name,
        os_family,
        os_major,
        kernel_track,
        kernel_version AS latest_known,
        kernel_sort_key AS latest_sort_key
    FROM normalized
    WHERE os_family <> 'windows'
    ORDER BY os_name, os_family, os_major, kernel_track, kernel_sort_key DESC, system_upgrade_time DESC NULLS LAST, inventory_update_time DESC NULLS LAST
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
    n.kernel_version AS kernel,
    ks.latest_known,
    CASE
        WHEN n.disk_usage_percent IS NULL THEN '⬜ unknown'
        WHEN n.disk_usage_percent <= 50 THEN '🟩 ' || n.disk_usage_percent || '%'
        WHEN n.disk_usage_percent <= 65 THEN '🟨 ' || n.disk_usage_percent || '%'
        WHEN n.disk_usage_percent <= 80 THEN '🟧 ' || n.disk_usage_percent || '%'
        ELSE '🟥 ' || n.disk_usage_percent || '%'
    END AS disk,
    n.os_name,
    date_trunc('second', n.inventory_update_time AT TIME ZONE 'Europe/Prague') AS last_seen,
    notes
FROM normalized n
JOIN kernel_stats ks
    ON ks.os_name = n.os_name
   AND ks.os_family = n.os_family
   AND ks.os_major = n.os_major
   AND ks.kernel_track = n.kernel_track
WHERE n.os_family <> 'windows'
ORDER BY
    CASE
        WHEN n.reboot_required = true THEN 1
        WHEN n.system_upgrade_time > n.system_boot_time THEN 2
        WHEN n.kernel_sort_key < ks.latest_sort_key THEN 3
        ELSE 4
    END,
    n.location,
    n.hostname;
