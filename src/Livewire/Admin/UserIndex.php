<?php

namespace Nawasara\DatabaseMonitor\Livewire\Admin;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Nawasara\AuthPrimitives\Attributes\RequiresSudo;
use Nawasara\AuthPrimitives\Traits\WithSudo;
use Nawasara\DatabaseMonitor\Services\MysqlAdmin;

/**
 * Fase F admin — MySQL user CRUD + GRANT/REVOKE.
 *
 * Mirror Spatie permission `database-monitor.user.manage` is enough at the
 * route layer; per-action sudo gating defends against post-auth session
 * theft. All destructive actions emit Spatie activity log entries.
 */
class UserIndex extends Component
{
    use WithSudo;

    // -- Create user form --
    public string $createUser = '';

    public string $createHost = '%';

    public string $createPassword = '';

    // -- Grant form --
    public string $grantUser = '';

    public string $grantHost = '%';

    public string $grantDatabase = '';

    /** @var list<string> */
    public array $grantPrivileges = ['SELECT'];

    // -- Drop confirm --
    public ?string $dropTargetUser = null;

    public ?string $dropTargetHost = null;

    public string $dropConfirmUser = '';

    public function mount(): void
    {
        if (! app(MysqlAdmin::class)->isEnabled()) {
            abort(404);
        }
    }

    #[Computed]
    public function adminConfigured(): bool
    {
        return app(MysqlAdmin::class)->isConfigured();
    }

    #[Computed]
    public function users(): array
    {
        if (! $this->adminConfigured) {
            return [];
        }

        $admin = app(MysqlAdmin::class);

        try {
            return $admin->listUsers();
        } catch (\Throwable $e) {
            return [];
        } finally {
            $admin->purge();
        }
    }

    public function grantsFor(string $user, string $host): array
    {
        // Defense-in-depth — even though the route requires user.manage,
        // pin this public Livewire method to the same gate so a future
        // relaxation of route middleware can't leak the grant table.
        if (! auth()->user()?->can('database-monitor.user.manage')) {
            return [];
        }

        if (! $this->adminConfigured) {
            return [];
        }

        $admin = app(MysqlAdmin::class);

        try {
            return $admin->listGrants($user, $host);
        } catch (\Throwable $e) {
            return [];
        } finally {
            $admin->purge();
        }
    }

