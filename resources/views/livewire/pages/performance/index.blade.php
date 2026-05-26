<div wire:poll.5s>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Database', 'url' => url('nawasara-database-monitor/dashboard')], ['label' => 'Performance']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page-header
            title="Performance"
            description="Processlist, status global, dan slow query — refresh otomatis tiap 5 detik."
            :count="count($this->processes).' threads'">
        </x-nawasara-ui::page-header>

        @if (! $this->isConfigured)
            <x-nawasara-ui::empty-state
                icon="lucide-shield-alert"
                title="Kredensial belum diatur"
                description="Set kredensial database-monitor di Vault sebelum membuka halaman ini.">
                <x-nawasara-ui::button color="primary" :href="url('nawasara-vault')" wire:navigate>
                    Buka Vault
                </x-nawasara-ui::button>
            </x-nawasara-ui::empty-state>
        @else
            @php
                $status = $this->status;
                $hitRate = $this->bufferPoolHitRate();
                $connected = (int) ($status['Threads_connected'] ?? 0);
                $running = (int) ($status['Threads_running'] ?? 0);
                $maxUsed = (int) ($status['Max_used_connections'] ?? 0);
                $slowCount = (int) ($status['Slow_queries'] ?? 0);
                $abortedConn = (int) ($status['Aborted_connects'] ?? 0);
            @endphp

            {{-- Global status cards --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-2 mb-4">
                <x-nawasara-ui::stat-card compact
                    label="Threads connected"
                    :value="$connected"
                    color="primary"
                    icon="lucide-network"
                    :description="'peak: '.$maxUsed" />
                <x-nawasara-ui::stat-card compact
                    label="Threads running"
                    :value="$running"
                    :color="$running > 5 ? 'warning' : 'success'"
                    icon="lucide-zap" />
                <x-nawasara-ui::stat-card compact
                    label="Slow queries"
                    :value="number_format($slowCount)"
                    color="warning"
                    icon="lucide-snail"
                    description="sejak restart" />
                <x-nawasara-ui::stat-card compact
                    label="Aborted connects"
                    :value="number_format($abortedConn)"
                    :color="$abortedConn > 100 ? 'danger' : 'neutral'"
                    icon="lucide-plug-zap" />
                <x-nawasara-ui::stat-card compact
                    label="Buffer pool hit rate"
                    :value="$hitRate !== null ? $hitRate.'%' : '—'"
                    :color="$hitRate !== null && $hitRate < 95 ? 'warning' : 'success'"
                    icon="lucide-cpu" />
            </div>

            {{-- Filters --}}
            <div class="flex flex-wrap items-center gap-3 mb-3">
                <label class="inline-flex items-center gap-2 text-xs text-neutral-600 dark:text-neutral-400 cursor-pointer">
                    <input type="checkbox" wire:model.live="hideSleeping"
                        class="rounded border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800" />
                    Sembunyikan Sleep
                </label>
                <x-nawasara-ui::search-input model="userFilter" placeholder="Filter user..." />
                <x-nawasara-ui::search-input model="dbFilter" placeholder="Filter database..." />
            </div>

            {{-- Processlist --}}
            <x-nawasara-ui::table stickyLast
                :headers="['ID', 'User', 'DB', 'Command', 'Time', 'State', 'Query', '']">
                <x-slot:table>
                    @forelse ($this->processes as $proc)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-700/40">
                            <td class="px-4 py-2 text-xs text-neutral-500 dark:text-neutral-400">{{ $proc['id'] }}</td>
                            <td class="px-4 py-2 text-sm text-neutral-800 dark:text-neutral-100">
                                {{ $proc['user'] }}
                                @if ($proc['host'])
                                    <span class="text-xs text-neutral-400 block">{{ $proc['host'] }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm text-neutral-600 dark:text-neutral-300">
                                {{ $proc['db'] ?? '—' }}
                            </td>
                            <td class="px-4 py-2">
                                @php
                                    $cmdColor = match ($proc['command']) {
                                        'Sleep' => 'neutral',
                                        'Query' => 'primary',
                                        'Connect' => 'info',
                                        default => 'neutral',
                                    };
                                @endphp
                                <x-nawasara-ui::badge :color="$cmdColor">{{ $proc['command'] }}</x-nawasara-ui::badge>
                            </td>
                            <td class="px-4 py-2 text-sm">
                                <span @class([
                                    'text-rose-600 dark:text-rose-400 font-semibold' => $proc['time'] > 30,
                                    'text-amber-600 dark:text-amber-400' => $proc['time'] > 5 && $proc['time'] <= 30,
                                    'text-neutral-600 dark:text-neutral-300' => $proc['time'] <= 5,
                                ])>
                                    {{ $proc['time'] }}s
                                </span>
                            </td>
                            <td class="px-4 py-2 text-xs text-neutral-500 dark:text-neutral-400 max-w-[160px] truncate">
                                {{ $proc['state'] ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-xs text-neutral-700 dark:text-neutral-200 font-mono max-w-[420px]">
                                <div class="truncate" title="{{ $proc['info'] }}">
                                    {{ $proc['info'] ?? '—' }}
                                </div>
                            </td>
                            <td class="px-4 py-2 text-right">
                                @can('database-monitor.process.kill')
                                    @if ($proc['command'] !== 'Sleep' || $proc['time'] > 60)
                                        <x-nawasara-ui::icon-button
                                            icon="square-x"
                                            tooltip="Kill thread {{ $proc['id'] }}"
                                            wire:click="killQuery({{ $proc['id'] }})"
                                            wire:confirm="Yakin kill thread #{{ $proc['id'] }} ({{ $proc['user'] }})?" />
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-6">
                                <x-nawasara-ui::empty-state
                                    inline
                                    icon="lucide-zap-off"
                                    title="Tidak ada thread aktif"
                                    description="Server idle atau filter terlalu ketat." />
                            </td>
                        </tr>
                    @endforelse
                </x-slot:table>
            </x-nawasara-ui::table>

            {{-- Slow query section --}}
            <div class="mt-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Slow Query Log</h2>

                @if (! $this->slowLog['enabled'])
                    <x-nawasara-ui::empty-state
                        icon="lucide-snail"
                        title="Slow query log tidak aktif"
                        description="Aktifkan dengan SET GLOBAL slow_query_log = 'ON' dan log_output = 'TABLE' di MySQL untuk melihat query lambat di sini." />
                @elseif (! $this->slowLog['queryable'])
                    <x-nawasara-ui::empty-state
                        icon="lucide-file-text"
                        :title="'Slow log aktif, tapi tertulis ke file'"
                        :description="'log_output = '.($this->slowLog['log_output'] ?? '?').' — set log_output = TABLE supaya Nawasara bisa baca dari mysql.slow_log. File: '.($this->slowLog['log_file'] ?? '?')" />
                @else
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 mb-2">
                        Ambang batas: query lebih lama dari
                        <strong>{{ $this->slowLog['long_query_time'] }}s</strong>.
                        Menampilkan 20 entri terakhir dari <code>mysql.slow_log</code>.
                    </p>

                    <x-nawasara-ui::table :headers="['Waktu', 'User', 'DB', 'Query time', 'Rows examined', 'SQL']">
                        <x-slot:table>
                            @forelse ($this->recentSlow as $row)
                                <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-700/40">
                                    <td class="px-4 py-2 text-xs text-neutral-500 dark:text-neutral-400 whitespace-nowrap">
                                        {{ $row['started'] }}
                                    </td>
                                    <td class="px-4 py-2 text-sm text-neutral-800 dark:text-neutral-100">{{ $row['user'] }}</td>
                                    <td class="px-4 py-2 text-sm text-neutral-600 dark:text-neutral-300">{{ $row['db'] ?? '—' }}</td>
                                    <td class="px-4 py-2 text-sm font-semibold text-rose-600 dark:text-rose-400">
                                        {{ number_format($row['query_time'], 2) }}s
                                    </td>
                                    <td class="px-4 py-2 text-sm text-neutral-600 dark:text-neutral-300">
                                        {{ number_format($row['rows_examined']) }}
                                    </td>
                                    <td class="px-4 py-2 text-xs text-neutral-700 dark:text-neutral-200 font-mono max-w-[480px]">
                                        <div class="truncate" title="{{ $row['sql'] }}">{{ $row['sql'] }}</div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-6">
                                        <x-nawasara-ui::empty-state
                                            inline
                                            icon="lucide-circle-check"
                                            title="Tidak ada slow query"
                                            description="Belum ada query yang melewati ambang batas." />
                                    </td>
                                </tr>
                            @endforelse
                        </x-slot:table>
                    </x-nawasara-ui::table>
                @endif
            </div>
        @endif
    </x-nawasara-ui::page.container>
</div>
