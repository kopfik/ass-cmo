-- label: Linux bulk agent update
-- description: Copy-paste SSH loop for outdated Linux agents. Copy this view to config.local/dashboard-views/ and replace placeholders before use.
-- placeholders:
--   __ASSCMO_DASHBOARD_SSH_USER__  SSH user used for generated targets, for example root or admin
--   __ASSCMO_BASE_URL__            Public ASS-CMO base URL, for example https://ass-cmo.example.com
--   __ASSCMO_LINUX_AGENT_VERSION__ Expected Linux agent version, usually agents/linux/VERSION

WITH config AS (
    SELECT '__ASSCMO_LINUX_AGENT_VERSION__' AS expected_version
),
guard AS (
    SELECT
        expected_version,
        -- True while the expected-version placeholder is still unreplaced.
        -- The right-hand sentinel is split so the copy-and-replace step cannot rewrite it,
        -- which lets us detect a verbatim copy and refuse to target every host.
        expected_version = ('__ASSCMO_LINUX_' || 'AGENT_VERSION__') AS placeholder_unset
    FROM config
),
targets AS (
    SELECT
        hostname,
        primary_ipv4_addr,
        '__ASSCMO_DASHBOARD_SSH_USER__@' || primary_ipv4_addr AS ssh_target
    FROM inventory, guard
    WHERE
        guard.placeholder_unset IS FALSE
        AND NOT (os_name ILIKE '%microsoft%' OR os_name ILIKE '%windows%')
        AND agent_version IS DISTINCT FROM guard.expected_version
        AND primary_ipv4_addr IS NOT NULL
        AND primary_ipv4_addr <> ''
    ORDER BY hostname
),
command_parts AS (
    SELECT
        count(*) AS target_count,
        string_agg(ssh_target, ' ' ORDER BY hostname) AS hosts
    FROM targets
)
SELECT
    command_parts.target_count,
    CASE
        WHEN guard.placeholder_unset THEN
            'Replace the __ASSCMO_LINUX_' || 'AGENT_VERSION__ placeholder with the expected Linux agent version before using this view.'
        WHEN command_parts.hosts IS NULL THEN 'No outdated Linux agents found.'
        ELSE
            'for h in ' || command_parts.hosts || chr(59) || ' do echo "=== $h ==="' || chr(59) || ' ssh -o BatchMode=yes "$h" ' ||
            '''tmp="$(mktemp)" && trap ''"''"''rm -f "$tmp"''"''"'' EXIT && curl -fsSL ''"''"''__ASSCMO_BASE_URL__/agents/linux/install-ass-cmo-agent.sh''"''"'' -o "$tmp" && sh "$tmp" --base-url ''"''"''__ASSCMO_BASE_URL__''"''"''''' ||
            chr(59) || ' done'
    END AS linux_bulk_update_oneliner
FROM command_parts, guard
