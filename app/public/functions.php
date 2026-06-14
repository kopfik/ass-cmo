<?php
// --- www/functions.php ---

function ip_prefix_matches_octets(string $ip, string $prefix): bool {
    $ip = trim($ip);
    $prefix = trim($prefix);

    if ($ip === '' || $prefix === '') {
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }

    $prefix = rtrim($prefix, '.');
    $prefix_parts = explode('.', $prefix);
    $ip_parts = explode('.', $ip);

    if (count($prefix_parts) < 1 || count($prefix_parts) > 4) {
        return false;
    }

    foreach ($prefix_parts as $idx => $part) {
        if ($part === '' || !ctype_digit($part)) {
            return false;
        }

        $octet = (int)$part;
        if ($octet < 0 || $octet > 255) {
            return false;
        }

        if (!isset($ip_parts[$idx]) || $ip_parts[$idx] !== (string)$octet) {
            return false;
        }
    }

    return true;
}

function get_location_info($ip) {
    if (!$ip) return ['loc' => 'unknown', 'seg' => 'remote'];

    $sites_file = getenv('ASSCMO_SITES_FILE') ?: '/app/config/sites.json';
    if (!file_exists($sites_file)) return ['loc' => 'unknown', 'seg' => 'remote'];

    $rules = json_decode(file_get_contents($sites_file), true);
    if (!is_array($rules)) return ['loc' => 'error-json', 'seg' => 'remote'];

    foreach ($rules as $rule) {
        // 1. Classic IPv4 prefix rules, matched on whole octets.
        if (isset($rule['subnets']) && is_array($rule['subnets'])) {
            foreach ($rule['subnets'] as $subnet) {
                if (ip_prefix_matches_octets((string)$ip, (string)$subnet)) {
                    return [
                        'loc' => $rule['location'] ?? 'unknown',
                        'seg' => $rule['network_segment'] ?? 'internal'
                    ];
                }
            }
        }

        // 2. VPN pool prefix, matched on whole octets.
        if (isset($rule['vpn_rw_pool']) && $rule['vpn_rw_pool'] !== null) {
            if (ip_prefix_matches_octets((string)$ip, (string)$rule['vpn_rw_pool'])) {
                return [
                    'loc' => $rule['location'] ?? 'unknown',
                    'seg' => 'vpn-rw'
                ];
            }
        }
    }

    return ['loc' => 'unknown', 'seg' => 'remote'];
}

