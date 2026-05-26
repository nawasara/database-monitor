<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[
                ['label' => 'Database', 'url' => url('nawasara-database-monitor/dashboard')],
                ['label' => 'Administration'],
                ['label' => 'Users'],
            ]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page-header
            title="Administration — MySQL Users"
            description="Kelola user MySQL + GRANT/REVOKE privilege. Aksi gated oleh Sudo Mode."
            :count="count($this->users).' users'">
            @can('database-monitor.user.manage')
                <x-nawasara-ui::button color="info"
                    x-on:click="$dispatch('open-modal', 'db-monitor-grant')">
                    <x-slot:icon><x-lucide-key class="size-4" /></x-slot:icon>
                    GRANT
                </x-nawasara-ui::button>
                <x-nawasara-ui::button color="primary"
                    x-on:click="$dispatch('open-modal', 'db-monitor-user-create')">
                    <x-slot:icon><x-lucide-user-plus class="size-4" /></x-slot:icon>
                    Tambah User
                </x-nawasara-ui::button>
            @endcan
        </x-nawasara-ui::page-header>

        @if (! $this->adminConfigured)
            <x-nawasara-ui::empty-state
                icon="lucide-shield-alert"
                title="Admin credential belum di-set"
                description="Isi admin_username + admin_password di group Vault 'database-monitor'.">
                <x-nawasara-ui::button color="primary" :href="url('nawasara-vault')" wire:navigate>
                    Buka Vault
                </x-nawasara-ui::button>
            </x-nawasara-ui::empty-state>
        @else
            <x-nawasara-ui::table stickyLast :headers="['User', 'Host', 'Grants', '']">
                <x-slot:table>
                    @forelse ($this->users as $u)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-700/40">
                            <td class="px-4 py-2.5 text-sm text-neutral-800 dark:text-neutral-100 font-medium">
                                {{ $u['user'] }}
                            </td>
                            <td class="px-4 py-2.5 text-sm text-neutral-600 dark:text-neutral-300 font-mono">
                                {{ $u['host'] }}
                            </td>
                            <td class="px-4 py-2.5 text-xs text-neutral-500 dark:text-neutral-400">
                                @php $grants = $this->grantsFor($u['user'], $u['host']); @endphp
                                @if (empty($grants))
                                    <span class="text-neutral-400">—</span>
                                @else
                                    <details>
                                        <summary class="cursor-pointer hover:text-neutral-700 dark:hover:text-neutral-200">
                                            {{ count($grants) }} grant(s)
                                        </summary>
                                        <ul class="mt-1 ml-3 font-mono text-[10px] space-y-0.5">
                                            @foreach ($grants as $g)
                                                <li class="truncate" title="{{ $g }}">{{ $g }}</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                @can('database-monitor.user.manage')
                                    @if (! in_array($u['user'], ['root', 'mysql.sys', 'mysql.session', 'mysql.infoschema']))
                                        <x-nawasara-ui::icon-button
                                            icon="trash-2"
                                            tooltip="Hapus user"
                                            wire:click="openDrop('{{ $u['user'] }}', '{{ $u['host'] }}')" />
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6">
                                <x-nawasara-ui::empty-state
                                    inline
                                    icon="lucide-user-x"
                                    title="Tidak ada user MySQL"
                                    description="Mungkin admin credential salah atau koneksi gagal." />
                            </td>
                        </tr>
                    @endforelse
                </x-slot:table>
            </x-nawasara-ui::table>

            {{-- Create user modal --}}
            <x-nawasara-ui::modal id="db-monitor-user-create" title="Buat user MySQL" maxWidth="md">
                <form wire:submit="createMysqlUser" class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">Username</label>
                        <input type="text" wire:model="createUser" placeholder="contoh: aplikasi_x"
                            class="w-full rounded-md border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm" />
                        @error('createUser') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">Host pattern</label>
                        <input type="text" wire:model="createHost"
                            class="w-full rounded-md border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm" />
                        <p class="text-xs text-neutral-500 mt-1"><code>%</code> = bisa dari mana saja. Lebih aman pakai IP spesifik.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">Password</label>
                        <input type="password" wire:model="createPassword"
                            class="w-full rounded-md border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm" />
                        @error('createPassword') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </form>

                <x-slot:footer>
                    <x-nawasara-ui::button color="neutral" x-on:click="$dispatch('close-modal', 'db-monitor-user-create')">Batal</x-nawasara-ui::button>
                    <x-nawasara-ui::button color="primary" wire:click="createMysqlUser">Buat user</x-nawasara-ui::button>
                </x-slot:footer>
            </x-nawasara-ui::modal>

            {{-- GRANT modal --}}
            <x-nawasara-ui::modal id="db-monitor-grant" title="GRANT privilege" maxWidth="md">
                <form wire:submit="grant" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">User</label>
                            <input type="text" wire:model="grantUser"
                                class="w-full rounded-md border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm" />
                            @error('grantUser') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">Host</label>
                            <input type="text" wire:model="grantHost"
                                class="w-full rounded-md border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">Database</label>
                        <input type="text" wire:model="grantDatabase"
                            class="w-full rounded-md border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm" />
                        @error('grantDatabase') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">Privileges</label>
                        <div class="grid grid-cols-3 gap-1.5 text-xs">
                            @foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX'] as $priv)
                                <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                    <input type="checkbox" wire:model="grantPrivileges" value="{{ $priv }}"
                                        class="rounded border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800" />
                                    {{ $priv }}
                                </label>
                            @endforeach
                        </div>
                        @error('grantPrivileges') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </form>

                <x-slot:footer>
                    <x-nawasara-ui::button color="neutral" x-on:click="$dispatch('close-modal', 'db-monitor-grant')">Batal</x-nawasara-ui::button>
                    <x-nawasara-ui::button color="info" wire:click="grant">Apply GRANT</x-nawasara-ui::button>
                </x-slot:footer>
            </x-nawasara-ui::modal>

            {{-- DROP user modal --}}
            <x-nawasara-ui::modal id="db-monitor-user-drop"
                :title="'Hapus user — '.($dropTargetUser ?? '').'@'.($dropTargetHost ?? '')"
                maxWidth="md">
                @if ($dropTargetUser)
                    <div class="space-y-3">
                        <div class="rounded-md bg-rose-50 border border-rose-200 dark:bg-rose-900/20 dark:border-rose-800 px-3 py-2">
                            <p class="text-sm text-rose-700 dark:text-rose-300 font-medium">
                                Aksi ini menghapus user dan SEMUA grant-nya.
                            </p>
                            <p class="text-xs text-rose-600 dark:text-rose-400 mt-1">
                                Aplikasi yang masih konek pakai user ini akan langsung gagal auth.
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">
                                Ketik ulang username <code>{{ $dropTargetUser }}</code>:
                            </label>
                            <input type="text" wire:model="dropConfirmUser"
                                class="w-full rounded-md border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm font-mono" />
                            @error('dropConfirmUser') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif

                <x-slot:footer>
                    <x-nawasara-ui::button color="neutral" wire:click="closeDrop">Batal</x-nawasara-ui::button>
                    <x-nawasara-ui::button color="danger" wire:click="confirmDrop">Hapus user</x-nawasara-ui::button>
                </x-slot:footer>
            </x-nawasara-ui::modal>
        @endif
    </x-nawasara-ui::page.container>
</div>
