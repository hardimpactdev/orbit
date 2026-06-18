#!/usr/bin/awk -f

BEGIN {
    marker = "[orbit-e2e]"
    separator = "\034"
}

$1 != marker {
    next
}

NF < 4 {
    next
}

{
    label = $2
    event = $3
    duration = $NF

    if (duration !~ /^[0-9]+(\.[0-9]+)?s$/) {
        next
    }

    sub(/s$/, "", duration)

    for (i = 4; i < NF; i++) {
        event = event "." $i
    }

    key = label separator event
    count[key]++
    values[key, count[key]] = duration + 0

    if (!(key in seen)) {
        seen[key] = 1
        order[++order_count] = key
    }
}

END {
    for (i = 1; i <= order_count; i++) {
        key = order[i]
        n = count[key]

        if (n == 0) {
            continue
        }

        sort_values(key, n)

        p50_index = percentile_index(n, 0.50)
        p95_index = percentile_index(n, 0.95)

        output_key = key
        gsub(separator, "/", output_key)

        printf "%s n=%d p50=%g p95=%g\n", output_key, n, values[key, p50_index], values[key, p95_index]
    }
}

function percentile_index(n, percentile,    result_index) {
    result_index = int((n * percentile) + 0.999999)

    if (result_index < 1) {
        return 1
    }

    if (result_index > n) {
        return n
    }

    return result_index
}

function sort_values(key, n,    i, j, current) {
    for (i = 2; i <= n; i++) {
        current = values[key, i]
        j = i - 1

        while (j >= 1 && values[key, j] > current) {
            values[key, j + 1] = values[key, j]
            j--
        }

        values[key, j + 1] = current
    }
}
