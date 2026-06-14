CREATE TABLE IF NOT EXISTS inventory (
    uid character varying(64) PRIMARY KEY,

    hostname character varying(255) NOT NULL,
    fqdn character varying(255),

    primary_interface character varying(50),
    primary_ipv4_addr character varying(45),
    ipv4_gateway character varying(45),
    primary_ipv6_addr character varying(45),
    ipv6_gateway character varying(45),

    dns_servers jsonb DEFAULT '[]'::jsonb,
    all_ipv4_addr jsonb DEFAULT '[]'::jsonb,
    all_ipv6_addr jsonb DEFAULT '[]'::jsonb,
    listening_ports jsonb DEFAULT '[]'::jsonb,

    location character varying(100),
    network_segment character varying(100),

    os_name character varying(255),
    os_type character varying(50),
    kernel_version character varying(255),

    reboot_required boolean DEFAULT false,
    pending_updates_count integer DEFAULT 0,

    cpu_model character varying(255),
    cpu_cores integer,
    cpu_architecture character varying(20),
    ram_gb double precision,

    disk_total_gb double precision,
    disk_used_gb double precision,
    disk_free_gb double precision,
    disk_usage_percent integer,

    docker_installed boolean DEFAULT false,
    docker_version character varying(50),

    admin_access jsonb DEFAULT '[]'::jsonb,

    uptime_seconds bigint,
    system_boot_time timestamptz,
    system_upgrade_time timestamptz,
    inventory_update_time timestamptz DEFAULT CURRENT_TIMESTAMP,

    agent_name text,
    agent_version text,
    agent_channel text,
    agent_update_time timestamptz,

    notes text,
    tags jsonb DEFAULT '{}'::jsonb
);

CREATE INDEX IF NOT EXISTS idx_inventory_hostname
    ON inventory (hostname);

CREATE INDEX IF NOT EXISTS idx_inventory_primary_ipv4_addr
    ON inventory (primary_ipv4_addr);

CREATE INDEX IF NOT EXISTS idx_inventory_location
    ON inventory (location);

CREATE INDEX IF NOT EXISTS idx_inventory_network_segment
    ON inventory (network_segment);

CREATE INDEX IF NOT EXISTS idx_inventory_update_time
    ON inventory (inventory_update_time);

CREATE INDEX IF NOT EXISTS idx_inventory_system_upgrade_time
    ON inventory (system_upgrade_time);

CREATE INDEX IF NOT EXISTS idx_inventory_reboot_required_true
    ON inventory (reboot_required)
    WHERE reboot_required = true;

CREATE INDEX IF NOT EXISTS idx_inventory_listening_ports_gin
    ON inventory USING gin (listening_ports);

CREATE INDEX IF NOT EXISTS idx_inventory_admin_access_gin
    ON inventory USING gin (admin_access);

CREATE INDEX IF NOT EXISTS idx_inventory_tags_gin
    ON inventory USING gin (tags);
