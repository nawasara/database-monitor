<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[
                ['label' => 'Database', 'url' => url('nawasara-database-monitor/dashboard')],
                ['label' => 'Administration'],
                ['label' => 'Databases'],
            ]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page-header
            title="Administration — Databases"
            description="CREATE / DROP database. Aksi destruktif gated oleh Sudo Mode dan audit log."
            :count="$this->databases->count().' user DB'">
            @can('database-monitor.database.create')
                <x-nawasara-ui::button
                    color="primary"
                    x-on:click="$dispatch('open-modal', 'db-monitor-create')">
                    <x-slot:icon><x-lucide-plus class="size-4" /></x-slot:icon>
                    Tambah Database
                </x-nawasara-ui::button>
            @endcan
        </x-nawasara-ui::page-header>

        @if (! $this->adminConfigured)
            <x-nawasara-ui::empty-state
                icon="lucide-shield-alert"
                title="Admin credential belum di-set"
                description="Isi field admin_username + admin_password di group Vault 'database-monitor' sebelum menggunakan halaman ini.">
                <x-nawasara-ui::button color="primary" :href="url('nawasara-vault')" wire:navigate>
                    Buka Vault
                </x-nawasara-ui::button>
            </x-nawasara-ui::empty-state>
        @else
            <x-nawasara-ui::table stickyLast :headers="['Nama', 'Ukuran', 'Tabel', '']">
                <x-slot:table>
                    @forelse ($this->databases as $db)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-700/40">
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <x-lucide-database class="size-4 text-neutral-400" />
                                    <span class="text-sm text-neutral-800 dark:text-neutral-100">{{ $db->name }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-sm text-neutral-700 dark:text-neutral-200">
                                @if ($db->totalSizeBytes() > 0)
                                    {{ \Nawasara\DatabaseMonitor\Livewire\Dashboard\Index::formatBytes($db->totalSizeBytes()) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-sm text-neutral-600 dark:text-neutral-300">
                                {{ $db->table_count ?? '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                @can('database-monitor.database.drop')
                                    <x-nawasara-ui::icon-button
                                        icon="trash-2"
                                        tooltip="Hapus database"
                                        wire:click="openDrop('{{ $db->name }}')" />
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6">
                                <x-nawasara-ui::empty-state
                                    inline
                                    icon="lucide-database-zap"
                                    title="Belum ada database user"
                                    description="Jalankan sync di Dashboard atau buat database baru." />
                            </td>
                        </tr>
                    @endforelse
                </x-slot:table>
            </x-nawasara-ui::table>

            {{-- CREATE modal --}}
            <x-nawasara-ui::modal id="db-monitor-create" title="Buat database baru" maxWidth="md">
                <form wire:submit="createDatabase" class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">Nama database</label>
                        <input type="text" wire:model="createName" placeholder="contoh: db_aplikasi_baru"
                            class="w-full rounded-md border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm" />
                        @error('createName') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">Charset</label>
                            <input type="text" wire:model="createCharset"
                                class="w-full rounded-md border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">Collation</label>
                            <input type="text" wire:model="createCollation"
                                class="w-full rounded-md border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm" />
                        </div>
                    </div>

                    <p class="text-xs text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 rounded-md p-2">
                        Aksi ini perlu Sudo Mode. Akan diminta verifikasi ulang kalau window sudo habis.
                    </p>
                </form>

                <x-slot:footer>
                    <x-nawasara-ui::button color="neutral" x-on:click="$dispatch('close-modal', 'db-monitor-create')">
                        Batal
                    </x-nawasara-ui::button>
                    <x-nawasara-ui::button color="primary" wire:click="createDatabase">
                        Buat database
                    </x-nawasara-ui::button>
                </x-slot:footer>
            </x-nawasara-ui::modal>

            {{-- DROP confirm modal --}}
            <x-nawasara-ui::modal id="db-monitor-drop"
                :title="'Hapus database — '.($dropTarget ?? '')"
                maxWidth="md">
                @if ($dropTarget)
                    <div class="space-y-3">
                        <div class="rounded-md bg-rose-50 border border-rose-200 dark:bg-rose-900/20 dark:border-rose-800 px-3 py-2">
                            <p class="text-sm text-rose-700 dark:text-rose-300 font-medium">
                                Aksi ini TIDAK BISA di-undo.
                            </p>
                            <p class="text-xs text-rose-600 dark:text-rose-400 mt-1">
                                Semua tabel + data di <code>{{ $dropTarget }}</code> akan hilang. Pastikan backup tersedia.
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">
                                Ketik ulang <code>{{ $dropTarget }}</code> untuk konfirmasi:
                            </label>
                            <input type="text" wire:model="dropConfirmName"
                                class="w-full rounded-md border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm font-mono" />
                            @error('dropConfirmName') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif

                <x-slot:footer>
                    <x-nawasara-ui::button color="neutral" wire:click="closeDrop">Batal</x-nawasara-ui::button>
                    <x-nawasara-ui::button color="danger" wire:click="confirmDrop"
                        wire:loading.attr="disabled">
                        Hapus permanen
                    </x-nawasara-ui::button>
                </x-slot:footer>
            </x-nawasara-ui::modal>
        @endif
    </x-nawasara-ui::page.container>
</div>