    #[RequiresSudo(reason: 'membuat user MySQL')]
    public function createMysqlUser(): void
    {
        $this->authorize('database-monitor.user.manage');

        $this->validate([
            'createUser' => 'required|string|min:1|max:32|regex:/^[A-Za-z0-9_]+$/',
            'createHost' => 'required|string|max:60|regex:/^[A-Za-z0-9_%.\-]+$/',
            'createPassword' => ['required', 'string', 'min:16', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/'],
        ], [
            'createUser.regex' => 'Username hanya boleh huruf, angka, underscore.',
            'createHost.regex' => 'Host pattern hanya boleh huruf, angka, underscore, %, titik, dash.',
            'createPassword.min' => 'Password minimal 16 karakter.',
            'createPassword.regex' => 'Password harus mengandung huruf besar, kecil, dan angka.',
        ]);

        $admin = app(MysqlAdmin::class);

        try {
            $admin->createUser($this->createUser, $this->createPassword, $this->createHost);
            activity('database-monitor')
                ->event('user.create')
                ->withProperties(['user' => $this->createUser, 'host' => $this->createHost])
                ->log("MySQL user '{$this->createUser}'@'{$this->createHost}' created by ".auth()->user()?->email);

            $this->dispatch('toast', ['type' => 'success', 'message' => "User '{$this->createUser}'@'{$this->createHost}' dibuat."]);
            $this->reset(['createUser', 'createPassword']);
            $this->createHost = '%';
            $this->dispatch('modal-close:db-monitor-user-create');
        } catch (\Throwable $e) {
            // PDO::quote() embeds the password inside the SQL string, so the
            // exception message can carry the password if MySQL echoes the
            // statement. Never bubble that to UI/audit fields. report() to
            // logs gets the full exception for debugging.
            report($e);
            activity('database-monitor')
                ->event('user.create_failed')
                ->withProperties(['user' => $this->createUser, 'host' => $this->createHost])
                ->log("MySQL user '{$this->createUser}'@'{$this->createHost}' create FAILED by ".auth()->user()?->email);
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Gagal membuat user (cek log aplikasi).']);
        } finally {
            $admin->purge();
        }
    }

    #[RequiresSudo(reason: 'memberikan privilege')]
    public function grant(): void
    {
        $this->authorize('database-monitor.user.manage');

        $this->validate([
            'grantUser' => 'required|string|regex:/^[A-Za-z0-9_]+$/',
            'grantHost' => 'required|string',
            'grantDatabase' => 'required|string|regex:/^[A-Za-z0-9_]+$/',
            'grantPrivileges' => 'required|array|min:1',
        ]);

        $admin = app(MysqlAdmin::class);

        try {
            $admin->grant($this->grantUser, $this->grantDatabase, $this->grantPrivileges, $this->grantHost);
            activity('database-monitor')
                ->event('user.grant')
                ->withProperties([
                    'user' => $this->grantUser,
                    'host' => $this->grantHost,
                    'database' => $this->grantDatabase,
                    'privileges' => $this->grantPrivileges,
                ])
                ->log("GRANT ".implode(',', $this->grantPrivileges)." ON {$this->grantDatabase}.* TO '{$this->grantUser}'@'{$this->grantHost}' by ".auth()->user()?->email);

            $this->dispatch('toast', ['type' => 'success', 'message' => "Privilege diberikan ke '{$this->grantUser}'."]);
            $this->reset(['grantUser', 'grantDatabase']);
            $this->grantHost = '%';
            $this->grantPrivileges = ['SELECT'];
            $this->dispatch('modal-close:db-monitor-grant');
        } catch (\Throwable $e) {
            report($e);
            activity('database-monitor')
                ->event('user.grant_failed')
                ->withProperties([
                    'user' => $this->grantUser,
                    'host' => $this->grantHost,
                    'database' => $this->grantDatabase,
                    'privileges' => $this->grantPrivileges,
                ])
                ->log("GRANT to '{$this->grantUser}'@'{$this->grantHost}' on '{$this->grantDatabase}' FAILED by ".auth()->user()?->email);
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Gagal memberikan privilege (cek log aplikasi).']);
        } finally {
            $admin->purge();
        }
    }

    public function openDrop(string $user, string $host): void
    {
        $this->dropTargetUser = $user;
        $this->dropTargetHost = $host;
        $this->dropConfirmUser = '';
        $this->dispatch('modal-open:db-monitor-user-drop');
    }

    public function closeDrop(): void
    {
        $this->dispatch('modal-close:db-monitor-user-drop');
        $this->dropTargetUser = null;
        $this->dropTargetHost = null;
        $this->dropConfirmUser = '';
    }

    #[RequiresSudo(reason: 'menghapus user MySQL')]
    public function confirmDrop(): void
    {
        $this->authorize('database-monitor.user.manage');

        if (! $this->dropTargetUser || ! $this->dropTargetHost) {
            return;
        }

        if ($this->dropConfirmUser !== $this->dropTargetUser) {
            $this->addError('dropConfirmUser', 'Ketik ulang username sama persis untuk konfirmasi.');
            return;
        }

        $admin = app(MysqlAdmin::class);

        try {
            $admin->dropUser($this->dropTargetUser, $this->dropTargetHost);
            activity('database-monitor')
                ->event('user.drop')
                ->withProperties(['user' => $this->dropTargetUser, 'host' => $this->dropTargetHost])
                ->log("MySQL user '{$this->dropTargetUser}'@'{$this->dropTargetHost}' DROPPED by ".auth()->user()?->email);

            $this->dispatch('toast', ['type' => 'success', 'message' => "User '{$this->dropTargetUser}' dihapus."]);
            $this->closeDrop();
        } catch (\Throwable $e) {
            report($e);
            activity('database-monitor')
                ->event('user.drop_failed')
                ->withProperties(['user' => $this->dropTargetUser, 'host' => $this->dropTargetHost])
                ->log("MySQL user '{$this->dropTargetUser}'@'{$this->dropTargetHost}' drop FAILED by ".auth()->user()?->email);
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Gagal menghapus user (cek log aplikasi).']);
        } finally {
            $admin->purge();
        }
    }

    public function render()
    {
        return view('nawasara-database-monitor::livewire.pages.admin.user-index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
