-- Docker containers reported by ASS-CMO agents.
--
-- Current state only, not a history table: every inventory scan that carries a
-- docker_containers payload replaces the full container set for that host.
-- A scan without the key (agent too old, or Docker unreachable) must leave the
-- stored rows untouched.

CREATE TABLE IF NOT EXISTS docker_containers (
    uid character varying(64) NOT NULL
        REFERENCES inventory (uid) ON DELETE CASCADE,
    container_id character varying(64) NOT NULL,

    container_name character varying(255),
    image_ref character varying(255),
    image_id character varying(128),
    image_version character varying(64),

    compose_project character varying(255),
    compose_service character varying(255),
    compose_working_dir character varying(512),

    state character varying(32),
    health_status character varying(32),
    exit_code integer,
    restart_count integer,
    restart_policy character varying(32),

    created_at timestamptz,
    started_at timestamptz,
    finished_at timestamptz,

    ports jsonb DEFAULT '[]'::jsonb,
    mounts jsonb DEFAULT '[]'::jsonb,

    present boolean NOT NULL DEFAULT true,
    first_seen_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    missing_since timestamptz,

    PRIMARY KEY (uid, container_id)
);

COMMENT ON TABLE docker_containers IS
    'Current Docker container state per host, replaced on every inventory scan that reports containers. Not a history table.';

COMMENT ON COLUMN docker_containers.present IS
    'False when the container was missing from the latest scan for this host. The state column keeps the last observed Docker state and is deliberately not overwritten with a synthetic gone value.';

COMMENT ON COLUMN docker_containers.missing_since IS
    'Set the first time the container went missing, cleared when it reappears.';

-- The primary key already covers lookups by uid, so no separate uid index.

CREATE INDEX IF NOT EXISTS idx_docker_containers_compose
    ON docker_containers (compose_project, compose_service);

CREATE INDEX IF NOT EXISTS idx_docker_containers_missing_since
    ON docker_containers (missing_since)
    WHERE present = false;
