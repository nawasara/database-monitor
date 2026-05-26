<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Database', 'url' => '#'], ['label' => 'Dashboard']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page-header
            title="Database Monitor"
            description="Inventaris dan kesehatan server MySQL Kominfo."
            :count="$this->server?->database_count ? $this->server->database_count.' databases' : null">
            @if ($this->isConfigured && $this->server)
                @can('database-monitor.sync.execute')
                    <x-nawasara-ui::icon-button
                        icon="refresh-cw"
                        tooltip="Sync sekarang"
                        wire:click="syncNow"
                        loadingTarget="syncNow" />
                @endcan
            @endif
        </x-nawasara-ui::page-header>

        @if (! $this->isConfigured)
            <x-nawasara-ui::empty-state
                icon="lucide-shield-alert"
                title="Kredensial belum diatur"
                description="Buka Vault dan isi grup database-monitor dengan host, user, dan password sebelum sinkronisasi pertama.">
                <x-nawasara-ui::button
                    color="primary"
                    :href="url('nawasara-vault')"
                    wire:navigate>
                    Buka Vault
                </x-nawasara-ui::button>
            </x-nawasara-ui::empty-state>
        @elseif (! $this->server)
            <x-nawasara-ui::empty-state
                icon="lucide-database"
                title="Belum pernah sinkron"
                description="Klik Sync sekarang untuk mengisi data inventaris pertama kali.">
                @can('database-monitor.sync.execute')
                    <x-nawasara-ui::button
                        color="primary"
                        wire:click="syncNow"
                        loadingTarget="syncNow">
                        <x-slot:icon><x-lucide-refresh-cw class="size-4" /></x-slot:icon>
                        Sync sekarang
                    </x-nawasara-ui::button>
                @endcan
            </x-nawasara-ui::empty-state>
        @else
            @php
                $server = $this->server;
                $userCount = $server->databases()->where('kind', \Nawasara\DatabaseMonitor\Models\DbDatabase::KIND_USER)->count();
                $systemCount = $server->databases()->where('kind', \Nawasara\DatabaseMonitor\Models\DbDatabase::KIND_SYSTEM)->count();
                $uptimeLabel = $server->uptime_seconds
                    ? \Carbon\CarbonInterval::seconds($server->uptime_seconds)->cascade()->forHumans(['short' => true])
                    : '—';
                $statusColor = match ($server->status) {
                    \Nawasara\DatabaseMonitor\Models\DbServer::STATUS_ONLINE => 'success',
                    \Nawasara\DatabaseMonitor\Models\DbServer::STATUS_UNREACHABLE => 'danger',
                    \Nawasara\DatabaseMonitor\Models\DbServer::STATUS_DEGRADED => 'warning',
                    default => 'neutral',
                };
            @endphp

            {{-- Stat cards — at-a-glance overview --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-2 mb-4">
                <x-nawasara-ui::stat-card compact
                    label="Status"
                    :value="ucfirst($server->status)"
                    :color="$statusColor"
                    icon="lucide-circle-dot"
                    :description="$server->last_synced_at?->diffForHumans() ?? '—'" />
                <x-nawasara-ui::stat-card compact
                    label="User DB"
                    :value="$userCount"
                    color="primary"
                    icon="lucide-database" />
                <x-nawasara-ui::stat-card compact
                    label="System DB"
                    :value="$systemCount"
                    color="neutral"
                    icon="lucide-cog" />
                <x-nawasara-ui::stat-card compact
                    label="Total size"
                    :value="\Nawasara\DatabaseMonitor\Livewire\Dashboard\Index::formatBytes($this->totalSizeBytes)"
                    color="warning"
                    icon="lucide-hard-drive"
                    :description="$this->showSystem ? 'termasuk system' : 'user databases'" />
                <x-nawasara-ui::stat-card compact
                    label="Uptime"
                    :value="$uptimeLabel"
                    color="info"
                    icon="lucide-clock" />
            </div>

            {{-- Server detail card --}}
            <x-nawasara-ui::page.card>
                <div class="flex items-center gap-2 mb-3">
                    <x-lucide-server class="size-5 text-neutral-500" />
                    <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100">
                        {{ $server->label }}
                    </p>
                </div>

                @if ($server->status_message)
                    <div class="mb-3 rounded-md bg-rose-50 border border-rose-200 px-3 py-2 dark:bg-rose-900/20 dark:border-rose-800">
                        <p class="text-sm text-rose-700 dark:text-rose-300">{{ $server->status_message }}</p>
                    </div>
                @endif

                <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-neutral-500 dark:text-neutral-400">Versi</dt>
                        <dd class="font-medium text-neutral-800 dark:text-neutral-100 mt-0.5">{{ $server->version ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-neutral-500 dark:text-neutral-400">Hostname</dt>
                        <dd class="font-medium text-neutral-800 dark:text-neutral-100 mt-0.5">{{ $server->hostname ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-neutral-500 dark:text-neutral-400">OS</dt>
                        <dd class="font-medium text-neutral-800 dark:text-neutral-100 mt-0.5">{{ $server->os ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-neutral-500 dark:text-neutral-400">Max koneksi</dt>
                        <dd class="font-medium text-neutral-800 dark:text-neutral-100 mt-0.5">{{ $server->max_connections ?? '—' }}</dd>
                    </div>
                </dl>
            </x-nawasara-ui::page.card>

            {{-- Database table --}}
            <div class="mt-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-sm text-neutral-600 dark:text-neutral-300">
                        Menampilkan {{ $this->databases->count() }} dari {{ $server->database_count }} database
                    </div>
                    <label class="inline-flex items-center gap-2 text-xs text-neutral-600 dark:text-neutral-400 cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model.live="showSystem"
                            class="rounded border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800" />
                        Tampilkan system schema
                    </label>
                </div>

                <x-nawasara-ui::table stickyLast :headers="['Nama', 'Jenis', 'Ukuran', 'Tabel', 'Baris (est.)', 'Terakhir sinkron', '']">
                    <x-slot:table>
                        @forelse ($this->databases as $database)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-700/40">
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <x-lucide-database class="size-4 text-neutral-400" />
                                        <span class="text-sm text-neutral-800 dark:text-neutral-100">{{ $database->name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    @if ($database->isSystem())
                                        <x-nawasara-ui::badge color="neutral">system</x-nawasara-ui::badge>
                                    @else
                                        <x-nawasara-ui::badge color="primary">user</x-nawasara-ui::badge>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-sm text-neutral-700 dark:text-neutral-200">
                                    @if ($database->totalSizeBytes() > 0)
                                        {{ \Nawasara\DatabaseMonitor\Livewire\Dashboard\Index::formatBytes($database->totalSizeBytes()) }}
                                    @else
                                        <span class="text-neutral-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-sm text-neutral-600 dark:text-neutral-300">
                                    {{ $database->table_count ?? '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-sm text-neutral-600 dark:text-neutral-300">
                                    {{ $database->row_estimate !== null ? number_format($database->row_estimate) : '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-sm text-neutral-500 dark:text-neutral-400">
                                    {{ $database->last_synced_at?->diffForHumans() ?? '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <x-nawasara-ui::icon-button
                                        icon="layers"
                                        tooltip="Lihat tabel terbesar"
                                        wire:click="openDetail('{{ $database->name }}')" />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-6">
                                    <x-nawasara-ui::empty-state
                                        inline
                                        icon="lucide-search-x"
                                        title="Tidak ada database"
                                        description="Aktifkan filter 'Tampilkan system schema' atau jalankan sync." />
                                </td>
                            </tr>
                        @endforelse
                    </x-slot:table>
                </x-nawasara-ui::table>

                {{-- Top tables detail modal — Alpine id-mode, listens for
                     'modal-open:database-monitor-top-tables' dispatch. --}}
                <x-nawasara-ui::modal
                    id="database-monitor-top-tables"
                    :title="$detailName ? 'Top tables — '.$detailName : 'Top tables'"
                    maxWidth="3xl">
                    @if ($detailName && empty($detailTopTables))
                        <p class="text-sm text-neutral-500 dark:text-neutral-400 py-6 text-center">
                            Tidak ada tabel untuk ditampilkan.
                        </p>
                    @elseif ($detailName)
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mb-3">
                            10 tabel terbesar berdasarkan data + index size.
                            Jumlah baris adalah estimasi InnoDB (cepat, tidak 100% akurat).
                        </p>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-xs text-neutral-500 dark:text-neutral-400 uppercase tracking-wide border-b border-neutral-200 dark:border-neutral-700">
                                        <th class="text-left py-2 px-2">Tabel</th>
                                        <th class="text-right py-2 px-2">Data</th>
                                        <th class="text-right py-2 px-2">Index</th>
                                        <th class="text-right py-2 px-2">Total</th>
                                        <th class="text-right py-2 px-2">Baris (est.)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($detailTopTables as $row)
                                        <tr class="border-b border-neutral-100 dark:border-neutral-800">
                                            <td class="py-2 px-2 font-medium text-neutral-800 dark:text-neutral-100">
                                                {{ $row['table'] }}
                                            </td>
                                            <td class="py-2 px-2 text-right text-neutral-700 dark:text-neutral-200">
                                                {{ \Nawasara\DatabaseMonitor\Livewire\Dashboard\Index::formatBytes($row['data']) }}
                                            </td>
                                            <td class="py-2 px-2 text-right text-neutral-700 dark:text-neutral-200">
                                                {{ \Nawasara\DatabaseMonitor\Livewire\Dashboard\Index::formatBytes($row['index']) }}
                                            </td>
                                            <td class="py-2 px-2 text-right font-semibold text-neutral-800 dark:text-neutral-100">
                                                {{ \Nawasara\DatabaseMonitor\Livewire\Dashboard\Index::formatBytes($row['data'] + $row['index']) }}
                                            </td>
                                            <td class="py-2 px-2 text-right text-neutral-600 dark:text-neutral-300">
                                                {{ number_format($row['rows']) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <x-slot:footer>
                        <x-nawasara-ui::button color="neutral" wire:click="closeDetail">
                            Tutup
                        </x-nawasara-ui::button>
                    </x-slot:footer>
                </x-nawasara-ui::modal>
            </div>
        @endif
    </x-nawasara-ui::page.container>
</div>
