import { computed } from 'vue';

export function useDashboardMetrics(props) {
    const stats = computed(() => props.stats || {});
    const counters = computed(() => props.counters || {});
    const isAdmin = computed(() => !!props.isAdmin);

    const cpuPercent = computed(() => Math.min(stats.value?.cpu_percent ?? 0, 100));
    const ramPercent = computed(() => Math.min(stats.value?.ram_percent ?? 0, 100));
    const diskPercent = computed(() => Math.min(stats.value?.disk_percent ?? 0, 100));

    const ramUsedGb = computed(() => Math.round(((stats.value?.ram_used_mb ?? 0) / 1024) * 10) / 10);
    const ramTotalGb = computed(() => Math.round(((stats.value?.ram_total_mb ?? 0) / 1024) * 10) / 10);

    const loadAvg = computed(() => ({
        '1m': stats.value?.load_avg?.['1m'] ?? 0,
        '5m': stats.value?.load_avg?.['5m'] ?? 0,
        '15m': stats.value?.load_avg?.['15m'] ?? 0,
    }));

    const uptime = computed(() => stats.value?.uptime_human || 'Active');
    const hostname = computed(() => stats.value?.hostname || 'localhost');
    const serverIp = computed(() => stats.value?.server_ip || '127.0.0.1');
    const serverTime = computed(() => stats.value?.server_time || 'SYNC');
    const olsVersion = computed(() => stats.value?.ols_version || 'OpenLiteSpeed');
    const osPretty = computed(() => stats.value?.os_pretty || 'Linux');
    const timezone = computed(() => stats.value?.timezone || 'UTC');

    return {
        stats,
        counters,
        isAdmin,
        cpuPercent,
        ramPercent,
        diskPercent,
        ramUsedGb,
        ramTotalGb,
        loadAvg,
        uptime,
        hostname,
        serverIp,
        serverTime,
        olsVersion,
        osPretty,
        timezone,
    };
}
